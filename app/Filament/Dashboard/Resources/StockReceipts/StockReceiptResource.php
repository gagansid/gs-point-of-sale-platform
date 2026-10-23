<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockReceipts;

use App\Actions\Stock\SaveStockReceipt;
use App\Enums\StockDocumentStatus;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\CreateStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\EditStockReceipt;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\ListStockReceipts;
use App\Filament\Dashboard\Resources\StockReceipts\Pages\ViewStockReceipt;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Stok → Stok masuk (ADR 0015, Q79; izin stock.receive): catat barang datang per outlet beserta harga beli. Draf bisa
 * diubah; Posting menambah stok & menghitung modal rata-rata tertimbang; Batalkan mengurangi stok lagi.
 * Supervisor (dashboard hanya Laporan) juga mendapat menu ini untuk outlet yang ditugaskan.
 */
final class StockReceiptResource extends DashboardResource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = StockReceipt::class;

    protected static ?string $slug = 'stock/receipts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Stok masuk';

    protected static ?string $modelLabel = 'stok masuk';

    protected static ?string $pluralModelLabel = 'stok masuk';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    /** Supervisor (tanpa dashboard.access) mencatat barang datang di outlet (Q79, ADR 0026). */
    protected static bool $requiresFullDashboard = false;

    protected static ?string $recordTitleAttribute = 'number';

    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('stock') && $user->hasPermission('stock.receive');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && $record instanceof StockReceipt && $record->isDraft();
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = SaveStockReceipt::rules();

        return $schema->columns(1)->components([
            Section::make('Barang datang')->icon(Heroicon::OutlinedTruck)->columns(2)->schema([
                // "Semua outlet" di topbar: pilih outlet penerima
                Select::make('outlet_id')->label('Outlet')->required()->native(false)
                    ->options(fn (): array => Outlet::query()->active()->whereIn('id', CurrentOutlet::ids())->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (string $operation): bool => $operation === 'create' && CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1)
                    ->columnSpanFull(),
                DatePicker::make('received_on')->label('Tanggal terima')->required()->native(false)->displayFormat('j M Y')
                    ->default(fn (): string => CurrentOutlet::today())->maxDate(fn (): string => CurrentOutlet::today()),
                Select::make('supplier_id')->label('Supplier')->searchable()->preload()->placeholder('Tanpa supplier')
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('invoice_number')->label('Nomor nota supplier')->rules($rules['invoice_number']),
                TextInput::make('notes')->label('Catatan')->rules($rules['notes']),
            ]),
            Section::make('Item')->icon(Heroicon::OutlinedListBullet)
                ->description('Hanya produk dengan lacak stok. Harga beli dipakai menghitung harga modal rata-rata saat diposting')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Produk')->markAsRequired(),
                            TableColumn::make('Qty')->markAsRequired()->width('120px'),
                            TableColumn::make('Harga beli / unit')->markAsRequired()->width('200px'),
                            TableColumn::make('Subtotal')->width('160px'),
                        ])
                        ->compact()
                        ->schema([
                            Select::make('product_id')->hiddenLabel()->required()->searchable()->native(false)
                                ->options(fn (): array => Product::query()->where('track_stock', true)->orderBy('name')->pluck('name', 'id')->all())
                                ->live()
                                // Harga modal saat ini sebagai isian awal harga beli
                                ->afterStateUpdated(function (?string $state, callable $set): void {
                                    $cost = $state !== null ? Product::query()->whereKey($state)->value('cost_price') : null;
                                    $set('unit_cost', $cost !== null ? (string) (int) $cost : null);
                                }),
                            TextInput::make('qty')->hiddenLabel()->integer()->required()->minValue(1)->default(1)->live(onBlur: true),
                            MoneyInput::make('unit_cost')->hiddenLabel()->required()->live(onBlur: true),
                            TextEntry::make('subtotal')->hiddenLabel()
                                ->state(fn (Get $get): string => Money::format(Money::mul(Money::of(is_numeric($get('unit_cost')) ? (string) $get('unit_cost') : '0'), max(0, (int) $get('qty'))))),
                        ])
                        ->minItems(1)
                        ->maxItems(SaveStockReceipt::MAX_ITEMS)
                        ->defaultItems(1)
                        ->addActionLabel('Tambah item')
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make()->columnSpan(['lg' => 2])
                    ->schema([View::make('filament.dashboard.stock.receipt')]),
                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Dibatalkan')->icon(Heroicon::OutlinedXCircle)->compact()
                        ->visible(fn (StockReceipt $record): bool => $record->status === StockDocumentStatus::Cancelled)
                        ->schema([
                            TextEntry::make('cancelled_at')->label('Waktu')->dateTime('j M Y, H.i')->timezone(CurrentOutlet::timezone()),
                            TextEntry::make('cancelledBy.name')->label('Oleh'),
                            TextEntry::make('cancel_reason')->label('Alasan'),
                        ]),
                    AuditInfo::card(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier:id,name', 'outlet:id,name'])->withCount('items'))
            ->columns([
                TextColumn::make('number')->label('Nomor')->weight('medium')->searchable()
                    ->description(fn (StockReceipt $record): ?string => $record->invoice_number !== null ? 'Nota '.$record->invoice_number : null),
                TextColumn::make('received_on')->label('Tanggal terima')->date('j M Y')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->placeholder('—'),
                TextColumn::make('outlet.name')->label('Outlet')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1),
                TextColumn::make('items_count')->label('Item')->alignEnd(),
                TextColumn::make('total_cost')->label('Total beli')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StockDocumentStatus::class),
                SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name'),
            ])
            ->recordUrl(fn (StockReceipt $record): string => $record->isDraft() ? self::getUrl('edit', ['record' => $record]) : self::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah draf'),
                ViewAction::make()->iconButton()->tooltip('Lihat')->visible(fn (StockReceipt $record): bool => ! $record->isDraft()),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedArrowDownTray, 'stok masuk', 'Catat barang datang dari supplier agar stok & harga modal ikut terbarui');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockReceipts::route('/'),
            'create' => CreateStockReceipt::route('/create'),
            'view' => ViewStockReceipt::route('/{record}'),
            'edit' => EditStockReceipt::route('/{record}/edit'),
        ];
    }
}
