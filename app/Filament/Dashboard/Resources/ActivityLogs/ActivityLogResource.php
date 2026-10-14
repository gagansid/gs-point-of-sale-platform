<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\ActivityLogs;

use App\Enums\PaymentCategory;
use App\Enums\UserRole;
use App\Filament\Dashboard\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Pengaturan → Riwayat perubahan (Q58, R7, audit.view): siapa mengubah apa, nilai lama → baru.
 * Hanya baca. Jenis data mengikuti izin pengelolanya (manager: katalog saja); outlet dibatasi ke outlet
 * yang boleh diakses (ADR 0010). Isolasi tenant lewat TenantScope di ActivityLog.
 */
final class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $slug = 'settings/activity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Riwayat perubahan';

    protected static ?string $modelLabel = 'riwayat perubahan';

    protected static ?string $pluralModelLabel = 'riwayat perubahan';

    protected static ?int $navigationSort = 9;

    protected static bool $isGloballySearchable = false;

    /** Jenis data → [label, permission pengelola] */
    private const SUBJECTS = [
        Product::class => ['Produk', 'product.manage'],
        Category::class => ['Kategori', 'product.manage'],
        OptionGroup::class => ['Grup opsi', 'product.manage'],
        Option::class => ['Opsi', 'product.manage'],
        PaymentMethod::class => ['Metode bayar', 'payment_method.manage'],
        Outlet::class => ['Outlet', 'outlet.settings'],
        User::class => ['Karyawan', 'user.manage'],
    ];

    private const FIELDS = [
        'name' => 'Nama', 'sku' => 'SKU', 'barcode' => 'Barcode', 'price' => 'Harga', 'cost_price' => 'Harga modal',
        'category_id' => 'Kategori', 'track_stock' => 'Lacak stok', 'is_active' => 'Aktif', 'is_favorite' => 'Favorit',
        'min_select' => 'Min. pilih', 'max_select' => 'Maks. pilih', 'price_delta' => 'Tambahan harga',
        'category' => 'Jenis', 'requires_reference' => 'Wajib referensi', 'address' => 'Alamat', 'timezone' => 'Zona waktu',
        'tax_rate' => 'Pajak', 'service_charge_rate' => 'Service', 'tax_inclusive' => 'Harga termasuk pajak', 'rounding' => 'Pembulatan',
        'receipt_header' => 'Header struk', 'receipt_footer' => 'Footer struk', 'receipt_show_rounding' => 'Pembulatan di struk',
        'discount_limits' => 'Batas diskon', 'daily_sales_target' => 'Target harian', 'monthly_sales_target' => 'Target bulanan',
        'cash_float' => 'Modal laci tetap', 'auto_lock_minutes' => 'Kunci layar otomatis (menit)', 'cash_out_approval_limit' => 'Batas kas keluar tanpa PIN',
        'email' => 'Email', 'username' => 'Username', 'role' => 'Role', 'web_cashier_access' => 'Akses kasir web',
    ];

    private const MONEY_FIELDS = ['price', 'cost_price', 'price_delta', 'daily_sales_target', 'monthly_sales_target', 'cash_float', 'cash_out_approval_limit'];

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => self::visibleTo($query)->with('causer'))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y, H.i')->sortable(),
                TextColumn::make('causer_name')->label('Oleh')
                    ->state(fn (ActivityLog $record): string => match (true) {
                        $record->causer instanceof Admin => 'Tim gs.POS',
                        $record->causer instanceof User => $record->causer->name,
                        default => 'Sistem',
                    }),
                TextColumn::make('subject_label')->label('Data')
                    ->state(fn (ActivityLog $record): string => self::SUBJECTS[$record->subject_type][0] ?? 'Data')
                    ->description(fn (ActivityLog $record): string => self::subjectName($record))
                    ->weight('medium'),
                TextColumn::make('event')->label('Aksi')->badge()
                    ->state(fn (ActivityLog $record): string => match (true) {
                        $record->description === 'credentials' => 'Kredensial',
                        $record->event === 'created' => 'Dibuat',
                        $record->event === 'deleted' => 'Dihapus',
                        default => 'Diubah',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Dibuat' => 'success',
                        'Dihapus' => 'danger',
                        'Kredensial' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('changes_summary')->label('Perubahan')->wrap()
                    ->state(fn (ActivityLog $record): string => self::summary($record))
                    // Kotak "Cari" tabel: dicari di server (isi perubahan, nama pelaku, jenis data)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),
            ])
            ->filters([
                DateRangeFilter::make('created_at', defaultToday: false),
                SelectFilter::make('subject_type')->label('Jenis data')
                    ->options(fn (): array => collect(self::SUBJECTS)
                        ->filter(fn (array $s): bool => self::user()->hasPermission($s[1]))
                        ->map(fn (array $s): string => $s[0])->all()),
                SelectFilter::make('causer_id')->label('Oleh')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->persistFiltersInSession();

        return TableEmptyState::apply($table, Heroicon::OutlinedClipboardDocumentList, 'riwayat perubahan',
            'Perubahan harga, produk, setelan outlet, dan karyawan tercatat otomatis di sini');
    }

    /**
     * Batasi ke jenis data yang boleh dikelola user, dan outlet yang boleh diakses.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public static function visibleTo(Builder $query): Builder
    {
        $user = self::user();
        $types = array_keys(array_filter(self::SUBJECTS, fn (array $s): bool => $user->hasPermission($s[1])));

        return $query
            ->whereIn('subject_type', $types)
            ->when(! $user->hasPermission('outlet.access_all'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('subject_type', '!=', Outlet::class)
                ->orWhereIn('subject_id', $user->outletIds())));
    }

    /**
     * Cari teks di nama/nilai yang berubah (JSON properties), nama pelaku, dan label jenis data.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public static function search(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $types = array_keys(array_filter(self::SUBJECTS, fn (array $s): bool => str_contains(mb_strtolower($s[0]), mb_strtolower(trim($search)))));

        return $query->where(function (Builder $q) use ($term, $types): void {
            $q->whereRaw('LOWER(CAST(properties AS CHAR)) LIKE ?', [$term])
                ->orWhereIn('causer_id', User::query()->whereRaw('LOWER(name) LIKE ?', [$term])->select('id'))
                ->when($types !== [], fn (Builder $w) => $w->orWhereIn('subject_type', $types));

            // Nama data saat ini (log perubahan hanya menyimpan kolom yang berubah); tetap dalam tenant aktif
            foreach (array_keys(self::SUBJECTS) as $type) {
                $q->orWhere(fn (Builder $w) => $w->where('subject_type', $type)
                    ->whereIn('subject_id', $type::query()->withoutGlobalScope(SoftDeletingScope::class)
                        ->whereRaw('LOWER(name) LIKE ?', [$term])->select('id')));
            }
        });
    }

    private static function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }

    private static function subjectName(ActivityLog $record): string
    {
        $properties = $record->properties;
        $name = $properties['attributes']['name'] ?? $properties['old']['name'] ?? null;

        if (! is_string($name) && $record->subject_type !== null && $record->subject_id !== null) {
            /** @var class-string<Model> $type */
            $type = $record->subject_type;
            $name = $type::query()->withoutGlobalScopes()->whereKey($record->subject_id)->value('name');
        }

        return is_string($name) ? $name : '—';
    }

    /** "Harga: Rp24.000 → Rp26.000 · Aktif: Ya → Tidak" */
    public static function summary(ActivityLog $record): string
    {
        $properties = $record->properties;

        if ($record->description === 'credentials') {
            $secrets = (array) ($properties['secrets'] ?? []);

            return implode(' & ', array_map(fn (string $s): string => $s === 'pin' ? 'PIN' : 'Kata sandi', $secrets)).' diganti';
        }

        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);

        $parts = [];
        foreach ($record->event === 'deleted' ? $old : $new as $field => $value) {
            $label = self::FIELDS[$field] ?? $field;
            $parts[] = match ($record->event) {
                'created' => $label.': '.self::format($field, $value),
                'deleted' => $label.': '.self::format($field, $value),
                default => $label.': '.self::format($field, $old[$field] ?? null).' → '.self::format($field, $value),
            };
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    private static function format(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            in_array($field, self::MONEY_FIELDS, true) => Money::format(Money::of((string) $value)),
            in_array($field, ['tax_rate', 'service_charge_rate'], true) => rtrim(rtrim((string) $value, '0'), '.').'%',
            $field === 'category_id' => (string) (Category::query()->whereKey($value)->value('name') ?? '—'),
            $field === 'role' => UserRole::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            $field === 'category' => PaymentCategory::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            $field === 'discount_limits' && is_array($value) => 'Kasir '.($value['cashier'] ?? 0).'%, Supervisor '.($value['supervisor'] ?? 0).'%',
            is_array($value) => (string) json_encode($value),
            default => mb_strimwidth((string) $value, 0, 60, '…'),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
