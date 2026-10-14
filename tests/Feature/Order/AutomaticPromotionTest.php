<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Promotion\SavePromotion;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Promotions\Pages\ManagePromotions;
use App\Filament\Dashboard\Resources\Promotions\PromotionResource;
use App\Models\Category;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Promo otomatis (ADR 0022, Q102–Q104). Rabu 14 Okt 2026 15.00 WIB. Es Kopi Susu 22.000 (kategori Kopi),
 * Croissant 24.000; service 5%, pajak 11%, pembulatan 100; batas diskon kasir 10%.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 08:00:00');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->kopi = Category::factory()->create(['name' => 'Kopi']);
    $this->pos->coffee->forceFill(['category_id' => $this->kopi->id])->save();
});

afterEach(fn () => Carbon::setTestNow());

function autoPromo(array $data): Promotion
{
    return app(SavePromotion::class)->handle(null, ['name' => 'Promo', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '20', ...$data]);
}

/** @param list<array{0: string, 1: int}> $items */
function promoSale(object $test, array $items, array $override = [], ?User $cashier = null): Order
{
    return app(CheckoutOrder::class)->handle($cashier ?? $test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => array_map(fn (array $i): array => ['product_id' => $test->pos->{$i[0]}->id, 'qty' => $i[1], 'option_ids' => $i[0] === 'coffee' ? [$test->pos->regular->id] : []], $items),
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 500000]],
        ...$override,
    ]))['order'];
}

it('happy hour kategori: diskon per item, masuk snapshot & struk, total dihitung setelah promo', function () {
    autoPromo(['name' => 'Happy hour kopi', 'applies_to' => 'categories', 'category_ids' => [$this->kopi->id], 'days' => [3], 'time_start' => '14:00', 'time_end' => '17:00']);

    $order = promoSale($this, [['coffee', 2], ['croissant', 1]]);

    // Promo 2 × 4.400 = 8.800; dasar 68.000 − 8.800 = 59.200 → +5% 62.160 → +11% 68.997,6 → 69.000
    expect($order->promo_discount)->toBe('8800.00')
        ->and($order->promotions)->toBe([['promotion_id' => Promotion::query()->value('id'), 'name' => 'Happy hour kopi', 'amount' => '8800.00']])
        ->and($order->discount_total)->toBe('8800.00')
        ->and($order->grand_total)->toBe('69000.00')
        ->and($order->approved_by)->toBeNull();

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$order->id}/receipt", apiHeaders())
        ->assertJsonPath('data.totals.promo_discount', '8800.00')
        ->assertJsonPath('data.totals.promotions.0.name', 'Happy hour kopi');
});

it('jadwal: di luar jam, hari lain, outlet lain, sudah berakhir, atau nonaktif → tanpa promo', function (array $schedule) {
    autoPromo(array_map(fn (mixed $v) => $v === '{other}' ? [uuid()] : $v, $schedule));

    expect(promoSale($this, [['coffee', 1]])->promo_discount)->toBe('0.00');
})->with([
    'jam' => [['time_start' => '17:00', 'time_end' => '19:00']],
    'hari' => [['days' => [6, 7]]],
    'berakhir' => [['ends_on' => '2026-10-13']],
    'belum mulai' => [['starts_on' => '2026-10-15']],
    'nonaktif' => [['is_active' => false]],
]);

it('jam lewat tengah malam berlaku', function () {
    Carbon::setTestNow('2026-10-14 17:30:00'); // 00.30 WIB Kamis
    autoPromo(['time_start' => '22:00', 'time_end' => '02:00']);

    expect(promoSale($this, [['coffee', 1]])->promo_discount)->toBe('4400.00');
});

it('beli 2 gratis 1: unit termurah gratis per kelompok; baris berdiskon item tidak ikut', function () {
    autoPromo(['name' => 'Beli 2 gratis 1', 'type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1]);

    expect(promoSale($this, [['croissant', 3]])->promo_discount)->toBe('24000.00')
        ->and(promoSale($this, [['croissant', 5]])->promo_discount)->toBe('24000.00')
        // 2 croissant + 1 kopi: kopi termurah gratis
        ->and(promoSale($this, [['croissant', 2], ['coffee', 1]])->promo_discount)->toBe('22000.00');

    autoPromo(['name' => 'Diskon kopi', 'applies_to' => 'products', 'product_ids' => [$this->pos->coffee->id], 'discount_value' => '10']);
    // Kopi kena diskon item 2.200; sisa 2 croissant belum cukup untuk beli 2 gratis 1
    $order = promoSale($this, [['croissant', 2], ['coffee', 1]]);
    expect($order->promo_discount)->toBe('2200.00')->and(array_column($order->promotions, 'name'))->toBe(['Diskon kopi']);
});

it('diskon terbesar per baris; diskon minimal belanja satu terbesar dengan maks.', function () {
    autoPromo(['name' => '10%', 'discount_value' => '10']);
    autoPromo(['name' => 'Rp5.000', 'discount_type' => 'fixed', 'discount_value' => '5000']);
    autoPromo(['name' => 'Min 50rb', 'type' => 'order_discount', 'discount_type' => 'percent', 'discount_value' => '10', 'max_discount' => '3000', 'min_subtotal' => '50000']);
    autoPromo(['name' => 'Min 100rb', 'type' => 'order_discount', 'discount_type' => 'fixed', 'discount_value' => '20000', 'min_subtotal' => '100000']);

    // 2 croissant: per item Rp5.000 > 10% (2.400) → 10.000; sisa 38.000 < 50.000 → tanpa diskon order
    expect(promoSale($this, [['croissant', 2]])->promo_discount)->toBe('10000.00');

    // 3 croissant: 15.000; sisa 57.000 ≥ 50.000 → 10% = 5.700, maks. 3.000
    $order = promoSale($this, [['croissant', 3]]);
    expect($order->promo_discount)->toBe('18000.00')->and(array_column($order->promotions, 'name'))->toBe(['Rp5.000', 'Min 50rb']);
});

it('promo tidak masuk batas diskon kasir; diskon manual dihitung dari dasar setelah promo', function () {
    autoPromo(['discount_value' => '50']);

    // Kasir diskon order 10% (batas 10%) di atas promo 50% → tidak butuh PIN
    $order = promoSale($this, [['croissant', 1]], ['discount' => ['type' => 'percent', 'value' => 10]]);
    expect($order->promo_discount)->toBe('12000.00')->and($order->order_discount)->toBe('1200.00')->and($order->approved_by)->toBeNull();
});

it('API: katalog memuat promo aktif; quote menghitung promo tanpa menyimpan', function () {
    autoPromo(['name' => 'Happy hour kopi', 'applies_to' => 'categories', 'category_ids' => [$this->kopi->id]]);
    autoPromo(['name' => 'Lama', 'ends_on' => '2026-10-01']);
    TenantContext::forget();

    freshAuth();
    $this->withToken($this->pos->token)->getJson('/api/v1/catalog', apiHeaders())
        ->assertOk()->assertJsonCount(1, 'data.promotions')->assertJsonPath('data.promotions.0.name', 'Happy hour kopi');

    freshAuth();
    $this->withToken($this->pos->token)->postJson('/api/v1/orders/quote', [
        'items' => [['product_id' => $this->pos->coffee->id, 'qty' => 2, 'option_ids' => [$this->pos->regular->id]]],
    ], apiHeaders())->assertOk()
        ->assertJsonPath('data.promo_discount', '8800.00')
        ->assertJsonPath('data.promotions.0.name', 'Happy hour kopi');

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/orders/quote', ['items' => []], apiHeaders()), 'VALIDATION_ERROR', 422);
    expect(Order::allTenants()->count())->toBe(0);
});

it('validasi promo & dashboard: owner membuat promo, kasir tanpa menu', function () {
    expect(fn () => autoPromo(['discount_value' => '0']))->toThrow(BusinessException::class);
    expect(fn () => autoPromo(['discount_value' => '120']))->toThrow(BusinessException::class);
    expect(fn () => autoPromo(['type' => 'buy_get', 'buy_qty' => null]))->toThrow(BusinessException::class);
    expect(fn () => autoPromo(['applies_to' => 'products', 'product_ids' => []]))->toThrow(BusinessException::class);
    expect(fn () => autoPromo(['time_start' => '10:00']))->toThrow(BusinessException::class);

    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    Livewire::test(ManagePromotions::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Beli 2 gratis 1', 'type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'applies_to' => 'all'])
        ->assertHasNoActionErrors();
    expect(Promotion::query()->where('name', 'Beli 2 gratis 1')->value('buy_qty'))->toBe(2);

    $this->actingAs($this->pos->cashier);
    expect(PromotionResource::canViewAny())->toBeFalse();
});
