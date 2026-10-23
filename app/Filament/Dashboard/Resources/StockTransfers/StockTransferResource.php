<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockTransfers;

use App\Enums\StockTransferStatus;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Dashboard\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
 * Stok → Transfer (ADR 0015, Q82; izin stock.transfer): pindah stok antar outlet dua langkah. Daftar berisi transfer
 * dari/ke outlet yang dipegang user (dan sesuai pilihan outlet topbar).
 */
final class StockTransferResource extends DashboardResource
{
    protected static ?string $model = StockTransfer::class;

    protected static ?string $slug = 'stock/transfers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Transfer';

    protected static ?string $modelLabel = 'transfer';

    protected static ?string $pluralModelLabel = 'transfer';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        // Butuh minimal 2 outlet aktif di bisnis
        return $user instanceof User && Features::enabled('stock') && $user->hasPermission('stock.transfer')
            && Outlet::query()->active()->count() > 1;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return self::canViewAny() && $record instanceof StockTransfer && $record->isDraft()
            && $user instanceof User && $user->canAccessOutlet($record->from_outlet_id);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return Builder<StockTransfer> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<StockTransfer> $query */
        $query = parent::getEloquentQuery();

        return $query->touching(CurrentOutlet::ids());
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $mine = $user instanceof User ? $user->outletIds() : [];

        return $schema->columns(1)->components([
            Section::make('Transfer')->icon(Heroicon::OutlinedArrowsRightLeft)->columns(2)->schema([
                Select::make('from_outlet_id')->label('Dari outlet')->required()->native(false)->live()
                    ->options(fn (): array => Outlet::query()->active()->whereIn('id', $mine)->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?string => CurrentOutlet::selectedId())
                    ->disabledOn('edit'),
                Select::make('to_outlet_id')->label('Ke outlet')->required()->native(false)
                    ->options(fn (Get $get): array => Outlet::query()->active()->whereKeyNot((string) $get('from_outlet_id'))->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('notes')->label('Catatan')->maxLength(255)->columnSpanFull(),
            ]),
            Section::make('Item')->icon(Heroicon::OutlinedListBullet)->description('Hanya produk dengan lacak stok')->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Produk')->markAsRequired(),
                        TableColumn::make('Qty')->markAsRequired()->width('140px'),
                    ])
                    ->compact()
                    ->schema([
                        Select::make('product_id')->hiddenLabel()->required()->searchable()->native(false)
                            ->options(fn (): array => Product::query()->where('track_stock', true)->orderBy('name')->pluck('name', 'id')->all()),
                        TextInput::make('qty')->hiddenLabel()->integer()->required()->minValue(1)->default(1),
                    ])
                    ->minItems(1)
                    ->maxItems(200)
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
                Section::make()->columnSpan(['lg' => 2])->schema([View::make('filament.dashboard.stock.transfer')]),
                Grid::make(1)->columnSpan(['lg' => 1])->schema([
                    Section::make('Dibatalkan')->icon(Heroicon::OutlinedXCircle)->compact()
                        ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::Cancelled)
                        ->schema([TextEntry::make('cancel_reason')->label('Alasan')]),
                    AuditInfo::card(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['fromOutlet:id,name', 'toOutlet:id,name'])->withCount('items'))
            ->columns([
                TextColumn::make('number')->label('Nomor')->weight('medium')->searchable(),
                TextColumn::make('route')->label('Dari → ke')
                    ->state(fn (StockTransfer $record): string => ($record->fromOutlet->name ?? '?').' → '.($record->toOutlet->name ?? '?')),
                TextColumn::make('items_count')->label('Item')->alignEnd(),
                TextColumn::make('sent_at')->label('Dikirim')->dateTime('j M Y, H.i')->timezone(CurrentOutlet::timezone())->placeholder('—')->sortable(),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('Status')->options(StockTransferStatus::class)])
            ->recordUrl(fn (StockTransfer $record): string => self::canEdit($record) ? self::getUrl('edit', ['record' => $record]) : self::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah draf'),
                ViewAction::make()->iconButton()->tooltip('Lihat')->visible(fn (StockTransfer $record): bool => ! self::canEdit($record)),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedArrowsRightLeft, 'transfer', 'Pindahkan stok dari satu outlet ke outlet lain');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
