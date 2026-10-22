<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Promotions;

use App\Actions\Promotion\SavePromotion;
use App\Actions\Promotion\SetPromotionActive;
use App\Enums\DiscountType;
use App\Enums\PromotionType;
use App\Filament\Dashboard\Resources\Promotions\Pages\ManagePromotions;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pelanggan → Promo (ADR 0022; izin promo.manage): promo otomatis tanpa kode — diskon produk/kategori (happy hour),
 * beli X gratis Y, diskon minimal belanja; jadwal hari, jam, tanggal, outlet.
 */
final class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static ?string $slug = 'promotions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Promo';

    protected static ?string $modelLabel = 'promo';

    protected static ?string $pluralModelLabel = 'promo';

    protected static ?int $navigationSort = 6;

    protected static bool $isGloballySearchable = false;

    public const DAYS = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('promotions') && $user->hasFullDashboard() && $user->hasPermission('promo.manage');
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('promo.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $type = fn (Get $get): PromotionType => $get('type') instanceof PromotionType ? $get('type') : (PromotionType::tryFrom((string) $get('type')) ?? PromotionType::ItemDiscount);
        $percent = fn (Get $get): bool => ($get('discount_type') instanceof DiscountType ? $get('discount_type')->value : $get('discount_type')) === DiscountType::Percent->value;
        $applies = fn (Get $get): string => (string) ($get('applies_to') ?? Promotion::APPLIES_ALL);

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama promo')->required()->maxLength(100)->placeholder('Happy hour kopi')->autofocus()
                ->helperText('Tampil di struk & keranjang')->columnSpanFull(),
            ToggleButtons::make('type')->label('Jenis')->required()->inline()->live()->default(PromotionType::ItemDiscount->value)
                ->options(PromotionType::class)->columnSpanFull(),

            ToggleButtons::make('discount_type')->label('Potongan')->inline()->live()->default(DiscountType::Percent->value)
                ->options([DiscountType::Percent->value => 'Persen', DiscountType::Fixed->value => 'Nominal'])
                ->visible(fn (Get $get): bool => $type($get) !== PromotionType::BuyGet)
                ->required(fn (Get $get): bool => $type($get) !== PromotionType::BuyGet),
            TextInput::make('discount_value')->label(fn (Get $get): string => $type($get) === PromotionType::ItemDiscount ? 'Potongan per item' : 'Potongan')
                ->numeric()->minValue(0)
                ->prefix(fn (Get $get): ?string => $percent($get) ? null : 'Rp')
                ->suffix(fn (Get $get): ?string => $percent($get) ? '%' : null)
                ->visible(fn (Get $get): bool => $type($get) !== PromotionType::BuyGet)
                ->required(fn (Get $get): bool => $type($get) !== PromotionType::BuyGet),
            MoneyInput::make('max_discount')->label('Maks. potongan')->helperText('Kosong = tanpa batas')
                ->visible(fn (Get $get): bool => $type($get) === PromotionType::OrderDiscount && $percent($get)),
            MoneyInput::make('min_subtotal')->label('Minimal belanja')
                ->visible(fn (Get $get): bool => $type($get) === PromotionType::OrderDiscount),
            TextInput::make('buy_qty')->label('Beli')->integer()->minValue(1)->maxValue(100)->default(2)->suffix('item')
                ->visible(fn (Get $get): bool => $type($get) === PromotionType::BuyGet)
                ->required(fn (Get $get): bool => $type($get) === PromotionType::BuyGet),
            TextInput::make('get_qty')->label('Gratis')->integer()->minValue(1)->maxValue(100)->default(1)->suffix('item')
                ->helperText('Item termurah yang gratis')
                ->visible(fn (Get $get): bool => $type($get) === PromotionType::BuyGet)
                ->required(fn (Get $get): bool => $type($get) === PromotionType::BuyGet),

            Section::make('Berlaku untuk')->icon(Heroicon::OutlinedTag)->columns(2)->columnSpanFull()
                ->visible(fn (Get $get): bool => $type($get) !== PromotionType::OrderDiscount)
                ->schema([
                    ToggleButtons::make('applies_to')->hiddenLabel()->inline()->live()->default(Promotion::APPLIES_ALL)
                        ->options([Promotion::APPLIES_ALL => 'Semua produk', Promotion::APPLIES_PRODUCTS => 'Produk tertentu', Promotion::APPLIES_CATEGORIES => 'Kategori tertentu'])
                        ->columnSpanFull(),
                    Select::make('product_ids')->label('Produk')->multiple()->searchable()->columnSpanFull()
                        ->options(fn (): array => Product::query()->sellable()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $applies($get) === Promotion::APPLIES_PRODUCTS),
                    Select::make('category_ids')->label('Kategori')->multiple()->columnSpanFull()
                        ->options(fn (): array => Category::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $applies($get) === Promotion::APPLIES_CATEGORIES),
                ]),

            Section::make('Jadwal')->icon(Heroicon::OutlinedCalendarDays)->columns(2)->columnSpanFull()
                ->description('Kosongkan untuk berlaku setiap saat. Jam memakai waktu outlet; selesai < mulai = lewat tengah malam')
                ->schema([
                    CheckboxList::make('days')->label('Hari')->options(self::DAYS)->columns(7)->columnSpanFull(),
                    TimePicker::make('time_start')->label('Jam mulai')->seconds(false)->native(false),
                    TimePicker::make('time_end')->label('Jam selesai')->seconds(false)->native(false),
                    DatePicker::make('starts_on')->label('Tanggal mulai')->native(false)->displayFormat('j M Y'),
                    DatePicker::make('ends_on')->label('Tanggal selesai')->native(false)->displayFormat('j M Y'),
                    Select::make('outlet_ids')->label('Outlet')->multiple()->placeholder('Semua outlet')->columnSpanFull()
                        ->options(fn (): array => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (): bool => Outlet::query()->count() > 1),
                ]),
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Promo')->weight('medium')->searchable()
                    ->description(fn (Promotion $record): string => $record->type->getLabel()),
                TextColumn::make('benefit')->label('Potongan')->state(fn (Promotion $record): string => self::benefit($record)),
                TextColumn::make('schedule')->label('Jadwal')->state(fn (Promotion $record): string => self::schedule($record)),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Promotion $record): string => match (true) {
                        ! $record->is_active => 'Nonaktif',
                        $record->ends_on !== null && $record->ends_on->toDateString() < CurrentOutlet::today() => 'Berakhir',
                        default => 'Aktif',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Nonaktif' => 'warning',
                        default => 'gray',
                    }),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('3xl')->successNotificationTitle('Promo diperbarui')
                    ->mutateRecordDataUsing(fn (Promotion $record, array $data): array => [
                        ...$data,
                        'type' => $record->type->value,
                        'discount_type' => $record->discount_type?->value,
                        'days' => array_map('strval', $record->days ?? []),
                    ])
                    ->using(fn (Promotion $record, array $data, EditAction $action): Promotion => RunBusinessAction::run(
                        fn (): Promotion => app(SavePromotion::class)->handle($record, self::payload($data)),
                        $action,
                    )),
                ActionGroup::make(ActiveStatusActions::make(
                    Promotion::class,
                    fn (Promotion $promotion, bool $active) => app(SetPromotionActive::class)->handle($promotion, $active),
                    'promo',
                    'Promo tidak berlaku di kasir sampai diaktifkan lagi.',
                    'promo.manage',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedSparkles, 'promo', 'Buat promo otomatis, mis. happy hour 20% atau beli 2 gratis 1');
    }

    /**
     * Data form → input SavePromotion (enum & waktu dari komponen Filament menjadi string).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function payload(array $data): array
    {
        $value = fn (mixed $v): ?string => $v instanceof BackedEnum ? (string) $v->value : (filled($v) ? (string) $v : null);

        return [
            ...$data,
            'type' => $value($data['type'] ?? null),
            'discount_type' => $value($data['discount_type'] ?? null),
            'time_start' => filled($data['time_start'] ?? null) ? substr((string) $data['time_start'], 0, 5) : null,
            'time_end' => filled($data['time_end'] ?? null) ? substr((string) $data['time_end'], 0, 5) : null,
            'starts_on' => filled($data['starts_on'] ?? null) ? substr((string) $data['starts_on'], 0, 10) : null,
            'ends_on' => filled($data['ends_on'] ?? null) ? substr((string) $data['ends_on'], 0, 10) : null,
            'days' => array_values(array_map('intval', (array) ($data['days'] ?? []))),
        ];
    }

    private static function benefit(Promotion $promo): string
    {
        $amount = $promo->discount_type === DiscountType::Percent
            ? rtrim(rtrim($promo->discount_value, '0'), '.').'%'
            : Money::format($promo->discount_value);

        return match ($promo->type) {
            PromotionType::BuyGet => "Beli {$promo->buy_qty} gratis {$promo->get_qty}",
            PromotionType::ItemDiscount => $amount.' per item',
            PromotionType::OrderDiscount => $amount.($promo->min_subtotal !== null ? ' · min. '.Money::format($promo->min_subtotal) : ''),
        };
    }

    private static function schedule(Promotion $promo): string
    {
        $parts = [];
        if ($promo->days !== null && $promo->days !== [] && count($promo->days) < 7) {
            $parts[] = collect($promo->days)->map(fn (mixed $d): string => self::DAYS[(int) $d] ?? '')->implode(', ');
        }
        if ($promo->time_start !== null && $promo->time_end !== null) {
            $parts[] = str_replace(':', '.', $promo->time_start).'–'.str_replace(':', '.', $promo->time_end);
        }
        if ($promo->ends_on !== null) {
            $parts[] = 's/d '.$promo->ends_on->locale('id')->translatedFormat('j M Y');
        }

        return $parts === [] ? 'Setiap saat' : implode(' · ', $parts);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromotions::route('/')];
    }
}
