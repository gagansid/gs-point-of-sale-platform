<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StockOpnames;

use App\Enums\StockDocumentStatus;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\StockOpnames\Pages\CountStockOpname;
use App\Filament\Dashboard\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Dashboard\Resources\StockOpnames\Pages\ViewStockOpname;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
 * Stok → Stok opname (ADR 0015, Q81; izin stock.opname): lembar hitung fisik per outlet; posting menyamakan stok dengan
 * hitungan dan mencatat selisih (qty & nilai modal).
 */
final class StockOpnameResource extends DashboardResource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = StockOpname::class;

    protected static ?string $slug = 'stock/opnames';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Stok opname';

    protected static ?string $modelLabel = 'stok opname';

    protected static ?string $pluralModelLabel = 'stok opname';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('stock') && $user->hasPermission('stock.opname');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny() && $record instanceof StockOpname && $record->isDraft();
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Lembar hitung: hanya kolom qty fisik yang bisa diisi. */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Hitungan fisik')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->description('Isi qty fisik produk yang dihitung; kosongkan bila tidak dihitung. Selisih dihitung terhadap stok saat posting')
                ->schema([
                    Repeater::make('counts')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Produk'),
                            TableColumn::make('Stok sistem saat dibuat')->width('200px'),
                            TableColumn::make('Qty fisik')->width('160px'),
                        ])
                        ->compact()
                        ->schema([
                            Hidden::make('id'),
                            TextEntry::make('product_name')->hiddenLabel(),
                            TextEntry::make('system_qty')->hiddenLabel()->numeric(),
                            TextInput::make('counted_qty')->hiddenLabel()->integer()->minValue(0)->maxValue(1000000)->placeholder('—'),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false),
                ]),
            TextInput::make('notes')->label('Catatan')->maxLength(255),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make()->columnSpan(['lg' => 2])->schema([View::make('filament.dashboard.stock.opname')]),
                Grid::make(1)->columnSpan(['lg' => 1])->schema([AuditInfo::card()]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['category:id,name', 'outlet:id,name'])->withCount('items'))
            ->columns([
                TextColumn::make('number')->label('Nomor')->weight('medium')->searchable()
                    ->description(fn (StockOpname $record): string => $record->category !== null ? 'Kategori '.$record->category->name : 'Semua produk'),
                TextColumn::make('counted_on')->label('Tanggal hitung')->date('j M Y')->sortable(),
                TextColumn::make('outlet.name')->label('Outlet')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1),
                TextColumn::make('items_count')->label('Produk')->alignEnd(),
                TextColumn::make('difference_qty')->label('Selisih qty')->alignEnd()
                    ->formatStateUsing(fn (int $state, StockOpname $record): string => $record->isDraft() ? '—' : ($state > 0 ? '+' : '').number_format($state, 0, ',', '.')),
                TextColumn::make('difference_value')->label('Nilai selisih')->alignEnd()
                    ->formatStateUsing(fn (string $state, StockOpname $record): string => $record->isDraft() ? '—' : Money::format($state))
                    ->color(fn (string $state): ?string => Money::compare($state, '0') < 0 ? 'danger' : null),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('Status')->options([
                StockDocumentStatus::Draft->value => StockDocumentStatus::Draft->getLabel(),
                StockDocumentStatus::Posted->value => StockDocumentStatus::Posted->getLabel(),
            ])])
            ->recordUrl(fn (StockOpname $record): string => self::getUrl($record->isDraft() ? 'edit' : 'view', ['record' => $record]))
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Isi hitungan'),
                ViewAction::make()->iconButton()->tooltip('Lihat')->visible(fn (StockOpname $record): bool => ! $record->isDraft()),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedClipboardDocumentCheck, 'stok opname', 'Buat lembar hitung, isi qty fisik, lalu posting untuk menyamakan stok');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
            'view' => ViewStockOpname::route('/{record}'),
            'edit' => CountStockOpname::route('/{record}/count'),
        ];
    }
}
