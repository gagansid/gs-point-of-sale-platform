<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\Announcement;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageAnnouncements extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AnnouncementResource::class;

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)->modalHeading('Tambah pengumuman')->modalWidth('xl')
                ->using(fn (array $data, CreateAction $action): Announcement => AnnouncementResource::save(null, $data, $action)),
        ];
    }
}
