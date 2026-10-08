<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListTenants extends ListRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = TenantResource::class;

    /**
     * @return array<Action>
     */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()->label('Tambah tenant')->icon(Heroicon::OutlinedPlus),
        ];
    }
}
