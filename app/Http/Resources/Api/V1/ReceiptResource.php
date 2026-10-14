<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\StampEntryType;
use App\Models\MemberStamp;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Payment;
use App\Models\StampProgram;
use App\Services\Member\StampCards;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data struk siap cetak. Nilai dari snapshot order (tarif pajak/service saat transaksi), bukan
 * setelan outlet saat ini. Waktu juga diberikan dalam zona outlet agar printer tidak perlu konversi.
 *
 * @mixin Order
 */
final class ReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $outlet = $this->outlet;
        $local = $this->created_at?->copy()->setTimezone($outlet->timezone);

        return [
            'outlet' => [
                'name' => $outlet->name,
                'address' => $outlet->address,
                'header' => $outlet->receipt_header,
                'footer' => $outlet->receipt_footer,
                // Q48: false = baris pembulatan tidak dicetak (sudah termasuk di total)
                'show_rounding' => $outlet->receipt_show_rounding,
            ],
            'order_id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type->value,
            'order_type_label' => $this->order_type->getLabel(),
            'table_label' => $this->table_label,
            'member_name' => $this->member_name,
            'member_tier' => $this->member_tier,
            // Poin di struk (ADR 0013): didapat dari order ini & saldo saat struk dibuat
            'member_points' => $this->member_id !== null ? ['earned' => $this->points_earned, 'balance' => $this->member?->points_balance] : null,
            // Kartu stempel (M6): stempel didapat dari order ini & saldo per program saat struk dibuat
            'member_stamps' => $this->member_id !== null && $this->member !== null ? self::stamps($this->resource) : [],
            'status' => $this->status->value,
            // Struk void dicetak dengan tanda BATAL
            'is_void' => $this->status === OrderStatus::Voided,
            'void_reason' => $this->void_reason,
            'cashier_name' => $this->user->name,
            'created_at' => Iso::dateTime($this->created_at),
            'created_at_local' => $local?->format('d/m/Y H.i'),
            'lines' => $this->items->map(fn (OrderItem $item): array => [
                'name' => $item->product_name,
                'options' => $item->options->map(fn (OrderItemOption $o): string => $o->option_name)->values()->all(),
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'options_total' => $item->options_total,
                'discount' => $item->discount,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount_total' => $this->discount_total,
                // Bagian discount_total dari diskon member (ADR 0013)
                'member_discount' => $this->member_discount,
                'stamp_free_total' => $this->stamp_free_total,
                // Promo otomatis (ADR 0022): nominal & nama promo
                'promo_discount' => $this->promo_discount,
                'promotions' => array_map(fn (array $p): array => ['name' => $p['name'], 'amount' => $p['amount']], $this->promotions ?? []),
                'voucher_code' => $this->voucher_code,
                'voucher_discount' => $this->voucher_discount,
                'service_rate' => $this->service_rate,
                'service_total' => $this->service_total,
                'tax_rate' => $this->tax_rate,
                'tax_inclusive' => $this->tax_inclusive,
                'tax_total' => $this->tax_total,
                'rounding' => $this->rounding,
                'grand_total' => $this->grand_total,
                'paid_total' => $this->paid_total,
                'change_total' => $this->change_total,
            ],
            'payments' => $this->payments->map(fn (Payment $p): array => [
                'method' => $p->method->name,
                'category' => $p->category->value,
                'amount' => $p->amount,
                'tendered' => $p->tendered,
                'change' => $p->change,
                'reference' => $p->reference,
                'status' => $p->status->value,
            ])->values()->all(),
        ];
    }

    /**
     * Program stempel yang bergerak di order ini (didapat/ditukar) beserta saldo terkini.
     *
     * @return list<array{name: string, earned: int, balance: int, stamps_required: int}>
     */
    private static function stamps(Order $order): array
    {
        $changes = MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::Earn)
            ->pluck('change', 'stamp_program_id');
        $redeemed = array_column($order->stamp_redemptions ?? [], 'stamp_program_id');
        $ids = array_values(array_unique([...$changes->keys()->all(), ...$redeemed]));

        if ($ids === []) {
            return [];
        }

        $balances = app(StampCards::class)->balances($order->member);

        return StampProgram::query()->whereIn('id', $ids)->orderBy('name')->get()->map(fn (StampProgram $p): array => [
            'name' => $p->name,
            'earned' => (int) ($changes[$p->id] ?? 0),
            'balance' => $balances[$p->id] ?? 0,
            'stamps_required' => $p->stamps_required,
        ])->values()->all();
    }
}
