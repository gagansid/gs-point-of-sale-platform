<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Employees;

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SetEmployeeActive;
use App\Actions\User\UnlockEmployeePin;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\User;
use App\Rules\SecurePin;
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
            TextInput::make('email')->label('Email')->email()->maxLength(150)
                ->unique(User::class, 'email', ignoreRecord: true)
                ->visible($usesPassword)->required($usesPassword)
                ->helperText('Untuk login dashboard & aplikasi'),
            TextInput::make('password')->label('Kata sandi')->password()->revealable()
                ->rule(Password::defaults())->maxLength(255)
                ->visible($usesPassword)
                ->required(fn (Get $get, string $operation): bool => $usesPassword($get) && ($operation === 'create' || ! $get('has_password')))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): string => $operation === 'edit' ? 'Kosongkan bila tidak diganti. Mengganti akan mengeluarkan semua sesi' : 'Minimal 8 karakter, huruf & angka'),
            TextInput::make('pin')->label('PIN')->password()->revealable()
                ->rule(new SecurePin)->length(6)->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off'])
                ->required(fn (Get $get, string $operation): bool => ! $usesPassword($get) && ($operation === 'create' || ! $get('has_pin')))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (Get $get, string $operation): string => match (true) {
                    $operation === 'edit' => 'Kosongkan bila tidak diganti. 6 digit, bukan angka sama/berurutan',
                    $usesPassword($get) => 'Opsional: untuk approval void/diskon. 6 digit',
                    default => 'Untuk login di tablet. 6 digit, bukan angka sama/berurutan',
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')
                    ->description(fn (User $record): ?string => $record->email)
                    ->searchable(['name', 'email'])->sortable(),
                TextColumn::make('role')->label('Role')->badge()->sortable(),
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
                        fn (): User => app(SaveEmployee::class)->handle($record, EmployeeData::fromArray([...$data, 'email' => self::emailFor($data)]))['user'],
                        $action,
                    )),
                ActionGroup::make([
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
     * Email hanya disimpan untuk role login email (kasir boleh tanpa email).
     *
     * @param  array<string, mixed>  $data
     */
    public static function emailFor(array $data): ?string
    {
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::tryFrom((string) ($data['role'] ?? ''));

        return $role?->canUsePasswordLogin() ? ($data['email'] ?? null) : null;
    }

    private static function role(Get $get): ?UserRole
    {
        $role = $get('role');

        return $role instanceof UserRole ? $role : UserRole::tryFrom((string) $role);
    }
}
