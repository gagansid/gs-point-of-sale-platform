<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PaymentMethods;

use App\Actions\Payment\UpdatePaymentMethod;
use App\Enums\PaymentCategory;
use App\Filament\Dashboard\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Forms\OutletPickList;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pengaturan → Metode pembayaran (permission payment_method.manage). Lima metode bawaan per bisnis
 * (satu per kategori): tidak ada tambah/hapus. Tunai tidak bisa dinonaktifkan.
 */
final class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $slug = 'settings/payment-methods';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Metode pembayaran';

    protected static ?string $modelLabel = 'metode pembayaran';

    protected static ?string $pluralModelLabel = 'metode pembayaran';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('payment_method.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('payment_method.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(50)->autofocus()
                ->helperText('Tampil di tombol bayar kasir & struk, mis. "QRIS BCA"'),
            Toggle::make('requires_reference')->label('Wajib nomor referensi')
                ->helperText('Kasir wajib mengisi kode approval/nomor transaksi dari EDC atau QRIS'),
            // Per outlet (ADR 0011): hanya bila ada lebih dari satu outlet aktif; tunai selalu aktif
            OutletPickList::make('active_outlet_ids')->countedLabel('Aktif di outlet')
                ->outletQuery(fn () => Outlet::query()->active()->orderBy('created_at'))
                ->helperText('Mis. matikan Debit di outlet yang belum punya mesin EDC')
                ->visible(fn (?PaymentMethod $record): bool => $record?->category !== PaymentCategory::Cash && Outlet::query()->active()->count() > 1),
            // Tanggal dibuat & diubah (audit)
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action
                ->tooltip($isReordering ? 'Selesai mengatur urutan' : 'Atur urutan tampil di kasir'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('name'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable(),
                TextColumn::make('category')->label('Jenis')->badge()->color('gray')->sortable(),
                IconColumn::make('requires_reference')->label('Wajib referensi')->boolean()->alignCenter()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable(),
                TextColumn::make('outlets')->label('Outlet')
                    ->state(fn (PaymentMethod $record): string => self::outletSummary($record))
                    ->color(fn (string $state): ?string => $state === 'Semua outlet' ? null : 'warning')
                    ->tooltip(fn (PaymentMethod $record): string => 'Aktif di: '.(Outlet::query()->whereKey(self::activeOutletIds($record))
                        ->orderBy('created_at')->pluck('name')->implode(', ') ?: 'tidak ada outlet'))
                    ->visible(fn (): bool => Outlet::query()->active()->count() > 1),
                // Audit: tersembunyi bawaan, tampilkan lewat pilih kolom
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('md')
                    ->mutateRecordDataUsing(fn (PaymentMethod $record, array $data): array => [
                        ...$data,
                        'active_outlet_ids' => self::activeOutletIds($record),
                    ])
                    ->using(fn (PaymentMethod $record, array $data): PaymentMethod => app(UpdatePaymentMethod::class)->handle($record, array_filter([
                        'name' => (string) $data['name'],
                        'requires_reference' => (bool) $data['requires_reference'],
                        // Field tersembunyi (satu outlet / tunai) tidak dikirim → status outlet tidak diubah
                        'active_outlet_ids' => isset($data['active_outlet_ids']) ? array_values(array_map('strval', $data['active_outlet_ids'])) : null,
                    ], fn (mixed $value): bool => $value !== null))),
                ActionGroup::make(ActiveStatusActions::make(
                    PaymentMethod::class,
                    fn (PaymentMethod $method, bool $active) => app(UpdatePaymentMethod::class)->handle($method, ['is_active' => $active]),
                    'metode pembayaran',
                    'Metode ini tidak muncul di tombol bayar aplikasi kasir sampai diaktifkan lagi.',
                    'payment_method.manage',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedCreditCard, 'metode pembayaran', 'Metode bawaan dibuat otomatis untuk setiap bisnis');
    }

    /**
     * Outlet aktif tempat metode dipakai (tanpa baris nonaktif = aktif).
     *
     * @return list<string>
     */
    private static function activeOutletIds(PaymentMethod $method): array
    {
        $inactive = $method->outletSettings()->where('is_active', false)->pluck('outlet_id')->all();

        return Outlet::query()->active()->whereNotIn('id', $inactive)->pluck('id')->values()->all();
    }

    private static function outletSummary(PaymentMethod $method): string
    {
        $total = Outlet::query()->active()->count();
        $active = count(self::activeOutletIds($method));

        return $active === $total ? 'Semua outlet' : "{$active} dari {$total} outlet";
    }

    public static function getPages(): array
    {
        return ['index' => ManagePaymentMethods::route('/')];
    }
}
