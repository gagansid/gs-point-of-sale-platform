<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\PriceOrder;
use App\Actions\Order\ResolveOrderLines;
use App\Actions\Order\VoidOrder;
use App\Actions\Shift\CloseShift;
use App\Actions\Shift\OpenShift;
use App\Enums\PaymentCategory;
use App\Models\Device;
use App\Models\Option;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shift\ShiftSummary;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Transaksi contoh 7 hari terakhir untuk melihat beranda & laporan di lokal. Dibuat lewat
 * Action yang sama dengan API (CheckoutOrder, CloseShift, VoidOrder) agar angkanya realistis.
 */
final class DemoTransactionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoTransactionsSeeder hanya untuk environment local/testing');
        }

        $tenant = Tenant::query()->where('slug', 'kopi-senja-demo')->firstOrFail();

        TenantContext::run($tenant->id, function (): void {
            if (Order::query()->exists()) {
                return;
            }

            $device = Device::query()->firstOrFail();
            $cashier = User::query()->where('name', 'Budi')->firstOrFail();
            $supervisor = User::query()->where('name', 'Sari')->firstOrFail();
            $products = Product::query()->with('optionGroups.options')->where('is_active', true)->get();
            $methods = PaymentMethod::query()->get()->keyBy(fn (PaymentMethod $m) => $m->category->value);

            mt_srand(42); // data demo yang sama setiap kali di-seed

            // Dihitung sekali: setelah setTestNow di dalam loop, now() menunjuk waktu tiruan
            $today = Carbon::now('Asia/Jakarta')->setTime(8, 0);

            for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
                $day = $today->copy()->subDays($daysAgo);
                Carbon::setTestNow($day->copy()->utc());
                $shift = app(OpenShift::class)->handle($cashier, $device, '200000.00')['shift'];

                $orders = mt_rand(4, 9);
                for ($i = 0; $i < $orders; $i++) {
                    Carbon::setTestNow($day->copy()->addMinutes(30 + $i * 50)->utc());
                    $order = $this->checkout($cashier, $device, $products, $methods);

                    if ($daysAgo === 0 && $i === 1) {
                        app(VoidOrder::class)->handle($supervisor, $order, 'Contoh void: salah input', null);
                    }
                }

                if ($daysAgo > 0) {
                    Carbon::setTestNow($day->copy()->setTime(21, 0)->utc());
                    $expected = app(ShiftSummary::class)->for($shift)['expected_cash'];
                    app(CloseShift::class)->handle($cashier, $shift, $expected, null);
                }
            }

            Carbon::setTestNow();
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  Collection<string, PaymentMethod>  $methods
     */
    private function checkout(User $cashier, Device $device, $products, $methods): Order
    {
        $items = [];
        foreach ($products->random(mt_rand(1, 3)) as $product) {
            $optionIds = [];
            foreach ($product->optionGroups as $group) {
                if ($group->min_select > 0) {
                    /** @var Option $option */
                    $option = $group->options->random();
                    $optionIds[] = $option->id;
                }
            }
            $items[] = ['product_id' => $product->id, 'qty' => mt_rand(1, 2), 'option_ids' => $optionIds];
        }

        $category = [PaymentCategory::Cash, PaymentCategory::Cash, PaymentCategory::Qris, PaymentCategory::Debit][mt_rand(0, 3)];
        $method = $methods[$category->value];

        $data = CheckoutData::fromArray([
            'id' => (string) Str::uuid7(),
            'order_type' => mt_rand(0, 1) === 1 ? 'dine_in' : 'takeaway',
            'items' => $items,
            // Nominal besar: tunai dikembalikan, non-tunai dibayar pas setelah total diketahui
            'payments' => [['id' => (string) Str::uuid7(), 'payment_method_id' => $methods['cash']->id, 'amount' => 500000, 'tendered' => 500000]],
        ]);

        if ($category !== PaymentCategory::Cash) {
            $outlet = $device->outlet()->firstOrFail();
            $preview = app(PriceOrder::class)->handle(
                $cashier,
                $outlet,
                app(ResolveOrderLines::class)->handle($data->items, $outlet),
                null,
                '0.00',
                null,
            )['totals'];

            $data = CheckoutData::fromArray([
                'id' => $data->id,
                'order_type' => $data->orderType->value,
                'items' => $items,
                'payments' => [[
                    'id' => (string) Str::uuid7(),
                    'payment_method_id' => $method->id,
                    'amount' => $preview->grandTotal,
                    'reference' => $method->requires_reference ? 'DEMO'.mt_rand(1000, 9999) : null,
                ]],
            ]);
        }

        return app(CheckoutOrder::class)->handle($cashier, $device, $data)['order'];
    }
}
