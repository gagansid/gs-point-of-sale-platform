<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Promotion\SavePromotion;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveSupplier;
use App\Enums\ReportTab;
use App\Exports\ReportSectionBuilder;
use App\Exports\ReportWorkbookExport;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Dashboard\Resources\Members\Pages\ManageMembers;
use App\Filament\Dashboard\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Dashboard\Resources\Promotions\Pages\ManagePromotions;
use App\Models\Category;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Report\ReportContext;
use App\Services\Report\ReportExporter;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Sapuan isolasi tenant (fase 3): satu hari kerja di tenant A dan tenant B (order selesai, open bill, retur,
 * kas keluar, member, stok masuk, promo, pesanan meja). Owner A lalu mencoba SETIAP ID milik B lewat API,
 * dashboard, daftar, laporan, dan export. Semua nama di tenant B berawalan "RAHASIA-B-" sebagai penanda bocor.
 */

/** Tabel bisnis yang dibandingkan sebelum/sesudah sapuan (tidak boleh ada perubahan di tenant mana pun). */
const ISW_TABLES = [
    'orders', 'order_items', 'order_item_options', 'payments', 'order_refunds', 'order_refund_items', 'shifts',
    'cash_movements', 'products', 'product_stocks', 'stock_movements', 'categories', 'option_groups', 'members',
    'users', 'payment_methods', 'self_orders', 'stock_receipts', 'suppliers', 'promotions', 'outlets', 'devices',
    'receivable_entries', 'member_point_entries',
];

function iswApi(object $test, string $method, string $uri, array $body, string $token): TestResponse
{
    freshAuth();

    return $test->withToken($token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/**
 * Satu tenant lengkap dengan penanda unik `RAHASIA-{tag}-...`.
 * Croissant 24.000 × 2 = 48.000 → service 5% 2.400 → pajak 11% 5.544 → 55.944 → dibulatkan 100 = 55.900.
 */
function iswTenant(object $test, string $tag, string $outletCode): stdClass
{
    Carbon::setTestNow('2026-10-16 03:00:00'); // 10.00 WIB
    $pos = posSetup();
    $m = "RAHASIA-{$tag}";
    $pos->marker = $m;

    TenantContext::run($pos->tenantId, function () use ($pos, $m, $outletCode): void {
        $pos->outlet->update(['name' => "{$m}-Outlet", 'code' => $outletCode]);
        $pos->device->forceFill(['device_uid' => 'kasir-'.strtolower($outletCode)])->save();
        $pos->croissant->update(['name' => "{$m}-Roti", 'barcode' => 'BC'.$outletCode]);
        $pos->coffee->update(['name' => "{$m}-Kopi"]);
        $pos->size->update(['name' => "{$m}-Ukuran"]);
        $pos->cashier->update(['name' => "{$m}-Budi"]);
        $pos->methods['qris']->update(['name' => "{$m}-QRIS"]);
        $pos->category = Category::factory()->create(['name' => "{$m}-Kategori"]);
        $pos->croissant->update(['category_id' => $pos->category->id]);

        app(UpdateMemberProgram::class)->handle(true, [$pos->outlet->id]);
        $pos->member = Member::factory()->create(['name' => "{$m}-Member"]);
        $pos->owner = User::factory()->owner()->create(['tenant_id' => $pos->tenantId, 'name' => "{$m}-Owner"]);
        $pos->promotion = app(SavePromotion::class)->handle(null, ['name' => "{$m}-Promo", 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '1']);
        $pos->supplier = app(SaveSupplier::class)->handle(null, ['name' => "{$m}-Supplier"]);
    });
    $pos->ownerToken = userToken($pos->owner, $pos->device);

    // Kasir: transaksi selesai dengan member (55.900 tunai)
    $pos->order = iswApi($test, 'POST', '/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $pos->member->id,
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 2]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => '100000']],
    ], $pos->ownerToken)->assertCreated()->json('data');

    // Open bill meja
    $pos->billId = uuid();
    iswApi($test, 'PUT', "/orders/{$pos->billId}", [
        'order_type' => 'dine_in', 'table_label' => "{$m}-Meja",
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1], ['product_id' => $pos->coffee->id, 'qty' => 1, 'option_ids' => [$pos->regular->id]]],
    ], $pos->ownerToken)->assertCreated();

    [$pos->orderItemId, $pos->billItemId, $pos->paymentId] = TenantContext::run($pos->tenantId, fn (): array => [
        OrderItem::query()->where('order_id', $pos->order['id'])->value('id'),
        OrderItem::query()->where('order_id', $pos->billId)->value('id'),
        DB::table('payments')->where('order_id', $pos->order['id'])->value('id'),
    ]);

    // Retur 1 croissant (tunai) & kas keluar operasional
    $pos->refundId = iswApi($test, 'POST', "/orders/{$pos->order['id']}/refunds", [
        'id' => uuid(), 'reason' => "{$m}-Retur", 'method' => 'cash', 'items' => [['order_item_id' => $pos->orderItemId, 'qty' => 1]],
    ], $pos->ownerToken)->assertCreated()->json('data.id');
    $pos->cashMovementId = uuid();
    iswApi($test, 'POST', '/cash-movements', [
        'id' => $pos->cashMovementId, 'type' => 'out', 'reason' => 'other', 'amount' => '5000', 'note' => "{$m}-Kas",
    ], $pos->ownerToken)->assertCreated();

    TenantContext::run($pos->tenantId, function () use ($pos, $m): void {
        // Stok masuk diposting + satu draf
        $pos->receipt = app(SaveStockReceipt::class)->handle(null, $pos->outlet, [
            'id' => uuid(), 'supplier_id' => $pos->supplier->id, 'received_on' => '2026-10-16', 'invoice_number' => "{$m}-INV",
            'items' => [['product_id' => $pos->croissant->id, 'qty' => 5, 'unit_cost' => '9000']],
        ]);
        app(PostStockReceipt::class)->handle($pos->receipt, $pos->owner);
        $pos->draftReceipt = app(SaveStockReceipt::class)->handle(null, $pos->outlet, [
            'id' => uuid(), 'supplier_id' => $pos->supplier->id, 'received_on' => '2026-10-16', 'invoice_number' => "{$m}-DRAF",
            'items' => [['product_id' => $pos->croissant->id, 'qty' => 1, 'unit_cost' => '9000']],
        ]);

        // Pesanan meja dari HP pelanggan (menunggu kasir); program member aktif = atas nama member (ADR 0027 K1)
        app(UpdateSelfOrderSettings::class)->handle($pos->outlet, ['enabled' => true]);
        $table = app(SaveDiningTable::class)->handle(null, $pos->outlet, ['name' => "{$m}-MejaQR"]);
        RateLimiter::clear('self-order-ip:127.0.0.1');
        $pos->selfOrder = app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($table->qr_token), [
            'id' => uuid(), 'customer_name' => "{$m}-Andi",
            'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        ], $pos->member, '127.0.0.1')['self_order'];
    });
    TenantContext::forget();
    CurrentOutlet::forget();

    $pos->ids = [
        'order' => $pos->order['id'], 'order_number' => $pos->order['order_number'], 'order_item' => $pos->orderItemId,
        'bill' => $pos->billId, 'bill_item' => $pos->billItemId, 'payment' => $pos->paymentId, 'refund' => $pos->refundId,
        'shift' => $pos->shift->id, 'cash_movement' => $pos->cashMovementId, 'product' => $pos->croissant->id,
        'barcode' => 'BC'.$outletCode, 'category' => $pos->category->id, 'option_group' => $pos->size->id,
        'member' => $pos->member->id, 'user' => $pos->cashier->id, 'payment_method' => $pos->methods['qris']->id,
        'cash_method' => $pos->methods['cash']->id, 'self_order' => $pos->selfOrder->id, 'outlet' => $pos->outlet->id,
        'receipt' => $pos->receipt->id, 'draft_receipt' => $pos->draftReceipt->id, 'promotion' => $pos->promotion->id,
        'regular' => $pos->regular->id,
    ];

    return $pos;
}

/** Ganti `{new}` (UUID baru), `{a:key}` / `{b:key}` di string (rekursif untuk body) dengan ID tenant A / B. */
function iswFill(mixed $value, stdClass $a, stdClass $b): mixed
{
    if (is_array($value)) {
        return array_map(fn ($v) => iswFill($v, $a, $b), $value);
    }

    if ($value === '{new}') {
        return uuid();
    }

    return is_string($value)
        ? preg_replace_callback('/\{([ab]):(\w+)\}/', fn (array $x): string => (string) ($x[1] === 'a' ? $a : $b)->ids[$x[2]], $value)
        : $value;
}

/** @return array<string, array<int, array<string, mixed>>> */
function iswSnapshot(): array
{
    return collect(ISW_TABLES)->filter(fn (string $t): bool => Schema::hasTable($t))
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
        ->all();
}

beforeEach(function () {
    $this->a = iswTenant($this, 'A', 'JKT01');
    $this->b = iswTenant($this, 'B', 'BDG09');
    Carbon::setTestNow('2026-10-16 05:00:00');
});

afterEach(fn () => Carbon::setTestNow());

describe('API: ID tenant B di URL atau body', function () {
    it('menolak dengan 404/422 dan tidak mengubah data apa pun', function (string $method, string $uri, array $body, int $status, string $code) {
        $before = iswSnapshot();

        $response = iswApi($this, $method, iswFill($uri, $this->a, $this->b), iswFill($body, $this->a, $this->b), $this->a->ownerToken);

        assertApiError($response, $code, $status);
        expect($response->getContent())->not->toContain('RAHASIA-B')
            ->and(iswSnapshot())->toEqual($before);
    })->with([
        // Produk & katalog
        'GET products/barcode' => ['GET', '/products/barcode/{b:barcode}', [], 404, 'NOT_FOUND'],
        'PUT products' => ['PUT', '/products/{b:product}', ['name' => 'Bajak', 'price' => '1000'], 404, 'NOT_FOUND'],
        'DELETE products' => ['DELETE', '/products/{b:product}', [], 404, 'NOT_FOUND'],
        'POST products/stock' => ['POST', '/products/{b:product}/stock', ['qty_change' => 5, 'reason' => 'Bajak'], 404, 'NOT_FOUND'],
        'PATCH products/availability' => ['PATCH', '/products/{b:product}/availability', ['is_available' => false], 404, 'NOT_FOUND'],
        'PUT categories' => ['PUT', '/categories/{b:category}', ['name' => 'Bajak'], 404, 'NOT_FOUND'],
        'DELETE categories' => ['DELETE', '/categories/{b:category}', [], 404, 'NOT_FOUND'],
        'PUT option-groups' => ['PUT', '/option-groups/{b:option_group}', ['name' => 'Bajak'], 404, 'NOT_FOUND'],
        'DELETE option-groups' => ['DELETE', '/option-groups/{b:option_group}', [], 404, 'NOT_FOUND'],
        'POST products category_id B' => ['POST', '/products', ['name' => 'Baru', 'price' => '1000', 'category_id' => '{b:category}'], 422, 'VALIDATION_ERROR'],
        'POST products option_group_ids B' => ['POST', '/products', ['name' => 'Baru', 'price' => '1000', 'option_group_ids' => ['{b:option_group}']], 422, 'VALIDATION_ERROR'],
        // Shift & kas
        'POST shifts/close' => ['POST', '/shifts/{b:shift}/close', ['actual_cash' => '0'], 404, 'NOT_FOUND'],
        'GET shifts/summary' => ['GET', '/shifts/{b:shift}/summary', [], 404, 'NOT_FOUND'],
        'POST shifts/force-close' => ['POST', '/shifts/{b:shift}/force-close', ['actual_cash' => '0'], 404, 'NOT_FOUND'],
        'POST shifts/opening-cash' => ['POST', '/shifts/{b:shift}/opening-cash', ['opening_cash' => '1', 'reason' => 'Bajak'], 404, 'NOT_FOUND'],
        'GET shifts/cash-movements' => ['GET', '/shifts/{b:shift}/cash-movements', [], 404, 'NOT_FOUND'],
        'POST cash-movements id B' => ['POST', '/cash-movements', ['id' => '{b:cash_movement}', 'type' => 'in', 'reason' => 'other', 'amount' => '1000', 'note' => 'Bajak'], 404, 'NOT_FOUND'],
        // Order & pembayaran
        'POST checkout id B' => ['POST', '/checkout', ['id' => '{b:order}', 'order_type' => 'takeaway', 'items' => [['product_id' => '{a:product}', 'qty' => 1]], 'payments' => [['id' => '{a:payment}', 'payment_method_id' => '{a:cash_method}', 'amount' => '50000']]], 404, 'NOT_FOUND'],
        // order.md: payments[].id sudah dipakai → 422 (pesan sama untuk tenant sendiri maupun lain)
        'POST checkout payment id B' => ['POST', '/checkout', ['id' => '{new}', 'order_type' => 'takeaway', 'items' => [['product_id' => '{a:product}', 'qty' => 1]], 'payments' => [['id' => '{b:payment}', 'payment_method_id' => '{a:cash_method}', 'amount' => '50000']]], 422, 'VALIDATION_ERROR'],
        'POST checkout product B' => ['POST', '/checkout', ['id' => '{new}', 'order_type' => 'takeaway', 'items' => [['product_id' => '{b:product}', 'qty' => 1]], 'payments' => [['id' => '{new}', 'payment_method_id' => '{a:cash_method}', 'amount' => '50000']]], 422, 'VALIDATION_ERROR'],
        'POST checkout member B' => ['POST', '/checkout', ['id' => '{new}', 'order_type' => 'takeaway', 'member_id' => '{b:member}', 'items' => [['product_id' => '{a:product}', 'qty' => 1]], 'payments' => [['id' => '{new}', 'payment_method_id' => '{a:cash_method}', 'amount' => '50000']]], 404, 'MEMBER_NOT_FOUND'],
        'POST checkout payment method B' => ['POST', '/checkout', ['id' => '{new}', 'order_type' => 'takeaway', 'items' => [['product_id' => '{a:product}', 'qty' => 1]], 'payments' => [['id' => '{new}', 'payment_method_id' => '{b:cash_method}', 'amount' => '50000']]], 422, 'VALIDATION_ERROR'],
        'POST orders/quote member B' => ['POST', '/orders/quote', ['member_id' => '{b:member}', 'items' => [['product_id' => '{a:product}', 'qty' => 1]]], 404, 'MEMBER_NOT_FOUND'],
        'PUT orders (open bill B)' => ['PUT', '/orders/{b:bill}', ['order_type' => 'dine_in', 'items' => [['product_id' => '{a:product}', 'qty' => 1]]], 404, 'NOT_FOUND'],
        'GET orders' => ['GET', '/orders/{b:order}', [], 404, 'NOT_FOUND'],
        'POST orders/payments' => ['POST', '/orders/{b:bill}/payments', ['payments' => [['id' => '{new}', 'payment_method_id' => '{a:cash_method}', 'amount' => '100000']]], 404, 'NOT_FOUND'],
        // order.md: payments[].id yang sudah dipakai (tenant mana pun) → 422 dengan pesan sama, tanpa membocorkan pemilik
        'POST orders/payments payment id B' => ['POST', '/orders/{a:bill}/payments', ['payments' => [['id' => '{b:payment}', 'payment_method_id' => '{a:cash_method}', 'amount' => '100000']]], 422, 'VALIDATION_ERROR'],
        'GET orders/receipt' => ['GET', '/orders/{b:order}/receipt', [], 404, 'NOT_FOUND'],
        'POST orders/void' => ['POST', '/orders/{b:order}/void', ['reason' => 'Bajak'], 404, 'NOT_FOUND'],
        'POST orders/refunds' => ['POST', '/orders/{b:order}/refunds', ['id' => '{new}', 'reason' => 'Bajak', 'method' => 'manual', 'items' => [['order_item_id' => '{b:order_item}', 'qty' => 1]]], 404, 'NOT_FOUND'],
        'POST orders/refunds id B' => ['POST', '/orders/{a:order}/refunds', ['id' => '{b:refund}', 'reason' => 'Bajak', 'method' => 'manual', 'items' => [['order_item_id' => '{a:order_item}', 'qty' => 1]]], 404, 'NOT_FOUND'],
        'POST orders/split' => ['POST', '/orders/{b:bill}/split', ['new_order_id' => '{new}', 'items' => [['order_item_id' => '{b:bill_item}', 'qty' => 1]]], 404, 'NOT_FOUND'],
        'POST orders/split new id B' => ['POST', '/orders/{a:bill}/split', ['new_order_id' => '{b:order}', 'items' => [['order_item_id' => '{a:bill_item}', 'qty' => 1]]], 404, 'NOT_FOUND'],
        'POST orders/merge' => ['POST', '/orders/{b:bill}/merge', ['source_order_id' => '{a:bill}'], 404, 'NOT_FOUND'],
        'POST orders/merge source B' => ['POST', '/orders/{a:bill}/merge', ['source_order_id' => '{b:bill}'], 404, 'NOT_FOUND'],
        'GET refundable-orders' => ['GET', '/refundable-orders/{b:order_number}', [], 404, 'NOT_FOUND'],
        // Member & voucher
        'GET members' => ['GET', '/members/{b:member}', [], 404, 'MEMBER_NOT_FOUND'],
        'GET members/points' => ['GET', '/members/{b:member}/points', [], 404, 'MEMBER_NOT_FOUND'],
        'GET members/receivables' => ['GET', '/members/{b:member}/receivables', [], 404, 'MEMBER_NOT_FOUND'],
        'POST members/receivable-payments' => ['POST', '/members/{b:member}/receivable-payments', ['id' => '{new}', 'amount' => '1000', 'payment_method_id' => '{a:cash_method}'], 404, 'MEMBER_NOT_FOUND'],
        'GET vouchers/check member B' => ['GET', '/vouchers/check?code=APAPUN&member_id={b:member}', [], 404, 'MEMBER_NOT_FOUND'],
        // Pesanan meja
        'POST self-orders/accept' => ['POST', '/self-orders/{b:self_order}/accept', [], 404, 'NOT_FOUND'],
        'POST self-orders/reject' => ['POST', '/self-orders/{b:self_order}/reject', ['reason' => 'Bajak'], 404, 'NOT_FOUND'],
        // Laporan
        'GET reports/shifts' => ['GET', '/reports/shifts/{b:shift}', [], 404, 'NOT_FOUND'],
        'GET reports/shifts/pdf' => ['GET', '/reports/shifts/{b:shift}/pdf', [], 404, 'NOT_FOUND'],
        // Setelan
        'GET users' => ['GET', '/users/{b:user}', [], 404, 'NOT_FOUND'],
        'PUT users' => ['PUT', '/users/{b:user}', ['name' => 'Bajak'], 404, 'NOT_FOUND'],
        'POST users/unlock-pin' => ['POST', '/users/{b:user}/unlock-pin', [], 404, 'NOT_FOUND'],
        'PUT payment-methods' => ['PUT', '/payment-methods/{b:payment_method}', ['name' => 'Bajak'], 404, 'NOT_FOUND'],
        'POST devices outlet B' => ['POST', '/devices', ['device_uid' => 'baru-1', 'name' => 'Tablet', 'outlet_id' => '{b:outlet}'], 422, 'VALIDATION_ERROR'],
    ]);
});

describe('API: daftar & laporan', function () {
    it('tidak memuat penanda atau ID tenant B', function (string $uri) {
        $response = iswApi($this, 'GET', iswFill($uri, $this->a, $this->b), [], $this->a->ownerToken)->assertOk();

        foreach (['RAHASIA-B', $this->b->ids['order'], $this->b->ids['product'], $this->b->ids['member'], $this->b->ids['shift'], $this->b->ids['outlet']] as $secret) {
            $response->assertDontSee($secret, false);
        }
    })->with([
        '/catalog', '/products', '/products?search=RAHASIA', '/orders', '/orders?search=RAHASIA', '/orders?shift_id={b:shift}',
        '/orders?status=open', '/members?search=RAHASIA', '/members?search=Member', '/self-orders', '/shifts/current', '/shifts/{a:shift}/cash-movements',
        '/users', '/payment-methods', '/outlets', '/outlet', '/auth/me',
        ...array_map(fn (string $r): string => "/reports/{$r}?from=2026-10-01&to=2026-10-16", [
            'summary', 'products', 'payment-methods', 'dashboard', 'hourly', 'audit', 'trend', 'categories', 'profit',
            'members', 'channels', 'purchases', 'options', 'employees', 'shifts', 'heatmap', 'stock',
        ]),
    ]);

    it('kontrol: owner B melihat penandanya sendiri di daftar & laporan yang sama', function (string $uri) {
        iswApi($this, 'GET', $uri, [], $this->b->ownerToken)->assertOk()->assertSee('RAHASIA-B', false);
    })->with(['/products', '/orders?status=open', '/members?search=RAHASIA', '/self-orders', '/users', '/payment-methods', '/reports/products?from=2026-10-01&to=2026-10-16']);
});

describe('dashboard: halaman record tenant B', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('dashboard');
        TenantContext::forget();
    });

    it('membuka view/edit record tenant B → 404', function (string $path) {
        $this->actingAs($this->a->owner, 'web')->get('/dashboard'.iswFill($path, $this->a, $this->b))->assertNotFound();
    })->with([
        '/orders/{b:order}', '/orders/{b:bill}', '/orders/{b:order}/receipt', '/shifts/{b:shift}', '/products/{b:product}/edit',
        '/option-groups/{b:option_group}/edit', '/stock/receipts/{b:receipt}', '/stock/receipts/{b:draft_receipt}/edit',
    ]);

    it('kontrol: record sendiri bisa dibuka', function (string $path) {
        $this->actingAs($this->a->owner, 'web')->get('/dashboard'.iswFill($path, $this->a, $this->b))->assertOk();
    })->with(['/orders/{a:order}', '/shifts/{a:shift}', '/products/{a:product}/edit', '/stock/receipts/{a:receipt}']);

    it('daftar tidak memuat penanda tenant B', function (string $path) {
        $this->actingAs($this->a->owner, 'web')->get('/dashboard'.$path)->assertOk()
            ->assertSee('RAHASIA-A', false)
            ->assertDontSee('RAHASIA-B', false);
    })->with([
        '/orders', '/shifts', '/products', '/members', '/settings/employees', '/settings/payment-methods', '/promotions',
        '/stock/receipts', '/stock/suppliers', '/settings/outlets', '/categories', '/option-groups',
    ]);

    it('aksi edit di halaman kelola tidak bisa menyentuh record tenant B', function (string $page, string $model, string $key, array $data) {
        TenantContext::set($this->a->tenantId);
        $this->actingAs($this->a->owner, 'web');
        CurrentOutlet::set($this->a->owner);
        $record = $model::allTenants()->findOrFail($this->b->ids[$key]);
        $before = iswSnapshot();

        try {
            Livewire::test($page)->callAction(TestAction::make('edit')->table($record), $data);
        } catch (Throwable) {
            // Record di luar query tabel boleh gagal dimuat; yang penting datanya tidak berubah
        }

        expect(iswSnapshot())->toEqual($before);
    })->with([
        'member' => [ManageMembers::class, Member::class, 'member', ['name' => 'Bajak', 'phone' => '081299990000']],
        'karyawan' => [ManageEmployees::class, User::class, 'user', ['name' => 'Bajak']],
        'metode bayar' => [ManagePaymentMethods::class, PaymentMethod::class, 'payment_method', ['name' => 'Bajak']],
        'promo' => [ManagePromotions::class, Promotion::class, 'promotion', ['name' => 'Bajak']],
    ]);
});

describe('export laporan', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('dashboard');
        TenantContext::set($this->a->tenantId);
        $this->actingAs($this->a->owner, 'web');
        CurrentOutlet::set($this->a->owner);
    });

    it('Excel lengkap dari halaman Laporan tidak memuat penanda tenant B', function () {
        Excel::fake();

        Livewire::test(Reports::class)->callAction('excel_full');

        Excel::assertDownloaded('laporan-lengkap-2026-10-01-2026-10-16.xlsx', function (ReportWorkbookExport $export): bool {
            $dump = json_encode(array_map(fn ($s) => method_exists($s, 'array') ? $s->array() : [], $export->sheets()));

            return str_contains((string) $dump, 'RAHASIA-A') && ! str_contains((string) $dump, 'RAHASIA-B');
        });
    });

    it('bagian PDF/Excel semua tab hanya berisi data tenant A', function () {
        $tabs = ReportExporter::tabsFor($this->a->owner, null);
        $sections = app(ReportSectionBuilder::class)->build($tabs, ReportContext::fromInput(['preset' => 'this_month'], $this->a->owner), 10000);
        $dump = (string) json_encode($sections);

        expect($tabs)->toContain(ReportTab::Products)
            ->and($dump)->toContain('RAHASIA-A-Roti')
            ->and($dump)->not->toContain('RAHASIA-B');
    });
});
