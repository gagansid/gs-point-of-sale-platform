<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Plans\Pages;

use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Plan;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePlans extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat paket')->modalWidth('2xl')
                ->using(fn (array $data, CreateAction $action): Plan => PlanResource::save(null, $data, $action)),
        ];
    }
}
