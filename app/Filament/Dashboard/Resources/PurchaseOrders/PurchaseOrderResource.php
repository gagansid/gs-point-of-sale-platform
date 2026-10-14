<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PurchaseOrders;

use App\Actions\Stock\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Dashboard\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOutlet;
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
use Filament\Resources\Resource;
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
 * Stok → Purchase order (ADR 0021; izin purchase.manage): pesanan ke supplier. Draf bisa diubah; Kirim mengunci;
 * Terima barang membuat draf Stok masuk dari sisa qty; Batalkan untuk PO yang belum diterima penuh.
 */
final class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $slug = 'stock/purchase-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Purchase order';

    protected static ?string $modelLabel = 'purchase order';

    protected static ?string $pluralModelLabel = 'purchase order';

    protected static ?int $navigationSort = 0;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('purchase.manage');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && $record instanceof PurchaseOrder && $record->isDraft();
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
        $rules = SavePurchaseOrder::rules();

        return $schema->columns(1)->components([
            Section::make('Pesanan')->columns(2)->schema([
                Select::make('outlet_id')->label('Outlet')->required()->native(false)
                    ->options(fn (): array => Outlet::query()->active()->whereIn('id', CurrentOutlet::ids())->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (string $operation): bool => $operation === 'create' && CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1)
                    ->columnSpanFull(),
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->preload()
                    ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                DatePicker::make('ordered_on')->label('Tanggal pesan')->required()->native(false)->displayFormat('j M Y')
                    ->default(fn (): string => CurrentOutlet::today()),
                DatePicker::make('expected_on')->label('Perkiraan datang')->native(false)->displayFormat('j M Y'),
                TextInput::make('notes')->label('Catatan')->rules($rules['notes']),
            ]),
            Section::make('Item')
                ->description('Produk & bahan yang melacak stok. Harga beli perkiraan, bisa disesuaikan saat barang diterima')
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
                                ->options(fn (): array => Product::query()->where('track_stock', true)->orderBy('name')->get(['id', 'name', 'unit'])
                                    ->mapWithKeys(fn (Product $p): array => [$p->id => $p->name.' ('.$p->unit.')'])->all())
                                ->live()
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
                        ->maxItems(SavePurchaseOrder::MAX_ITEMS)
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
                    ->schema([View::make('filament.dashboard.stock.purchase-order')]),
                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Dibatalkan')->compact()
                        ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Cancelled)
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
                TextColumn::make('number')->label('Nomor')->weight('medium')->searchable(),
                TextColumn::make('ordered_on')->label('Tanggal pesan')->date('j M Y')->sortable(),
                TextColumn::make('expected_on')->label('Perkiraan datang')->date('j M Y')->placeholder('—')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier'),
                TextColumn::make('outlet.name')->label('Outlet')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1),
                TextColumn::make('items_count')->label('Item')->alignEnd(),
                TextColumn::make('total_cost')->label('Total')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(PurchaseOrderStatus::class),
                SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name'),
            ])
            ->recordUrl(fn (PurchaseOrder $record): string => $record->isDraft() ? self::getUrl('edit', ['record' => $record]) : self::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah draf'),
                ViewAction::make()->iconButton()->tooltip('Lihat')->visible(fn (PurchaseOrder $record): bool => ! $record->isDraft()),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedClipboardDocumentList, 'purchase order', 'Catat pesanan ke supplier, lalu terima barangnya lewat Stok masuk');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
