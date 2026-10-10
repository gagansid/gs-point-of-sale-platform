<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Categories;

use App\Actions\Product\DeleteCategory;
use App\Actions\Product\SaveCategory;
use App\Actions\Product\SetCategoryActive;
use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\BulkDeleteAction;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
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
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Menu Katalog → Kategori: CRUD dalam modal, urutan bisa digeser (urutan tampil di aplikasi kasir).
 */
final class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Kategori';

    protected static ?string $modelLabel = 'kategori';

    protected static ?string $pluralModelLabel = 'kategori';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama kategori')
                ->placeholder('Kopi')
                ->required()
                ->maxLength(100)
                ->autofocus(),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Kategori nonaktif beserta produknya tidak tampil di aplikasi kasir'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action
                ->tooltip($isReordering ? 'Selesai mengatur urutan' : 'Atur urutan tampil di kasir'))
            // Urutan bawaan = urutan tampil di kasir; Filament menambahkan id sebagai pemecah seri
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('Nama kategori')->weight('medium')->searchable()->sortable(),
                TextColumn::make('products_count')->label('Jumlah produk')->numeric(locale: 'id')->alignEnd()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Ubah')
                    ->using(fn (Category $record, array $data): Category => app(SaveCategory::class)
                        ->handle($record, (string) $data['name'], isActive: (bool) $data['is_active'])['category']),
                ActionGroup::make([
                    ...ActiveStatusActions::make(
                        Category::class,
                        fn (Category $category, bool $active) => app(SetCategoryActive::class)->handle($category, $active),
                        'kategori',
                        'Kategori dan produk di dalamnya tidak tampil di aplikasi kasir sampai diaktifkan lagi.',
                        'product.manage',
                    ),
                    DeleteAction::make()
                        ->modalHeading('Hapus kategori')
                        ->modalDescription(fn (Category $record): HtmlString => Layout::confirmText(
                            'Yakin ingin menghapus <strong>'.e($record->name).'</strong>?',
                            'Produk di kategori ini tidak ikut terhapus; menjadi tanpa kategori.',
                        ))
                        ->using(function (Category $record): bool {
                            app(DeleteCategory::class)->handle($record);

                            return true;
                        }),
                ])->tooltip('Aksi lain'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkDeleteAction::make(
                        Category::class,
                        fn (Category $category) => app(DeleteCategory::class)->handle($category),
                        'kategori',
                        'Produk di kategori ini tidak ikut terhapus; menjadi tanpa kategori.',
                        'product.manage',
                    ),
                ])->label('Aksi massal'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedTag, 'kategori', 'Kelompokkan produk agar kasir mudah mencarinya');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
