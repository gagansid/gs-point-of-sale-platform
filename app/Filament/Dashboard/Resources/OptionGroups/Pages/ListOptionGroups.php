<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Pages;

use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListOptionGroups extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = OptionGroupResource::class;

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [CreateAction::make()->label('Tambah grup opsi')->icon(Heroicon::OutlinedPlus)];
    }
}
