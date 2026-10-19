<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices\Pages;

use App\Actions\Billing\CreateManualInvoice;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SubscriptionInvoices\Schemas\ManualInvoiceForm;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\Admin;
use App\Models\Tenant;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Tagihan → Tambah: tagihan manual admin (CreateManualInvoice). */
final class CreateSubscriptionInvoice extends CreateRecord
{
    use HasIconBreadcrumbs;

    protected static string $resource = SubscriptionInvoiceResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Tagihan manual';

    protected static ?string $breadcrumb = 'Tambah';

    protected function handleRecordCreation(array $data): Model
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $tenant = Tenant::query()->findOrFail($data['tenant_id']);

        try {
            return app(CreateManualInvoice::class)->handle($tenant, $admin, ManualInvoiceForm::toActionData($data), (bool) ($data['notify_owners'] ?? false));
        } catch (BusinessException|ValidationException $e) {
            // Aturan server (diskon > subtotal, add-on sudah aktif, ...) tampil sebagai notifikasi; form tetap terisi
            $details = $e instanceof ValidationException ? $e->errors() : (array) $e->details;
            Notification::make()->danger()->title($e->getMessage())
                ->body(implode(' ', array_unique(array_merge(...array_values(array_map(fn (mixed $m): array => array_map('strval', (array) $m), $details))))))
                ->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Tagihan '.$this->getRecord()->getAttribute('number').' dibuat';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
