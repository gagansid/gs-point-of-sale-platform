<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Orders\Pages;

use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = OrderResource::class;
}
