<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Backfill migration unit_cost (Q53): transaksi lama diisi dari harga modal produk saat ini. */
it('mengisi unit_cost transaksi lama dari products.cost_price, produk tanpa modal tetap null', function () {
    $pos = posSetup();
    $migration = require database_path('migrations/2026_10_14_100000_add_unit_cost_to_order_items.php');

    $order = TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->coffee->id, 'qty' => 1, 'option_ids' => [$pos->large->id]], ['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 100000]],
    ]))['order']);
    DB::table('order_items')->update(['unit_cost' => null]);
    Product::allTenants()->whereKey($pos->coffee->id)->update(['cost_price' => '7000.00']);
    Product::allTenants()->whereKey($pos->croissant->id)->update(['cost_price' => null]);

    $migration->down();
    $migration->up();

    $items = OrderItem::allTenants()->where('order_id', $order->id)->get()->keyBy('product_id');
    expect($items->get($pos->coffee->id)?->unit_cost)->toBe('7000.00')
        ->and($items->get($pos->croissant->id)?->unit_cost)->toBeNull();
});
