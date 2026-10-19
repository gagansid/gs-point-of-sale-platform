<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Plans;

use App\Actions\Billing\SavePlan;
use App\Actions\Billing\SetPlanActive;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Admin\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Feature;
use App\Models\Plan;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Pelanggan → Paket (ADR 0024, Q108): paket langganan SaaS, harga, kuota, fitur, status aktif, dan periode
 * penjualan opsional (promo: bisa dipilih owner sampai tanggal tertentu; kosong = tanpa batas waktu).
 */
final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $slug = 'plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Paket';

    protected static ?string $modelLabel = 'paket';

    protected static ?string $pluralModelLabel = 'paket';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        $limited = fn (Get $get): bool => (bool) $get('has_period');

        return $schema->columns(2)->components([
            Section::make('Identitas paket')->description('Nama tampil di halaman Langganan owner & tagihan')
                ->columns(2)->columnSpanFull()->schema([
                    TextInput::make('name')->label('Nama')->required()->maxLength(60)->placeholder('Pro')->autofocus(),
                    TextInput::make('code')->label('Kode')->required()->maxLength(30)->placeholder('pro')
                        ->helperText('Huruf kecil, angka, tanda hubung'),
                    Textarea::make('description')->label('Keterangan')->maxLength(255)->rows(2)->autosize()->columnSpanFull(),
                ]),
            Section::make('Harga')->description('Harga tahunan biasanya 10× bulanan (2 bulan gratis)')
                ->columns(3)->columnSpanFull()->schema([
                    MoneyInput::make('price_monthly')->label('Harga bulanan')->required(),
                    MoneyInput::make('price_yearly')->label('Harga tahunan')->required(),
                    TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(999)->default(0)
                        ->helperText('Urutan tampil, kecil di depan'),
                ]),
            Section::make('Periode penjualan')
                ->description('Untuk paket promo. Di luar periode paket tidak bisa dipilih owner; bisnis yang sudah memakainya tetap bisa memperpanjang')
                ->columns(2)->columnSpanFull()->schema([
                    Toggle::make('has_period')->label('Batasi periode')->live()->dehydrated(false)->columnSpanFull()
                        ->afterStateHydrated(fn (Toggle $component, ?Plan $record) => $component->state($record?->available_from !== null || $record?->available_until !== null))
                        ->helperText('Mati = tanpa batas waktu'),
                    DateTimePicker::make('available_from')->label('Mulai')->visible($limited)
                        ->helperText('Kosong = mulai sekarang'),
                    DateTimePicker::make('available_until')->label('Berakhir')->visible($limited)->required($limited)
                        ->after('available_from'),
                ]),
            // Kuota dari master data quotas (ADR 0025); kosong = tanpa batas
            Section::make('Kuota')->description('Kosong = tanpa batas')->compact()->columns(3)->columnSpanFull()
                ->schema(fn (): array => TenantForm::limitFields('limits', 'Tanpa batas')),
            Section::make('Fitur')->description('Kasir, laporan, retur, pecah bill, kas masuk/keluar selalu tersedia')->columnSpanFull()->schema([
                CheckboxList::make('features')->hiddenLabel()->options(fn (): array => Feature::options())->columns(2)->bulkToggleable()
                    ->helperText('Daftar fitur dikelola di database (tabel features)'),
            ]),
            Section::make('Status')->description('Paket nonaktif tidak bisa ditagihkan; bisnis yang memakainya tetap berjalan')
                ->columns(2)->columnSpanFull()->schema([
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    Toggle::make('is_public')->label('Tampil di halaman Langganan owner')->default(true)
                        ->helperText('Mati = paket khusus, hanya bisa ditagihkan admin'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $allFeatures = fn (): array => once(fn (): array => array_keys(Feature::options()));

        $table = $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily(FontFamily::Mono)->size('xs')->searchable()->sortable()
                    ->copyable()->copyMessage('Kode disalin'),
                TextColumn::make('name')->label('Paket')->weight('medium')->searchable()->sortable()
                    // Keterangan lengkap lewat tooltip agar kolom tetap ramping
                    ->tooltip(fn (Plan $record): ?string => $record->description),
                // Satu kolom: bulanan di atas, tahunan di bawah (tabel tetap muat di layar 1366px)
                TextColumn::make('price_monthly')->label('Harga')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state).'/bln')
                    ->description(fn (Plan $record): string => Money::format($record->price_yearly).'/thn'),
                TextColumn::make('limits')->label('Kuota')->state(fn (Plan $record): string => $record->limitsSummary() ?: 'Tanpa batas')->wrap()
                    ->extraCellAttributes(['class' => 'gs-ta-cell-wide']),
                TextColumn::make('features')->label('Fitur')
                    ->state(fn (Plan $record): string => count(array_intersect($record->features, $allFeatures())).' dari '.count($allFeatures())),
                TextColumn::make('period')->label('Periode')
                    ->state(fn (Plan $record): string => self::periodText($record))
                    ->description(fn (Plan $record): ?string => $record->periodStatus() === Plan::PERIOD_UNLIMITED ? null : Plan::PERIODS[$record->periodStatus()]),
                TextColumn::make('tenants_count')->label('Bisnis')->counts('tenants')->alignEnd()->sortable(),
                TextColumn::make('is_public')->label('Tampil')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Publik' : 'Khusus')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                TextColumn::make('is_active')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ...AuditColumns::make(),
            ])
            ->filters([
                SelectFilter::make('is_public')->label('Tampil')->options(['1' => 'Publik', '0' => 'Khusus']),
                SelectFilter::make('period')->label('Periode')->options(Plan::PERIODS)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? (new Plan)->scopePeriodStatus($query, (string) $data['value']) : $query),
                SelectFilter::make('feature')->label('Memuat fitur')->options(fn (): array => Feature::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereJsonContains('features', (string) $data['value']) : $query),
                TernaryFilter::make('used')->label('Dipakai bisnis')->trueLabel('Dipakai')->falseLabel('Belum dipakai')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('tenants'),
                        false: fn (Builder $query): Builder => $query->doesntHave('tenants'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('3xl')
                    ->using(fn (Plan $record, array $data, EditAction $action): Plan => self::save($record, $data, $action)),
                ActionGroup::make([
                    ...ActiveStatusActions::make(
                        Plan::class,
                        fn (Plan $plan, bool $active) => app(SetPlanActive::class)->handle($plan, $active),
                        'paket',
                        'Paket tidak bisa ditagihkan sampai diaktifkan lagi. Bisnis yang memakainya tetap berjalan.',
                        null,
                    ),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedRectangleStack, 'paket', 'Tambah paket langganan, mis. Basic dan Pro');
    }

    /** "Tanpa batas" · "s/d 31 Des 2026" · "1 Nov – 31 Des 2026" (zona waktu panel). */
    public static function periodText(Plan $plan): string
    {
        $fmt = fn (CarbonImmutable $d): string => $d->setTimezone(FilamentTimezone::get())->locale('id')->translatedFormat('j M Y');

        return match (true) {
            $plan->available_from === null && $plan->available_until === null => 'Tanpa batas',
            $plan->available_from === null => 's/d '.$fmt($plan->available_until),
            $plan->available_until === null => 'Mulai '.$fmt($plan->available_from),
            default => $fmt($plan->available_from).' – '.$fmt($plan->available_until),
        };
    }

    /** @param array<string, mixed> $data */
    public static function save(?Plan $record, array $data, Action $action): Plan
    {
        // Periode dimatikan: field tersembunyi tidak terkirim → tanpa batas waktu
        $data += ['available_from' => null, 'available_until' => null];

        return RunBusinessAction::run(fn (): Plan => app(SavePlan::class)->handle($record, $data), $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }
}
