<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Actions\Tenant\UpdateTenant;
use App\Enums\BusinessType;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditTenant extends EditRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = TenantResource::class;

    protected static ?string $breadcrumb = 'Ubah';

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Lihat'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Tenant $record */
        $endsAt = $data['subscription_ends_at'] ?? null;

        return app(UpdateTenant::class)->handle(
            $record,
            (string) $data['name'],
            (string) $data['slug'],
            $data['business_type'] instanceof BusinessType ? $data['business_type'] : BusinessType::from((string) $data['business_type']),
            filled($endsAt) ? CarbonImmutable::parse($endsAt) : null,
        );
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Tenant berhasil diperbarui';
    }
}
