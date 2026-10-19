<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Admins\Pages;

use App\Actions\Admin\CreateAdmin;
use App\Filament\Admin\Resources\Admins\AdminResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Admin;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageAdmins extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AdminResource::class;

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)->modalHeading('Tambah admin')->modalWidth('lg')
                ->schema(AdminResource::formFields(creating: true))
                ->using(fn (array $data): Admin => app(CreateAdmin::class)->handle((string) $data['name'], (string) $data['email'], (string) $data['password'])),
        ];
    }
}
