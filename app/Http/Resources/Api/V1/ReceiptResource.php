<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Payment;
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
            ],
            'order_id' => $this->id,
            'order_number' => $this->order_number,
            'order_type' => $this->order_type->value,
            'order_type_label' => $this->order_type->getLabel(),
            'table_label' => $this->table_label,
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
}
