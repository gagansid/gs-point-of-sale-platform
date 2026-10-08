<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Categories;

use App\Actions\Product\DeleteCategory;
use App\Actions\Product\SaveCategory;
use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Shared\Layout;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Nama kategori')->weight('medium')->searchable(),
                TextColumn::make('products_count')->label('Jumlah produk')->numeric(locale: 'id')->alignEnd(),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Ubah')
                    ->using(fn (Category $record, array $data): Category => app(SaveCategory::class)
                        ->handle($record, (string) $data['name'])['category']),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Hapus')
                    ->modalHeading('Hapus kategori')
                    ->modalDescription(fn (Category $record): HtmlString => Layout::confirmText(
                        'Yakin ingin menghapus <strong>'.e($record->name).'</strong>?',
                        'Produk di kategori ini tidak ikut terhapus; menjadi tanpa kategori.',
                    ))
                    ->using(function (Category $record): bool {
                        app(DeleteCategory::class)->handle($record);

                        return true;
                    }),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading('Belum ada kategori')
            ->emptyStateDescription('Kelompokkan produk agar kasir mudah mencarinya');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
