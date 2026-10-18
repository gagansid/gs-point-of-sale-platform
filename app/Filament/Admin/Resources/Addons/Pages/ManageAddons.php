<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Addons\Pages;

use App\Filament\Admin\Resources\Addons\AddonResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Addon;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageAddons extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AddonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat add-on')->modalWidth('2xl')
                ->using(fn (array $data, CreateAction $action): Addon => AddonResource::save(null, $data, $action)),
        ];
    }
}
