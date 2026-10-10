<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Actions\Sales\Data\SalesLeadData;
use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan calon pelanggan dari halaman depan. IP disimpan sebagai hash HMAC (APP_KEY),
 * cukup untuk mengenali spam berulang tanpa menyimpan IP mentah.
 */
final class CreateSalesLead
{
    public function handle(SalesLeadData $data, ?string $ip = null): SalesLead
    {
        return DB::transaction(fn (): SalesLead => SalesLead::query()->create([
            'name' => $data->name,
            'business_name' => $data->businessName,
            'phone' => $data->phone,
            'email' => $data->email,
            'city' => $data->city,
            'business_type' => $data->businessType,
            'message' => $data->message,
            'status' => SalesLeadStatus::New,
            'ip_hash' => $ip !== null ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
        ]));
    }
}
