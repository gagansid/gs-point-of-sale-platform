<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\Actions\TenantAddonInvoiceAction;
use App\Filament\Admin\Resources\Tenants\Actions\TenantAddonsAction;
use App\Filament\Admin\Resources\Tenants\Actions\TenantInvoiceAction;
use App\Filament\Admin\Resources\Tenants\Actions\TenantPlanAction;
use App\Filament\Admin\Resources\Tenants\Actions\TenantStatusActions;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewTenant extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TenantInvoiceAction::make(),
            TenantAddonInvoiceAction::make(),
            TenantPlanAction::make(),
            TenantAddonsAction::make(),
            TenantStatusActions::suspend(),
            TenantStatusActions::activate(),
            EditAction::make()->label('Ubah'),
        ];
    }
}
