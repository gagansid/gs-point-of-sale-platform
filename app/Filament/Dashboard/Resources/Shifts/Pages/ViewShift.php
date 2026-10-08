<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Shifts\Pages;

use App\Filament\Dashboard\Resources\Shifts\Actions\ForceCloseShiftAction;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

final class ViewShift extends ViewRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = ShiftResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['openedBy', 'closedBy', 'device']);
    }

    protected function getHeaderActions(): array
    {
        return [ForceCloseShiftAction::make()];
    }
}
