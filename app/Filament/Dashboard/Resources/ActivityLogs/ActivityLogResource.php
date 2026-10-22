<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\ActivityLogs;

use App\Actions\Product\SetProductAvailability;
use App\Enums\PaymentCategory;
use App\Enums\UserRole;
use App\Filament\Dashboard\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
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
use App\Models\ProductStock;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
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
    use HasSentenceCaseLabels;

    protected static ?string $model = ActivityLog::class;

    protected static ?string $slug = 'settings/activity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Akun';

    protected static ?string $navigationLabel = 'Riwayat perubahan';

    protected static ?string $modelLabel = 'riwayat perubahan';

    protected static ?string $pluralModelLabel = 'riwayat perubahan';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    /** Jenis data → [label, permission pengelola] */
    private const SUBJECTS = [
        Product::class => ['Produk', 'product.manage'],
        // Menu habis/tersedia per outlet (Q115): kasir pun boleh menandai, owner perlu jejaknya
        ProductStock::class => ['Ketersediaan menu', 'product.toggle_available'],
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
        'cash_float' => 'Modal laci tetap', 'auto_lock_minutes' => 'Kunci layar otomatis (menit)', 'cash_out_approval_limit' => 'Batas kas keluar tanpa PIN', 'refund_max_days' => 'Batas retur (hari)',
        'email' => 'Email', 'username' => 'Username', 'role' => 'Role', 'web_cashier_access' => 'Akses kasir web',
        'is_available' => 'Tersedia',
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
                TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y')->sortable()
                    ->description(fn (ActivityLog $record): string => (string) $record->created_at?->setTimezone(CurrentOutlet::timezone())->format('H.i')),
                TextColumn::make('causer_name')->label('Oleh')->weight('medium')->visibleFrom('md')
                    ->state(fn (ActivityLog $record): string => self::causerName($record))
                    ->description(fn (ActivityLog $record): string => self::causerRole($record)),
                TextColumn::make('subject_label')->label('Data')
                    ->state(fn (ActivityLog $record): string => self::subjectName($record))
                    ->description(fn (ActivityLog $record): string => self::SUBJECTS[$record->subject_type][0] ?? 'Data')
                    ->weight('medium')->wrap(),
                TextColumn::make('event')->label('Aksi')->badge()
                    ->state(fn (ActivityLog $record): string => self::eventLabel($record))
                    ->color(fn (string $state): string => self::eventColor($state)),
                // Kolom, nilai sebelum & sesudah dipisah per baris (sejajar), maks. 3 baris; selengkapnya di Detail
                TextColumn::make('changed_fields')->label('Kolom')
                    ->state(fn (ActivityLog $record): array => self::changeColumn($record, 'label'))
                    ->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-field'])
                    // Kotak "Cari" tabel: dicari di server (isi perubahan, nama pelaku, jenis data)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),
                TextColumn::make('old_values')->label('Sebelum')
                    ->state(fn (ActivityLog $record): array => self::changeColumn($record, 'old'))
                    ->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-old']),
                TextColumn::make('new_values')->label('Sesudah')
                    ->state(fn (ActivityLog $record): array => self::changeColumn($record, 'new'))
                    ->listWithLineBreaks()->extraAttributes(['class' => 'gs-diff-cell is-new']),
            ])
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')->label('Detail')->icon(Heroicon::OutlinedEye)->iconButton()->tooltip('Detail perubahan')
                    ->modalHeading(fn (ActivityLog $record): string => self::eventLabel($record).' · '.(self::SUBJECTS[$record->subject_type][0] ?? 'Data'))
                    ->modalDescription(fn (ActivityLog $record): string => self::subjectName($record))
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (ActivityLog $record) => view('filament.dashboard.activity.detail', [
                        'log' => $record,
                        'changes' => self::changes($record, full: true),
                        'causer' => self::causerName($record),
                        'role' => self::causerRole($record),
                        'subject' => self::SUBJECTS[$record->subject_type][0] ?? 'Data',
                        'name' => self::subjectName($record),
                        'event' => self::eventLabel($record),
                        'eventColor' => self::eventColor(self::eventLabel($record)),
                    ])),
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
            'Perubahan harga, produk, menu habis/tersedia, setelan outlet, dan karyawan tercatat otomatis di sini');
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
            ->when(! $user->hasPermission('outlet.access_all'), fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w
                    ->where('subject_type', '!=', Outlet::class)
                    ->orWhereIn('subject_id', $user->outletIds()))
                ->where(fn (Builder $w) => $w
                    ->where('subject_type', '!=', ProductStock::class)
                    ->orWhereIn('subject_id', ProductStock::query()->whereIn('outlet_id', $user->outletIds())->select('id'))));
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
            // (ProductStock tidak punya kolom name; nama produk & outlet sudah ada di properties)
            foreach (array_diff(array_keys(self::SUBJECTS), [ProductStock::class]) as $type) {
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

        if ($record->subject_type === ProductStock::class) {
            return ($properties['product'] ?? '—').' · '.($properties['outlet'] ?? '—');
        }

        $name = $properties['attributes']['name'] ?? $properties['old']['name'] ?? null;

        if (! is_string($name) && $record->subject_type !== null && $record->subject_id !== null) {
            /** @var class-string<Model> $type */
            $type = $record->subject_type;
            $name = $type::query()->withoutGlobalScopes()->whereKey($record->subject_id)->value('name');
        }

        return is_string($name) ? $name : '—';
    }

    /** Label aksi: Dibuat / Diubah / Dihapus / Kredensial / Habis / Tersedia. */
    public static function eventLabel(ActivityLog $record): string
    {
        return match (true) {
            $record->description === 'credentials' => 'Kredensial',
            $record->event === SetProductAvailability::LOG_EVENT => ($record->properties['attributes']['is_available'] ?? false) ? 'Tersedia' : 'Habis',
            $record->event === 'created' => 'Dibuat',
            $record->event === 'deleted' => 'Dihapus',
            default => 'Diubah',
        };
    }

    public static function eventColor(string $label): string
    {
        return match ($label) {
            'Dibuat', 'Tersedia' => 'success',
            'Dihapus' => 'danger',
            'Kredensial', 'Habis' => 'warning',
            default => 'info',
        };
    }

    /**
     * Perubahan per kolom (sebelum & sesudah terpisah). Dibuat: sebelum "—"; dihapus: sesudah "—";
     * kredensial: nilai tidak pernah tersimpan, hanya "Diganti".
     *
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public static function changes(ActivityLog $record, bool $full = false): array
    {
        $properties = $record->properties;

        if ($record->description === 'credentials') {
            return array_values(array_map(fn (string $s): array => [
                'field' => $s, 'label' => $s === 'pin' ? 'PIN' : 'Kata sandi', 'old' => '••••••', 'new' => 'Diganti',
            ], (array) ($properties['secrets'] ?? [])));
        }

        $new = (array) ($properties['attributes'] ?? []);
        $old = (array) ($properties['old'] ?? []);
        $rows = [];
        foreach ($record->event === 'deleted' ? $old : $new as $field => $value) {
            $field = (string) $field;
            $rows[] = [
                'field' => $field,
                'label' => self::FIELDS[$field] ?? $field,
                'old' => $record->event === 'created' ? '—' : self::format($field, $record->event === 'deleted' ? $value : ($old[$field] ?? null), $full),
                'new' => $record->event === 'deleted' ? '—' : self::format($field, $value, $full),
            ];
        }

        return $rows;
    }

    /**
     * Satu sisi perubahan untuk kolom tabel, maks. 3 baris (+N di kolom pertama).
     *
     * @param  'label'|'old'|'new'  $key
     * @return list<string>
     */
    private static function changeColumn(ActivityLog $record, string $key): array
    {
        $changes = self::changes($record);
        $values = array_map(fn (array $c): string => $c[$key], array_slice($changes, 0, 3));
        if (count($changes) > 3) {
            // Baris pengisi (spasi tak putus) agar kolom sebelum/sesudah tetap sejajar dengan kolom pertama
            $values[] = $key === 'label' ? '+'.(count($changes) - 3).' kolom lain' : "\u{00A0}";
        }

        return $values === [] ? ['—'] : $values;
    }

    private static function causerName(ActivityLog $record): string
    {
        return match (true) {
            $record->causer instanceof Admin => 'Tim gs.POS',
            $record->causer instanceof User => $record->causer->name,
            default => 'Sistem',
        };
    }

    private static function causerRole(ActivityLog $record): string
    {
        return match (true) {
            $record->causer instanceof Admin => 'Super admin',
            $record->causer instanceof User => $record->causer->role->getLabel(),
            default => 'Otomatis',
        };
    }

    /** "Harga: Rp24.000 → Rp26.000 · Aktif: Ya → Tidak" */
    public static function summary(ActivityLog $record): string
    {
        $properties = $record->properties;

        if ($record->description === 'credentials') {
            $secrets = (array) ($properties['secrets'] ?? []);

            return implode(' & ', array_map(fn (string $s): string => $s === 'pin' ? 'PIN' : 'Kata sandi', $secrets)).' diganti';
        }

        // "Croissant ditandai habis di Kemang oleh Budi"
        if ($record->event === SetProductAvailability::LOG_EVENT) {
            return (string) $record->description;
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

    private static function format(string $field, mixed $value, bool $full = false): string
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
            default => $full ? (string) $value : mb_strimwidth((string) $value, 0, 60, '…'),
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
