<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Actions\Tenant\CreateTenantWithOwner;
use App\Actions\Tenant\Data\CreateTenantData;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTenant extends CreateRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = TenantResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tambah tenant';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        $admin = auth('admin')->user();

        return app(CreateTenantWithOwner::class)->handle(
            CreateTenantData::fromArray($data),
            $admin instanceof Admin ? $admin : null,
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Tenant berhasil ditambahkan';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
