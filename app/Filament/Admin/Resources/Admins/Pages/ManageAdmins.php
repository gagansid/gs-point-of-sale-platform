<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Actions\Admin\CreateAdmin;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Admin;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageAdmins extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AdminResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah admin')->modalWidth('lg')
                ->schema(AdminResource::formFields(creating: true))
                ->using(fn (array $data): Admin => app(CreateAdmin::class)->handle((string) $data['name'], (string) $data['email'], (string) $data['password'])),
        ];
    }
}
