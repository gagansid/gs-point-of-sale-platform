<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use Illuminate\Support\Facades\DB;

/**
 * Tindak lanjut lead oleh super admin: status & catatan.
 */
final class UpdateSalesLead
{
    public function handle(SalesLead $lead, SalesLeadStatus $status, ?string $notes = null): SalesLead
    {
        DB::transaction(fn () => $lead->fill(['status' => $status, 'notes' => $notes])->save());

        return $lead;
    }
}
