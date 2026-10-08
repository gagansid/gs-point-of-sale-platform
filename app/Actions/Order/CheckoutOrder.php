<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Auth\VerifyApproval;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\ResolvedLine;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Order\CalculationResult;
use App\Services\Order\CalculatorLine;
use App\Services\Order\OrderCalculator;
use App\Services\Order\OrderNumberGenerator;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Buat order + bayar sekaligus (POST /checkout, alur takeaway/retail).
 *
 * Server sumber kebenaran: harga dari katalog, total dari OrderCalculator, nominal kiriman
 * aplikasi hanya dipakai sebagai uang yang dibayarkan. ID order & payment dari aplikasi adalah
 * idempotency key: request ganda mengembalikan order yang sama tanpa memotong stok lagi.
 */
final class CheckoutOrder
{
    public function __construct(
        private readonly ResolveOrderLines $resolveLines,
        private readonly AllocatePayments $allocatePayments,
        private readonly VerifyApproval $verifyApproval,
        private readonly OrderCalculator $calculator,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * @return array{order: Order, replayed: bool}
     *
     * @throws BusinessException
     */
    public function handle(User $actor, Device $device, CheckoutData $data): array
    {
        if (($existing = Idempotency::existing(Order::class, $data->id)) !== null) {
            return ['order' => $this->load($existing), 'replayed' => true];
        }

        $this->ensurePaymentIdsUnused($data);

        $shift = Shift::query()->where('open_device_key', $device->id)->first()
            ?? throw BusinessException::of(ErrorCode::ShiftNotOpen, 'Shift belum dibuka di perangkat ini');
        $outlet = Outlet::query()->findOrFail($shift->outlet_id);

        $lines = $this->resolveLines->handle($data->items);
        $totals = $this->calculate($lines, $data, $outlet);
        $approvedBy = $this->checkDiscountLimit($actor, $outlet, $totals, $data);
        $payments = $this->allocatePayments->handle($data->payments, $totals->grandTotal);

        $paid = Money::add(...array_column($payments, 'amount'));
        if (Money::compare($paid, $totals->grandTotal) < 0) {
            $remaining = Money::sub($totals->grandTotal, $paid);
            throw BusinessException::of(ErrorCode::ValidationError, 'Pembayaran kurang', [
                'payments' => ['Pembayaran kurang '.Money::format($remaining)],
                'remaining' => $remaining,
            ]);
        }

        try {
            $order = DB::transaction(fn (): Order => $this->persist($actor, $device, $shift, $outlet, $data, $lines, $totals, $payments, $approvedBy));
        } catch (UniqueConstraintViolationException) {
            // Request kembar lolos pengecekan awal bersamaan; primary key menjadi penjaga terakhir
            return ['order' => $this->load(Order::query()->findOrFail($data->id)), 'replayed' => true];
        }

        return ['order' => $this->load($order), 'replayed' => false];
    }

    /**
     * @param  list<ResolvedLine>  $lines
     */
    private function calculate(array $lines, CheckoutData $data, Outlet $outlet): CalculationResult
    {
        return $this->calculator->calculate(
            array_map(fn (ResolvedLine $l): CalculatorLine => new CalculatorLine($l->product->price, $l->optionsTotal, $l->qty, $l->discount), $lines),
            $data->discountType,
            $data->discountValue,
            $outlet->service_charge_rate,
            $outlet->tax_rate,
            $outlet->tax_inclusive,
            $outlet->rounding,
        );
    }

    /**
     * Diskon di atas batas role (outlets.discount_limits, persen dari harga kotor) butuh
     * permission order.discount_over_limit atau PIN approver.
     */
    private function checkDiscountLimit(User $actor, Outlet $outlet, CalculationResult $totals, CheckoutData $data): ?string
    {
        if (! Money::isPositive($totals->discountTotal)) {
            return null;
        }

        $limits = $outlet->discount_limits ?? config('pos.outlet_defaults.discount_limits');
        $limit = (string) ($limits[$actor->role->value] ?? 0);
        $percent = $totals->discountPercent();

        if (bccomp($percent, $limit, 2) <= 0) {
            return null;
        }

        return $this->verifyApproval->handle(
            $actor,
            'order.discount_over_limit',
            $data->approval,
            ErrorCode::DiscountOverLimit,
            ['limit_percent' => $limit, 'discount_percent' => $percent],
        );
    }

    private function ensurePaymentIdsUnused(CheckoutData $data): void
    {
        $ids = array_column($data->payments, 'id');
        $used = Payment::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('id')->all();

        if ($used !== []) {
            $errors = [];
            foreach ($ids as $index => $id) {
                if (in_array($id, $used, true)) {
                    $errors["payments.{$index}.id"] = ['ID pembayaran sudah dipakai'];
                }
            }

            throw BusinessException::of(ErrorCode::ValidationError, details: $errors);
        }
    }

    /**
     * @param  list<ResolvedLine>  $lines
     * @param  list<array{id: string, method: PaymentMethod, category: PaymentCategory, amount: string, tendered: string, change: string, reference: string|null}>  $payments
     */
    private function persist(User $actor, Device $device, Shift $shift, Outlet $outlet, CheckoutData $data, array $lines, CalculationResult $totals, array $payments, ?string $approvedBy): Order
    {
        $order = new Order([
            'outlet_id' => $outlet->id,
            'shift_id' => $shift->id,
            'device_id' => $device->id,
            'user_id' => $actor->id,
            'order_type' => $data->orderType,
            'table_label' => $data->tableLabel,
            'notes' => $data->notes,
        ]);
        $order->id = $data->id;
        $order->forceFill([
            'order_number' => $this->numbers->next($outlet),
            'status' => OrderStatus::Completed,
            'discount_type' => $data->discountType,
            'discount_value' => $data->discountType !== null ? $data->discountValue : null,
            'order_discount' => $totals->orderDiscount,
            'subtotal' => $totals->subtotal,
            'discount_total' => $totals->discountTotal,
            'service_total' => $totals->serviceTotal,
            'tax_total' => $totals->taxTotal,
            'rounding' => $totals->rounding,
            'grand_total' => $totals->grandTotal,
            'paid_total' => Money::add(...array_column($payments, 'amount')),
            'change_total' => Money::add(...array_column($payments, 'change')),
            'service_rate' => $outlet->service_charge_rate,
            'tax_rate' => $outlet->tax_rate,
            'tax_inclusive' => $outlet->tax_inclusive,
            'approved_by' => $approvedBy,
            'completed_at' => now(),
        ])->save();

        foreach ($lines as $position => $line) {
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $line->product->id,
                'product_name' => $line->product->name,
                'unit_price' => $line->product->price,
                'options_total' => $line->optionsTotal,
                'qty' => $line->qty,
                'discount' => $totals->lines[$position]['discount'],
                'line_total' => $totals->lines[$position]['line_total'],
                'notes' => $line->notes,
                'sort_order' => $position,
            ]);

            foreach ($line->options as $option) {
                OrderItemOption::query()->create([
                    'order_item_id' => $item->id,
                    'option_id' => $option->id,
                    'option_name' => $option->name,
                    'price_delta' => $option->price_delta,
                ]);
            }

            $this->deductStock($line, $order, $actor);
        }

        foreach ($payments as $payment) {
            $model = new Payment([
                'order_id' => $order->id,
                'payment_method_id' => $payment['method']->id,
                'user_id' => $actor->id,
                'category' => $payment['category'],
                'amount' => $payment['amount'],
                'tendered' => $payment['tendered'],
                'change' => $payment['change'],
                'reference' => $payment['reference'],
                'status' => PaymentStatus::Paid,
            ]);
            $model->id = $payment['id'];
            $model->save();
        }

        return $order;
    }

    /** Stok berkurang tepat saat order selesai; boleh minus (SPEC Stok). */
    private function deductStock(ResolvedLine $line, Order $order, User $actor): void
    {
        if (! $line->product->track_stock) {
            return;
        }

        // Update atomik + kunci baris: penjualan bersamaan tidak saling menimpa
        $product = Product::query()->lockForUpdate()->findOrFail($line->product->id);
        $product->stock_qty -= $line->qty;
        $product->save();

        StockMovement::query()->create([
            'product_id' => $product->id,
            'user_id' => $actor->id,
            'type' => StockMovementType::Sale,
            'qty_change' => -$line->qty,
            'qty_after' => $product->stock_qty,
            'reference_id' => $order->id,
        ]);
    }

    private function load(Order $order): Order
    {
        return $order->load(['items.options', 'payments.method']);
    }
}
