<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tagihan langganan tenant (ADR 0024). Ditulis lewat App\Actions\Billing\*.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property InvoiceKind $kind
 * @property string $plan_id
 * @property string $plan_name
 * @property BillingCycle $billing_cycle
 * @property string $amount
 * @property InvoiceStatus $status
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string|null $paid_via midtrans / manual
 * @property string|null $gateway_order_id
 * @property string|null $snap_url
 * @property string|null $paid_by_admin_id
 * @property string|null $created_by_admin_id dibuat super admin (null = dibuat owner)
 * @property string|null $notes
 */
final class SubscriptionInvoice extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['plan_id', 'plan_name', 'billing_cycle', 'amount', 'due_at', 'notes'];

    protected $attributes = ['status' => 'pending', 'kind' => 'renewal'];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /** @return HasMany<SubscriptionInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionInvoiceItem::class, 'invoice_id')->orderBy('sort_order');
    }

    /**
     * Uraian singkat untuk tabel & email: "Perpanjangan Pro · Bulanan + Outlet tambahan ×2" / "Add-on: Self-order QR".
     */
    public function description(): string
    {
        $addons = $this->items->where('type', SubscriptionInvoiceItem::ADDON)
            ->map(fn (SubscriptionInvoiceItem $i): string => $i->name.($i->quantity > 1 ? ' ×'.$i->quantity : ''))->implode(', ');

        if ($this->kind === InvoiceKind::Addon) {
            return 'Add-on: '.$addons;
        }

        return $this->kind->getLabel().' '.$this->plan_name.' · '.$this->billing_cycle->getLabel().($addons !== '' ? ' + '.$addons : '');
    }
}
