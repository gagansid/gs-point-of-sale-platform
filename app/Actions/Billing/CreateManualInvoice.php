<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Enums\InvoiceKind;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\Addon;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionInvoiceItem;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Notifications\InvoiceCreated;
use App\Services\Billing\ManualInvoiceTotals;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tagihan manual dari panel /admin (2026-10-19): kebutuhan di luar paket — biaya bebas (instalasi, pelatihan,
 * perangkat, kustomisasi) dan/atau add-on dengan harga khusus. Baris add-on dipasang saat lunas (MarkInvoicePaid),
 * baris biaya bebas tidak mengubah apa pun. Paket & masa aktif tetap. Tidak membatalkan tagihan menunggu lain.
 * Nominal dihitung ulang di server (ManualInvoiceTotals): diskon nominal, PPN persen dibulatkan ke rupiah.
 */
final class CreateManualInvoice
{
    public const MAX_LINES = 30;

    /**
     * @param  array<string, mixed>  $data  title, due_at (Y-m-d), items[], discount_amount, tax_rate, memo
     *
     * @throws BusinessException|ValidationException
     */
    public function handle(Tenant $tenant, Admin $admin, array $data, bool $notifyOwners = false): SubscriptionInvoice
    {
        CreateInvoice::ensureSaas();

        $v = Validator::make($data, [
            'title' => ['required', 'string', 'max:120'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.type' => ['required', Rule::in([SubscriptionInvoiceItem::CUSTOM, SubscriptionInvoiceItem::ADDON])],
            'items.*.addon_id' => ['nullable', 'required_if:items.*.type,'.SubscriptionInvoiceItem::ADDON, 'string', Rule::exists('addons', 'id')],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'discount_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'memo' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'title' => 'judul', 'due_at' => 'jatuh tempo', 'items' => 'rincian', 'items.*.name' => 'uraian',
            'items.*.quantity' => 'jumlah', 'items.*.unit_price' => 'harga satuan', 'items.*.addon_id' => 'add-on',
            'discount_amount' => 'diskon', 'tax_rate' => 'PPN', 'memo' => 'catatan',
        ])->validate();

        $items = array_values($v['items']);
        foreach ($items as $i => $item) {
            if ($item['type'] === SubscriptionInvoiceItem::ADDON) {
                $items[$i] = self::checkAddonLine($tenant, $item, $i);
            } else {
                $items[$i]['addon_id'] = null;
            }
        }

        $totals = ManualInvoiceTotals::of($items, $v['discount_amount'] ?? null, $v['tax_rate'] ?? null);
        if (bccomp($totals->discount, $totals->subtotal, 2) > 0) {
            throw self::invalid('discount_amount', 'Diskon melebihi subtotal');
        }
        if (bccomp($totals->total, '1', 2) < 0) {
            throw self::invalid('items', 'Total tagihan minimal Rp1');
        }
        if (bccomp($totals->total, Money::MAX, 2) > 0) {
            throw self::invalid('items', 'Total tagihan terlalu besar');
        }

        $invoice = DB::transaction(function () use ($tenant, $admin, $v, $totals): SubscriptionInvoice {
            $plan = $tenant->plan;
            $invoice = new SubscriptionInvoice([
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'billing_cycle' => $tenant->billing_cycle,
                'amount' => $totals->total,
                // Jatuh tempo akhir hari (zona waktu platform)
                'due_at' => CarbonImmutable::parse((string) $v['due_at'], (string) config('pos.default_timezone'))->endOfDay()->utc(),
            ]);
            $invoice->tenant_id = $tenant->id;
            $invoice->forceFill([
                'number' => 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6)),
                'kind' => InvoiceKind::Manual,
                'title' => trim((string) $v['title']),
                'discount_amount' => $totals->discount,
                'tax_rate' => $totals->taxRate,
                'tax_amount' => $totals->tax,
                'memo' => filled($v['memo'] ?? null) ? trim((string) $v['memo']) : null,
                'created_by_admin_id' => $admin->id,
            ])->save();

            foreach ($totals->lines as $index => $line) {
                $invoice->items()->create([...$line, 'sort_order' => $index]);
            }

            return $invoice->load('items');
        });

        Log::info('Tagihan manual dibuat', ['invoice_id' => $invoice->id, 'tenant_id' => $tenant->id, 'admin_id' => $admin->id, 'amount' => $invoice->amount]);

        // Setelah commit: email gagal tidak membatalkan tagihan
        if ($notifyOwners) {
            User::allTenants()->where('tenant_id', $tenant->id)->where('role', UserRole::Owner)->where('is_active', true)
                ->whereNotNull('email')->get()
                ->each(fn (User $owner) => $owner->notify(new InvoiceCreated($invoice, $tenant)));
        }

        return $invoice;
    }

    /**
     * Baris add-on: aktif; fitur = 1 unit, belum termasuk paket & belum aktif. Nama kosong = nama add-on.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function checkAddonLine(Tenant $tenant, array $item, int $index): array
    {
        $addon = Addon::query()->findOrFail((string) $item['addon_id']);
        $field = "items.{$index}.addon_id";

        if (! $addon->is_active) {
            throw self::invalid($field, "Add-on {$addon->name} nonaktif");
        }

        if (! $addon->isQuota()) {
            if ((int) $item['quantity'] !== 1) {
                throw self::invalid("items.{$index}.quantity", "Add-on fitur {$addon->name} hanya 1 unit");
            }
            if ($tenant->plan !== null && in_array($addon->feature_key, $tenant->plan->features, true)) {
                throw self::invalid($field, "Fitur {$addon->name} sudah termasuk paket {$tenant->plan->name}");
            }
            if (TenantAddon::forTenant($tenant->id)->where('addon_id', $addon->id)->where('status', TenantAddon::ACTIVE)->exists()) {
                throw self::invalid($field, "Add-on {$addon->name} sudah aktif");
            }
        }

        return [...$item, 'addon_id' => $addon->id, 'name' => filled($item['name'] ?? null) ? $item['name'] : $addon->name];
    }

    private static function invalid(string $field, string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, [$field => [$message]]);
    }
}
