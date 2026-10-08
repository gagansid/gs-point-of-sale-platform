<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Pages;

use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use Filament\Resources\Pages\ListRecords;

final class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;
}
