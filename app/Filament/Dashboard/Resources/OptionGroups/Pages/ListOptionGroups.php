<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Pages;

use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListOptionGroups extends ListRecords
{
    protected static string $resource = OptionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah grup opsi')->icon(Heroicon::OutlinedPlus)];
    }
}
