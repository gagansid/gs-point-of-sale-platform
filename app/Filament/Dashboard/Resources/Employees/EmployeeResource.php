<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Employees;

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SendEmployeeVerification;
use App\Actions\User\SetEmployeeActive;
use App\Actions\User\UnlockEmployeePin;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Forms\OutletPickList;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\User;
use App\Rules\SecurePin;
use App\Support\CurrentOutlet;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Pengaturan → Karyawan (SPEC, permission user.manage). Simpan lewat SaveEmployee/SetEmployeeActive.
 * Karyawan tidak dihapus — dinonaktifkan agar riwayat transaksi tetap utuh.
 */
final class EmployeeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'settings/employees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Karyawan';

    protected static ?string $modelLabel = 'karyawan';

    protected static ?string $pluralModelLabel = 'karyawan';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        $usesPassword = fn (Get $get): bool => self::role($get)?->canUsePasswordLogin() ?? false;

        return $schema->columns(2)->components([
            // Status kredensial saat mengubah: PIN/kata sandi lama tetap dipakai bila field dikosongkan
            Hidden::make('has_pin')->dehydrated(false),
            Hidden::make('has_password')->dehydrated(false),
            TextInput::make('name')->label('Nama')->required()->maxLength(100)->autofocus()
                ->helperText('Tampil di layar pilih kasir & struk'),
            Select::make('role')->label('Role')->options(UserRole::class)->required()->native(false)->live()
                ->default(UserRole::Cashier->value)
                ->helperText(fn (Get $get): string => match (self::role($get)) {
                    UserRole::Owner => 'Semua akses, termasuk karyawan & setelan',
                    UserRole::Manager => 'Kelola produk, stok, laporan; login dashboard',
                    UserRole::Supervisor => 'Kasir + approval void/diskon dengan PIN',
                    default => 'Transaksi di tablet dengan PIN',
                }),
            TextInput::make('email')->label('Email')->email()->maxLength(150)->required()
                ->unique(User::class, 'email', ignoreRecord: true)
                ->placeholder('budi@contoh.com')
                ->helperText(fn (Get $get): string => $usesPassword($get)
                    ? 'Untuk login dashboard & aplikasi. Link verifikasi dikirim saat disimpan'
                    : 'Link verifikasi dikirim ke email ini saat disimpan'),
            TextInput::make('username')->label('Username')->maxLength(30)->minLength(3)
                // Huruf besar diterima lalu disimpan huruf kecil (sama seperti API)
                ->regex('/^[A-Za-z0-9._-]+$/')
                ->validationMessages(['regex' => 'Username hanya huruf, angka, titik, garis bawah, atau tanda hubung.'])
                ->rule(fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $taken = User::query()
                        ->where('username', mb_strtolower(trim((string) $value)))
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($taken) {
                        $fail('Username sudah dipakai karyawan lain.');
                    }
                })
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null)
                ->visible(fn (Get $get): bool => ! $usesPassword($get))->required(fn (Get $get): bool => ! $usesPassword($get))
                ->placeholder('budi')
                ->helperText('Untuk login kasir web (cadangan bila tablet rusak)'),
            TextInput::make('password')->label('Kata sandi')->password()->revealable()
                ->rule(Password::defaults())->maxLength(255)
                ->required(fn (string $operation, Get $get): bool => $operation === 'create' || ! $get('has_password'))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation, Get $get): string => match (true) {
                    $operation === 'edit' && (bool) $get('has_password') => 'Kosongkan bila tidak diganti. Mengganti akan mengeluarkan semua sesi',
                    $usesPassword($get) => 'Untuk login dashboard & aplikasi. Minimal 8 karakter, huruf & angka',
                    default => 'Untuk login kasir web. Minimal 8 karakter, huruf & angka',
                }),
            TextInput::make('pin')->label('PIN')->password()->revealable()
                ->rule(new SecurePin)->length(6)->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off'])
                ->required(fn (Get $get, string $operation): bool => ! $usesPassword($get) && ($operation === 'create' || ! $get('has_pin')))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (Get $get, string $operation): string => match (true) {
                    $operation === 'edit' => 'Kosongkan bila tidak diganti. 6 digit, bukan angka sama/berurutan',
                    $usesPassword($get) => 'Opsional: untuk approval void/diskon. 6 digit',
                    default => 'Untuk login di tablet. 6 digit, bukan angka sama/berurutan',
                }),
            // Outlet yang dipegang (ADR 0010). Owner otomatis semua outlet; disembunyikan bila hanya satu outlet
            OutletPickList::make('outlet_ids')
                ->countedLabel('Outlet')
                ->outletQuery(fn () => self::assignableOutletQuery())
                ->formatStateUsing(fn (?User $record, mixed $state): array => $record !== null
                    ? $record->outlets()->pluck('outlets.id')->all()
                    : (is_array($state) && $state !== [] ? $state : array_filter([CurrentOutlet::get()?->id])))
                ->required()
                ->columnSpanFull()
                ->helperText('Karyawan hanya bisa login & bertransaksi di perangkat outlet yang dipilih')
                ->visible(fn (Get $get): bool => count(self::assignableOutlets()) > 1
                    && ! (self::role($get)?->allows('outlet.access_all') ?? false)),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')
                    ->description(fn (User $record): ?string => $record->email ?? ($record->username !== null ? '@'.$record->username : null))
                    ->searchable(['name', 'email', 'username'])->sortable(),
                TextColumn::make('role')->label('Role')->badge()->sortable(),
                TextColumn::make('outlets.name')->label('Outlet')->badge()->color('gray')
                    ->placeholder('Semua outlet')
                    ->visible(fn (): bool => Outlet::query()->count() > 1),
                // Verifikasi email karyawan (SPEC Q46)
                TextColumn::make('email_status')->label('Email')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->email === null => 'Belum diisi',
                        $record->hasVerifiedEmail() => 'Terverifikasi',
                        default => 'Belum verifikasi',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Terverifikasi' => 'success',
                        'Belum verifikasi' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (User $record): ?string => $record->email),
                TextColumn::make('pin_status')->label('PIN')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->isPinLocked() => 'Terkunci',
                        $record->pin !== null => 'Ada',
                        default => 'Belum',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Terkunci' => 'danger',
                        'Ada' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('last_login_at')->label('Login terakhir')->since()->dateTimeTooltip('j M Y, H.i')
                    ->placeholder('Belum pernah')->sortable()->toggleable(),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Role')->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')
                    ->mutateRecordDataUsing(fn (array $data, User $record): array => [
                        ...$data,
                        'has_pin' => $record->pin !== null,
                        'has_password' => $record->password !== null,
                    ])
                    ->using(fn (User $record, array $data, EditAction $action): User => self::orNotify(
                        fn (): User => app(SaveEmployee::class)->handle($record, EmployeeData::fromArray([...$data, ...self::credentialsFor($data)]), self::actor())['user'],
                        $action,
                    )),
                ActionGroup::make([
                    Action::make('resendVerification')->label('Kirim ulang verifikasi')->icon(Heroicon::OutlinedEnvelope)
                        ->visible(fn (User $record): bool => $record->email !== null && ! $record->hasVerifiedEmail()
                            && (auth()->user()?->can('user.manage') ?? false))
                        ->action(function (User $record): void {
                            try {
                                app(SendEmployeeVerification::class)->handle($record);
                                Notification::make()->success()->title('Link verifikasi dikirim')->body('Ke '.$record->email)->send();
                            } catch (BusinessException $e) {
                                Notification::make()->danger()->title($e->getMessage())->send();
                            }
                        }),
                    Action::make('unlockPin')->label('Buka kunci PIN')->icon(Heroicon::OutlinedLockOpen)
                        ->visible(fn (User $record): bool => $record->isPinLocked() && (auth()->user()?->can('user.manage') ?? false))
                        ->action(function (User $record): void {
                            app(UnlockEmployeePin::class)->handle($record);
                            Notification::make()->success()->title('Kunci PIN dibuka')->send();
                        }),
                    ...ActiveStatusActions::make(
                        User::class,
                        function (User $employee, bool $active): void {
                            $by = auth()->user();
                            abort_unless($by instanceof User, 403);
                            app(SetEmployeeActive::class)->handle($employee, $active, $by);
                        },
                        'karyawan',
                        'Karyawan langsung keluar dari tablet & dashboard dan tidak bisa login sampai diaktifkan lagi.',
                        'user.manage',
                    ),
                ])->tooltip('Aksi lain'),
            ])
            ->defaultSort('name');

        return TableEmptyState::apply($table, Heroicon::OutlinedUsers, 'karyawan', 'Tambahkan kasir & supervisor untuk login di tablet');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployees::route('/')];
    }

    /**
     * Jalankan Action bisnis; BusinessException (mis. LAST_OWNER_REQUIRED) tampil sebagai notifikasi,
     * modal tetap terbuka.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function orNotify(Closure $callback, Action $action): mixed
    {
        try {
            return $callback();
        } catch (BusinessException $e) {
            $details = is_array($e->details) ? implode(' ', array_merge(...array_values(array_map(
                fn (mixed $messages): array => array_map('strval', (array) $messages),
                $e->details,
            )))) : null;

            Notification::make()->danger()->title($e->getMessage())->body($details)->send();

            // halt() melempar Halt: modal tetap terbuka
            $action->halt();

            throw $e;
        }
    }

    /**
     * Identitas login sesuai role: owner/manager memakai email, supervisor/kasir memakai username
     * (kasir web) — email tetap wajib untuk semua (SPEC Q46). Username dikosongkan untuk owner/manager
     * agar tidak tersimpan sisa sebelum role diganti.
     *
     * @param  array<string, mixed>  $data
     * @return array{email: string|null, username: string|null}
     */
    public static function credentialsFor(array $data): array
    {
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::tryFrom((string) ($data['role'] ?? ''));
        $usesEmail = $role?->canUsePasswordLogin() ?? false;

        return [
            // Email untuk semua role (SPEC Q46): login owner/manager, verifikasi karyawan
            'email' => $data['email'] ?? null,
            'username' => $usesEmail ? null : ($data['username'] ?? null),
        ];
    }

    /**
     * Outlet aktif yang boleh ditugaskan: hanya outlet yang juga dipegang pemberi tugas.
     *
     * @return array<string, string>
     */
    private static function assignableOutlets(): array
    {
        return self::assignableOutletQuery()->pluck('name', 'id')->all();
    }

    /** @return Builder<Outlet> */
    private static function assignableOutletQuery(): Builder
    {
        return self::actor()?->accessibleOutlets()->active() ?? Outlet::query()->whereRaw('1 = 0');
    }

    public static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function role(Get $get): ?UserRole
    {
        $role = $get('role');

        return $role instanceof UserRole ? $role : UserRole::tryFrom((string) $role);
    }
}
