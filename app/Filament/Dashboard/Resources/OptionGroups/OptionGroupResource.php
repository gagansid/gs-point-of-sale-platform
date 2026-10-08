<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups;

use App\Filament\Dashboard\Resources\OptionGroups\Pages\CreateOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\EditOptionGroup;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Filament\Dashboard\Resources\OptionGroups\Schemas\OptionGroupForm;
use App\Filament\Dashboard\Resources\OptionGroups\Tables\OptionGroupsTable;
use App\Models\OptionGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Menu Katalog → Grup Opsi (Ukuran, Gula, Topping) beserta opsinya.
 */
final class OptionGroupResource extends Resource
{
    protected static ?string $model = OptionGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Grup Opsi';

    protected static ?string $modelLabel = 'grup opsi';

    protected static ?string $pluralModelLabel = 'grup opsi';

    public static function form(Schema $schema): Schema
    {
        return OptionGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OptionGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptionGroups::route('/'),
            'create' => CreateOptionGroup::route('/create'),
            'edit' => EditOptionGroup::route('/{record}/edit'),
        ];
    }
}
