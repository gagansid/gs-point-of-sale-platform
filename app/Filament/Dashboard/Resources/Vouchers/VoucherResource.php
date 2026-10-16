<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Vouchers;

use App\Actions\Member\SaveVoucher;
use App\Actions\Member\SetVoucherActive;
use App\Enums\DiscountType;
use App\Enums\VoucherSource;
use App\Filament\Dashboard\Resources\Vouchers\Pages\ManageVouchers;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\MemberSetting;
use App\Models\MemberTier;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Voucher;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pelanggan → Voucher (ADR 0013, Q66; izin member.settings): kode promo owner (tambah/ubah/nonaktif) dan daftar
 * voucher ulang tahun yang dibuat otomatis (hanya dilihat).
 */
final class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $slug = 'vouchers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Voucher';

    protected static ?string $modelLabel = 'voucher';

    protected static ?string $pluralModelLabel = 'voucher';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('member') && $user->role->hasFullDashboard() && $user->hasPermission('member.settings');
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->can('member.settings') ?? false) && MemberSetting::current()->enabled;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can('member.settings') ?? false) && $record instanceof Voucher && $record->source === VoucherSource::Owner;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = SaveVoucher::rules();
        $timezone = CurrentOutlet::timezone();
        $percent = fn (Get $get): bool => ($get('type') instanceof DiscountType ? $get('type')->value : $get('type')) === DiscountType::Percent->value;

        return $schema->columns(2)->components([
            TextInput::make('code')->label('Kode')->required()->maxLength(30)->placeholder('HEMAT10')->autofocus()
                ->helperText('Huruf, angka, tanda hubung; diketik kasir atau pelanggan')
                ->dehydrateStateUsing(fn (?string $state): string => Voucher::normalizeCode((string) $state)),
            TextInput::make('name')->label('Nama')->required()->rules($rules['name'])->placeholder('Promo akhir pekan'),
            ToggleButtons::make('type')->label('Jenis potongan')->required()->inline()->live()->default(DiscountType::Fixed->value)
                ->options([DiscountType::Fixed->value => 'Nominal', DiscountType::Percent->value => 'Persen']),
            TextInput::make('value')->label('Nilai')->numeric()->required()
                ->prefix(fn (Get $get): ?string => $percent($get) ? null : 'Rp')
                ->suffix(fn (Get $get): ?string => $percent($get) ? '%' : null),
            MoneyInput::make('max_discount')->label('Maks. potongan')->helperText('Kosong = tanpa batas')
                ->visible($percent),
            MoneyInput::make('min_spend')->label('Minimal belanja')->default(0)->helperText('Subtotal setelah diskon item'),
            TextInput::make('quota')->label('Kuota total')->integer()->rules($rules['quota'])->helperText('Kosong = tanpa batas'),
            TextInput::make('per_member_quota')->label('Kuota per member')->integer()->rules($rules['per_member_quota'])
                ->helperText('Diisi = otomatis khusus member'),
            DateTimePicker::make('starts_at')->label('Mulai')->seconds(false)->timezone($timezone)->native(false),
            DateTimePicker::make('ends_at')->label('Berakhir')->seconds(false)->timezone($timezone)->native(false)->after('starts_at'),
            Toggle::make('member_only')->label('Khusus member')->columnSpanFull(),
            Select::make('tier_ids')->label('Khusus tier')->multiple()->placeholder('Semua tier')
                ->options(fn (): array => MemberTier::ordered()->pluck('name', 'id')->all())
                ->visible(fn (): bool => MemberTier::query()->exists()),
            Select::make('outlet_ids')->label('Berlaku di outlet')->multiple()->placeholder('Semua outlet yang ikut program')
                ->options(fn (): array => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (): bool => Outlet::query()->count() > 1),
            // Tanggal dibuat & diubah (audit)
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $timezone = CurrentOutlet::timezone();

        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('member:id,name'))
            ->columns([
                TextColumn::make('code')->label('Kode')->weight('medium')->searchable()->sortable()
                    ->placeholder('—')
                    ->description(fn (Voucher $record): string => $record->member !== null ? $record->name.' · '.$record->member->name : $record->name),
                TextColumn::make('benefit')->label('Potongan')->state(fn (Voucher $record): string => $record->benefitLabel()),
                TextColumn::make('min_spend')->label('Min. belanja')->alignEnd()
                    ->formatStateUsing(fn (string $state): string => Money::isPositive($state) ? Money::format($state) : '—'),
                TextColumn::make('period')->label('Periode')
                    ->state(fn (Voucher $record): string => self::period($record, $timezone)),
                TextColumn::make('used_count')->label('Terpakai')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state, Voucher $record): string => $record->quota !== null ? "{$state} / {$record->quota}" : (string) $state),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Voucher $record): string => self::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Terjadwal' => 'info',
                        'Nonaktif' => 'warning',
                        default => 'gray',
                    }),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')->successNotificationTitle('Voucher diperbarui')
                    ->mutateRecordDataUsing(fn (Voucher $record, array $data): array => [...$data, 'type' => $record->type->value])
                    ->using(fn (Voucher $record, array $data, EditAction $action): Voucher => RunBusinessAction::run(
                        fn (): Voucher => app(SaveVoucher::class)->handle($record, $data),
                        $action,
                    )),
                ActionGroup::make(ActiveStatusActions::make(
                    Voucher::class,
                    fn (Voucher $voucher, bool $active) => app(SetVoucherActive::class)->handle($voucher, $active),
                    'voucher',
                    'Voucher tidak bisa dipakai sampai diaktifkan lagi. Riwayat pemakaian tetap ada.',
                    'member.settings',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedReceiptPercent, 'voucher',
            MemberSetting::current()->enabled
                ? 'Buat kode promo, mis. HEMAT10 untuk potongan 10%'
                : 'Aktifkan dulu di Pengaturan → Program member');
    }

    private static function period(Voucher $voucher, string $timezone): string
    {
        $format = fn ($date): string => $date->setTimezone($timezone)->locale('id')->translatedFormat('j M Y');

        return match (true) {
            $voucher->starts_at !== null && $voucher->ends_at !== null => $format($voucher->starts_at).' – '.$format($voucher->ends_at),
            $voucher->ends_at !== null => 's/d '.$format($voucher->ends_at),
            $voucher->starts_at !== null => 'Mulai '.$format($voucher->starts_at),
            default => 'Tanpa batas',
        };
    }

    private static function status(Voucher $voucher): string
    {
        return match (true) {
            ! $voucher->is_active => 'Nonaktif',
            $voucher->ends_at !== null && $voucher->ends_at->isPast() => 'Berakhir',
            $voucher->quota !== null && $voucher->used_count >= $voucher->quota => 'Habis',
            $voucher->starts_at !== null && $voucher->starts_at->isFuture() => 'Terjadwal',
            default => 'Aktif',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageVouchers::route('/')];
    }
}
