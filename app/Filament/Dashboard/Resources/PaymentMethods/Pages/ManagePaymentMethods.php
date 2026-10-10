<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\PaymentMethods\Pages;

use App\Filament\Dashboard\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Resources\Pages\ManageRecords;

final class ManagePaymentMethods extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = PaymentMethodResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;
}
