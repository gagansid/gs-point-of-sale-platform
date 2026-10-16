<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices\Pages;

use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ListRecords;

final class ListSubscriptionInvoices extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = SubscriptionInvoiceResource::class;
}
