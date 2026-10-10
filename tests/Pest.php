<?php

declare(strict_types=1);

use App\Actions\Auth\IssueUserToken;
use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Models\Device;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Feature test memakai database bersih per test. Helper bersama sesuai docs/standards/testing.md §4.
 */
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Header wajib setiap request API dari aplikasi Flutter.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function apiHeaders(array $extra = []): array
{
    return ['Accept' => 'application/json', 'X-App-Version' => '1.0.0', ...$extra];
}

/**
 * Semua respons gagal wajib berbentuk envelope yang sama.
 */
function assertApiError(TestResponse $response, string $code, int $status): void
{
    $response->assertStatus($status)
        ->assertJsonStructure(['success', 'message', 'error' => ['code', 'details'], 'meta' => ['request_id']])
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', $code);
}

/** Token user siap pakai (seperti hasil login). */
function userToken(User $user, ?Device $device = null): string
{
    return app(IssueUserToken::class)
        ->handle($user, 'app:test-'.$user->id, now()->addDay(), $device)
        ->plainTextToken;
}

/** Device token siap pakai (seperti hasil POST /devices). */
function deviceToken(Device $device): string
{
    return $device->createToken('device', ['device'])->plainTextToken;
}

/**
 * Melupakan user yang sudah di-resolve guard, agar request berikutnya dalam satu test
 * membaca token dari awal (seperti request HTTP baru).
 */
function freshAuth(): void
{
    app('auth')->forgetGuards();
    // Satu instance aplikasi dipakai semua request di test: tenant request sebelumnya tidak boleh terbawa
    TenantContext::forget();
    CurrentOutlet::forget();
}

/**
 * Kasir siap bertransaksi: outlet JKT01 (pajak 11%, service 5%, pembulatan 100, batas diskon
 * kasir 10%), device, kasir login PIN di device, shift terbuka, katalog contoh, metode bayar bawaan.
 */
function posSetup(bool $openShift = true): stdClass
{
    TenantContext::forget();
    CurrentOutlet::forget();
    $pos = new stdClass;
    $pos->outlet = Outlet::factory()->create([
        'code' => 'JKT01', 'tax_rate' => '11.00', 'service_charge_rate' => '5.00',
        'tax_inclusive' => false, 'rounding' => 100,
        'discount_limits' => ['cashier' => 10, 'supervisor' => 25],
    ]);
    $pos->tenantId = $pos->outlet->tenant_id;
    $pos->device = Device::factory()->forOutlet($pos->outlet)->create(['device_uid' => 'kasir-1']);
    $pos->cashier = User::factory()->cashier()->forOutlet($pos->outlet)->create(['name' => 'Budi']);
    $pos->supervisor = User::factory()->supervisor()->forOutlet($pos->outlet)->create(['name' => 'Sari']);
    $pos->token = userToken($pos->cashier, $pos->device);

    TenantContext::run($pos->tenantId, function () use ($pos, $openShift): void {
        app(CreateDefaultPaymentMethods::class)->handle();
        $pos->methods = PaymentMethod::query()->get()->keyBy(fn ($m) => $m->category->value);

        $pos->size = OptionGroup::factory()->create(['name' => 'Ukuran', 'min_select' => 1, 'max_select' => 1]);
        $pos->regular = Option::factory()->create(['option_group_id' => $pos->size->id, 'tenant_id' => $pos->tenantId, 'name' => 'Regular', 'price_delta' => '0.00']);
        $pos->large = Option::factory()->create(['option_group_id' => $pos->size->id, 'tenant_id' => $pos->tenantId, 'name' => 'Large', 'price_delta' => '5000.00']);

        $pos->coffee = Product::factory()->create(['name' => 'Es Kopi Susu', 'price' => '22000.00']);
        $pos->coffee->optionGroups()->attach($pos->size->id, ['sort_order' => 0]);
        $pos->croissant = Product::factory()->tracked(10)->create(['name' => 'Croissant', 'price' => '24000.00']);

        if ($openShift) {
            $pos->shift = Shift::factory()->forDevice($pos->device, $pos->cashier)->create(['opening_cash' => '200000.00']);
        }
    });

    return $pos;
}

/** UUID v7 baru (seperti yang dibuat aplikasi Flutter). */
function uuid(): string
{
    return (string) Str::uuid7();
}

/**
 * Baris stok produk di satu outlet (ADR 0010); default outlet pertama tenant produk.
 * Tanpa baris = nilai bawaan (stok 0, tersedia).
 */
function stockOf(Product|string $product, ?string $outletId = null): ProductStock
{
    $product = $product instanceof Product ? $product : Product::allTenants()->withTrashed()->findOrFail($product);
    $outletId ??= Outlet::forTenant($product->tenant_id)->orderBy('created_at')->value('id');

    return ProductStock::forTenant($product->tenant_id)->where('outlet_id', $outletId)->where('product_id', $product->id)->first()
        ?? new ProductStock(['outlet_id' => $outletId, 'product_id' => $product->id]);
}
