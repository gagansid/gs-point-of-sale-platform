<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Members;

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\AnonymizeMember;
use App\Actions\Member\ChangeMemberPassword;
use App\Actions\Member\Data\MemberData;
use App\Actions\Member\SaveMember;
use App\Actions\Member\SetMemberActive;
use App\Actions\Member\SetMemberPin;
use App\Filament\Dashboard\Resources\Members\Pages\ManageMembers;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Http\Requests\Api\V1\Member\MemberRequest;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\User;
use App\Support\Phone;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Pelanggan → Member (ADR 0013, member.view): daftar member bisnis (semua outlet). Tambah (member.create),
 * ubah/nonaktifkan/hapus = anonimkan (member.manage). Program diaktifkan di Pengaturan → Program member.
 */
final class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $slug = 'members';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Member';

    protected static ?string $modelLabel = 'member';

    protected static ?string $pluralModelLabel = 'member';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        // Supervisor masuk dashboard hanya untuk Laporan (Q50); member dicari/didaftar dari kasir
        return $user instanceof User && $user->role->hasFullDashboard() && $user->hasPermission('member.view');
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->can('member.create') ?? false) && MemberSetting::current()->enabled;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('member.manage') ?? false) && $record instanceof Member && ! $record->isAnonymized();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = MemberRequest::fieldRules();

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->rules($rules['name'])->autofocus(),
            TextInput::make('phone')->label('Nomor HP')->required()->tel()->rules($rules['phone'])
                ->placeholder('0812 3456 7890')->helperText('Unik per bisnis; dipakai untuk mencari member di kasir'),
            TextInput::make('email')->label('Email')->email()->rules($rules['email']),
            Grid::make(2)->schema([
                Select::make('birth_day')->label('Tanggal lahir')->placeholder('Tanggal')->options(array_combine(range(1, 31), array_map('strval', range(1, 31))))->native(false),
                Select::make('birth_month')->label('Bulan lahir')->placeholder('Bulan')->native(false)->requiredWith('birth_day')
                    ->options(array_combine(range(1, 12), ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'])),
            ]),
            Textarea::make('notes')->label('Catatan')->rows(2)->rules($rules['notes'])->columnSpanFull(),
            Checkbox::make('consent')->label('Pelanggan menyetujui datanya dipakai untuk program member')
                ->accepted()->columnSpanFull()
                ->visibleOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            // Nomor HP berspasi ("0812 3456 7890") dicari utuh, bukan per kata
            ->splitSearchTerms(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('joinedOutlet:id,name'))
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->sortable()
                    ->description(fn (Member $record): string => $record->code)
                    ->searchable(query: fn (Builder $query, string $search): Builder => Member::applySearch($query, $search)),
                TextColumn::make('phone')->label('Nomor HP')->formatStateUsing(fn (?string $state): string => Phone::display($state))
                    ->placeholder('—'),
                TextColumn::make('points_balance')->label('Poin')->numeric()->sortable()->alignEnd()
                    ->color(fn (int $state): ?string => $state < 0 ? 'danger' : null),
                TextColumn::make('visit_count')->label('Kunjungan')->numeric()->sortable()->alignEnd(),
                TextColumn::make('last_visit_at')->label('Terakhir datang')->since()->dateTimeTooltip('j M Y, H.i')
                    ->placeholder('Belum pernah')->sortable(),
                TextColumn::make('joinedOutlet.name')->label('Daftar di')->placeholder('Dashboard')->toggleable(),
                TextColumn::make('created_at')->label('Bergabung')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Member $record): string => match (true) {
                        $record->isAnonymized() => 'Dihapus',
                        $record->is_active => 'Aktif',
                        default => 'Nonaktif',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Nonaktif' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')->successNotificationTitle('Member diperbarui')
                    ->using(fn (Member $record, array $data, EditAction $action): Member => self::orNotify(
                        fn (): Member => app(SaveMember::class)->handle($record, MemberData::fromArray($data))['member'],
                        $action,
                    )),
                ActionGroup::make([
                    Action::make('points')->label('Riwayat poin')->icon(Heroicon::OutlinedClock)
                        ->modalHeading(fn (Member $record): string => 'Riwayat poin · '.$record->name)
                        ->modalContent(fn (Member $record) => view('filament.dashboard.members.points', [
                            'member' => $record,
                            'entries' => MemberPointEntry::query()->with('user:id,name')->where('member_id', $record->id)->latest()->limit(100)->get(),
                        ]))
                        ->modalSubmitAction(false)->modalCancelActionLabel('Tutup'),
                    Action::make('adjustPoints')->label('Sesuaikan poin')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->visible(fn (Member $record): bool => ! $record->isAnonymized() && (auth()->user()?->can('member.points_adjust') ?? false))
                        ->modalHeading(fn (Member $record): string => 'Sesuaikan poin · '.$record->name)
                        ->modalDescription(fn (Member $record): string => 'Saldo saat ini '.number_format($record->points_balance, 0, ',', '.').' poin')
                        ->schema([
                            TextInput::make('points')->label('Jumlah poin')->integer()->required()->helperText('Positif = tambah, negatif = kurangi'),
                            TextInput::make('reason')->label('Alasan')->required()->maxLength(255)->placeholder('mis. kompensasi pesanan salah'),
                        ])
                        ->action(function (Member $record, array $data, Action $action): void {
                            abort_unless(auth()->user()?->can('member.points_adjust') ?? false, 403);
                            $user = auth()->user();
                            abort_unless($user instanceof User, 403);
                            self::orNotify(fn () => app(AdjustMemberPoints::class)->handle($record, (int) $data['points'], (string) $data['reason'], $user), $action);
                            Notification::make()->success()->title('Poin disesuaikan')->send();
                        }),
                    Action::make('temporaryPassword')->label('Kata sandi sementara')->icon(Heroicon::OutlinedLockOpen)
                        ->visible(fn (Member $record): bool => self::canEdit($record))
                        ->requiresConfirmation()
                        ->modalHeading('Buat kata sandi sementara')
                        ->modalDescription(fn (Member $record): HtmlString => Layout::confirmText(
                            'Buat kata sandi sementara untuk <strong>'.e($record->name).'</strong>?',
                            'Untuk pelanggan yang lupa kata sandi halaman member. Kata sandi lama tidak berlaku; pelanggan wajib membuat kata sandi baru saat masuk.',
                        ))
                        ->modalSubmitActionLabel('Buat')
                        ->action(function (Member $record): void {
                            abort_unless(self::canEdit($record), 403);
                            $password = app(ChangeMemberPassword::class)->temporary($record);
                            Notification::make()->success()->persistent()
                                ->title('Kata sandi sementara: '.$password)
                                ->body('Berikan ke pelanggan sekarang; tidak ditampilkan lagi. Masuk di '.route('member.login', ['business' => $record->tenant->slug]))
                                ->send();
                        }),
                    Action::make('setPin')->label('Atur PIN member')->icon(Heroicon::OutlinedKey)
                        ->visible(fn (Member $record): bool => self::canEdit($record))
                        ->modalHeading(fn (Member $record): string => 'PIN member · '.$record->name)
                        ->modalDescription('Dipakai saat menukar poin bila pelanggan tidak bisa menunjukkan kode QR halaman member. Minta pelanggan sendiri yang mengetik PIN')
                        ->schema([
                            TextInput::make('pin')->label('PIN baru')->password()->revealable()->required()->length(4)
                                ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
                        ])
                        ->action(function (Member $record, array $data, Action $action): void {
                            abort_unless(self::canEdit($record), 403);
                            self::orNotify(fn () => app(SetMemberPin::class)->handle($record, (string) $data['pin']), $action);
                            Notification::make()->success()->title('PIN member disimpan')->send();
                        }),
                    ...ActiveStatusActions::make(Member::class, function (Member $member, bool $active): void {
                        app(SetMemberActive::class)->handle($member, $active);
                    }, 'member', 'Member tidak bisa dipasang ke transaksi baru sampai diaktifkan lagi. Riwayat tetap ada.', 'member.manage'),
                    Action::make('anonymize')->label('Hapus data')->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->visible(fn (Member $record): bool => self::canEdit($record))
                        ->requiresConfirmation()
                        ->modalHeading('Hapus data member')
                        ->modalDescription(fn (Member $record): HtmlString => Layout::confirmText(
                            'Hapus data pribadi <strong>'.e($record->name).'</strong>?',
                            'Nama, nomor HP, email, dan tanggal lahir dihapus permanen (permintaan pelanggan, UU PDP). Transaksi tetap ada untuk laporan.',
                        ))
                        ->modalSubmitActionLabel('Hapus data')
                        ->action(function (Member $record): void {
                            abort_unless(self::canEdit($record), 403);
                            app(AnonymizeMember::class)->handle($record);
                            Notification::make()->success()->title('Data member dihapus')->send();
                        }),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedUserGroup, 'member',
            MemberSetting::current()->enabled
                ? 'Daftarkan pelanggan dari kasir atau tombol Tambah'
                : 'Aktifkan dulu di Pengaturan → Program member');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function orNotify(Closure $callback, Action $action): mixed
    {
        return RunBusinessAction::run($callback, $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembers::route('/')];
    }
}
