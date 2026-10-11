<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AppVersions\Pages;

use App\Actions\Admin\SaveAppVersion;
use App\Enums\AppPlatform;
use App\Filament\Admin\Resources\AppVersions\AppVersionResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Models\AppVersion;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageAppVersions extends ManageRecords
{
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = AppVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah platform')->modalWidth('md')
                // Satu baris per platform: tombol hilang bila Android & iOS sudah ada
                ->visible(fn (): bool => AppVersion::query()->count() < count(AppVersionResource::platforms()))
                ->using(fn (array $data, CreateAction $action): AppVersion => RunBusinessAction::run(fn (): AppVersion => app(SaveAppVersion::class)->handle(
                    null,
                    $data['platform'] instanceof AppPlatform ? $data['platform'] : AppPlatform::from((string) $data['platform']),
                    (string) $data['min_version'],
                    (string) $data['latest_version'],
                    (bool) ($data['force_update'] ?? false),
                ), $action)),
        ];
    }
}
