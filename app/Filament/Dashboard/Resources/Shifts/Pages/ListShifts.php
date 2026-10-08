<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Pages;

use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;

final class ListShifts extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = ShiftResource::class;
}
