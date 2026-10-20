<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoicePaymentMethod;
use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
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
 * @property string|null $plan_id null = tagihan manual untuk bisnis tanpa paket
 * @property string|null $plan_name
 * @property BillingCycle|null $billing_cycle
 * @property string|null $title judul tagihan manual
 * @property string $amount total akhir (setelah diskon & PPN)
 * @property string $discount_amount
 * @property string $tax_rate PPN persen
 * @property string $tax_amount
 * @property string|null $memo catatan untuk pelanggan (tagihan manual)
 * @property InvoiceStatus $status
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string|null $paid_via midtrans / manual
 * @property InvoicePaymentMethod|null $payment_method metode pilihan owner (Q123)
 * @property string|null $proof_path bukti transfer manual (disk local/privat)
 * @property string|null $proof_sender nama pengirim / bank asal
 * @property string|null $proof_note
 * @property CarbonImmutable|null $proof_submitted_at null = belum ada bukti yang menunggu verifikasi
 * @property string|null $proof_submitted_by user owner
 * @property string|null $proof_rejected_reason alasan admin menolak bukti terakhir
 * @property CarbonImmutable|null $proof_rejected_at
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
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'payment_method' => InvoicePaymentMethod::class,
            'proof_submitted_at' => 'immutable_datetime',
            'proof_rejected_at' => 'immutable_datetime',
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

    /** Subtotal baris (sebelum diskon & PPN). */
    public function subtotal(): string
    {
        return Money::add(...$this->items->pluck('amount')->map(fn (mixed $a): string => (string) $a)->all());
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Pending && $this->due_at->isPast() && ! $this->awaitingVerification();
    }

    /** Bukti transfer manual sudah dikirim dan menunggu verifikasi admin (Q123). */
    public function awaitingVerification(): bool
    {
        return $this->status === InvoiceStatus::Pending && $this->proof_submitted_at !== null;
    }

    /** Masih bisa dibayar owner: menunggu, belum jatuh tempo, dan bukti tidak sedang diverifikasi. */
    public function isPayable(): bool
    {
        return $this->status === InvoiceStatus::Pending && $this->due_at->isFuture() && $this->proof_submitted_at === null;
    }

    /** Label status untuk owner & admin: "Menunggu verifikasi" bila bukti sedang dicek. */
    public function statusLabel(): string
    {
        return $this->awaitingVerification() ? 'Menunggu verifikasi' : $this->status->getLabel();
    }

    public function statusColor(): string
    {
        return $this->awaitingVerification() ? 'info' : $this->status->getColor();
    }

    /**
     * Uraian singkat untuk tabel & email: "Perpanjangan Pro · Bulanan + Outlet tambahan ×2" / "Add-on: Self-order QR" /
     * "Biaya instalasi · 3 item" (manual).
     */
    public function description(): string
    {
        $addons = $this->items->where('type', SubscriptionInvoiceItem::ADDON)
            ->map(fn (SubscriptionInvoiceItem $i): string => $i->name.($i->quantity > 1 ? ' ×'.$i->quantity : ''))->implode(', ');

        if ($this->kind === InvoiceKind::Addon) {
            return 'Add-on: '.$addons;
        }

        if ($this->kind === InvoiceKind::Manual) {
            $count = $this->items->count();

            return ($this->title ?? 'Tagihan manual').($count > 1 ? " · {$count} item" : '');
        }

        return $this->kind->getLabel().' '.$this->plan_name.' · '.$this->billing_cycle?->getLabel().($addons !== '' ? ' + '.$addons : '');
    }
}
