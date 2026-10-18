<?php

declare(strict_types=1);

use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SavePlan;
use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Promotion\SavePromotion;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Enums\BillingCycle;
use App\Enums\UserRole;
use App\Filament\Dashboard\Pages\SubscriptionSettings;
use App\Filament\Dashboard\Resources\Categories\CategoryResource;
use App\Filament\Dashboard\Resources\DiningTables\Pages\ManageDiningTables;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Dashboard\Resources\OptionGroups\OptionGroupResource;
use App\Filament\Dashboard\Resources\OptionGroups\Pages\ListOptionGroups;
use App\Filament\Dashboard\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Dashboard\Resources\Promotions\Pages\ManagePromotions;
use App\Filament\Dashboard\Resources\Promotions\PromotionResource;
use App\Filament\Dashboard\Resources\Shifts\Pages\ListShifts;
use App\Filament\Dashboard\Resources\Shifts\ShiftResource;
use App\Filament\Dashboard\Resources\StampPrograms\Pages\ManageStampPrograms;
use App\Filament\Dashboard\Resources\Vouchers\Pages\ManageVouchers;
use App\Models\Member;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Promotion;
use App\Models\Shift;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Kontrak dashboard (fase 3, celah G10 + izin + isolasi tenant).
 * Outlet posSetup(): Es Kopi Susu 22.000 (wajib Ukuran), Croissant 24.000; service 5%, pajak 11%, pembulatan Rp100.
 * Rabu 14 Okt 2026 08.00 UTC (15.00 WIB).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 08:00:00');
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['name' => 'Owner A']);
    tacAsOwner($this);
});

afterEach(fn () => Carbon::setTestNow());

/** Kembali ke sesi dashboard owner tenant A (setelah request API yang mereset guard & konteks). */
function tacAsOwner(object $test): void
{
    TenantContext::set($test->pos->tenantId);
    // Request API sebelumnya memindah guard bawaan ke sanctum: login dashboard eksplisit di guard web
    $test->flushHeaders();
    $test->actingAs($test->owner, 'web');
    CurrentOutlet::set($test->owner, $test->pos->outlet->id);
}

/** Request API kasir tenant A seperti dari aplikasi Flutter. */
function tacApi(object $test, string $method, string $uri, array $body = []): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

/** Body checkout tunai untuk satu baris item. */
function tacCheckoutBody(object $test, array $item, array $override = []): array
{
    return [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [$item],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 500000]],
        ...$override,
    ];
}

describe('G10 aksi aktif/nonaktif', function () {
    it('promo nonaktif tidak memotong quote, checkout, dan katalog; aktif lagi memotong kembali', function () {
        $promotion = app(SavePromotion::class)->handle(null, [
            'name' => 'Kopi 20%', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '20',
            'applies_to' => 'products', 'product_ids' => [$this->pos->coffee->id],
        ]);
        $coffee = ['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]];

        Livewire::test(ManagePromotions::class)->callAction(TestAction::make('deactivate')->table($promotion));
        expect($promotion->refresh()->is_active)->toBeFalse();

        // Tanpa promo: 22.000 + 5% 1.100 = 23.100 + 11% 2.541 = 25.641 → 25.700
        tacApi($this, 'POST', '/orders/quote', ['items' => [$coffee]])->assertOk()
            ->assertJsonPath('data.promo_discount', '0.00')
            ->assertJsonPath('data.promotions', [])
            ->assertJsonPath('data.grand_total', '25700.00');
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $coffee))->assertCreated()
            ->assertJsonPath('data.promo_discount', '0.00')
            ->assertJsonPath('data.grand_total', '25700.00');
        tacApi($this, 'GET', '/catalog')->assertOk()->assertJsonCount(0, 'data.promotions');

        tacAsOwner($this);
        Livewire::test(ManagePromotions::class)->callAction(TestAction::make('activate')->table($promotion));
        expect($promotion->refresh()->is_active)->toBeTrue();

        // 20% × 22.000 = 4.400 → 17.600 + 5% 880 = 18.480 + 11% 2.032,8 = 20.512,8 → 20.600
        tacApi($this, 'POST', '/orders/quote', ['items' => [$coffee]])->assertOk()
            ->assertJsonPath('data.promo_discount', '4400.00')
            ->assertJsonPath('data.grand_total', '20600.00');
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $coffee))->assertCreated()
            ->assertJsonPath('data.promo_discount', '4400.00')
            ->assertJsonPath('data.grand_total', '20600.00');
        tacApi($this, 'GET', '/catalog')->assertOk()->assertJsonPath('data.promotions.0.name', 'Kopi 20%');
    });

    it('voucher nonaktif ditolak VOUCHER_INVALID (inactive); aktif lagi bisa dipakai', function () {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $voucher = app(SaveVoucher::class)->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
        $croissant = ['product_id' => $this->pos->croissant->id, 'qty' => 1];

        Livewire::test(ManageVouchers::class)->callAction(TestAction::make('deactivate')->table($voucher));
        expect($voucher->refresh()->is_active)->toBeFalse();

        $check = tacApi($this, 'GET', '/vouchers/check?code=HEMAT5');
        assertApiError($check, 'VOUCHER_INVALID', 422);
        $check->assertJsonPath('error.details.reason', 'inactive');

        $checkout = tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant, ['voucher_code' => 'HEMAT5']));
        assertApiError($checkout, 'VOUCHER_INVALID', 422);
        $checkout->assertJsonPath('error.details.reason', 'inactive');

        tacAsOwner($this);
        Livewire::test(ManageVouchers::class)->callAction(TestAction::make('activate')->table($voucher));
        expect($voucher->refresh()->is_active)->toBeTrue();

        tacApi($this, 'GET', '/vouchers/check?code=HEMAT5')->assertOk()->assertJsonPath('data.code', 'HEMAT5');
        // 24.000 − 5.000 = 19.000 + 5% 950 = 19.950 + 11% 2.194,5 = 22.144,5 → 22.200
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant, ['voucher_code' => 'HEMAT5']))->assertCreated()
            ->assertJsonPath('data.voucher_discount', '5000.00')
            ->assertJsonPath('data.grand_total', '22200.00');
        expect($voucher->refresh()->used_count)->toBe(1);
    });

    it('kartu stempel nonaktif: tidak ditawarkan, tidak menambah, tidak bisa ditukar; saldo kembali saat aktif', function () {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $program = app(SaveStampProgram::class)->handle(null, ['name' => 'Roti', 'stamps_required' => 3, 'product_ids' => [$this->pos->croissant->id]]);
        $member = Member::factory()->create(['name' => 'Rina']);
        $croissant = fn (int $qty): array => ['product_id' => $this->pos->croissant->id, 'qty' => $qty];

        // Saldo awal: 3 croissant = 3 stempel
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant(3), ['member_id' => $member->id]))->assertCreated()
            ->assertJsonPath('data.stamps_earned', 3);

        tacAsOwner($this);
        Livewire::test(ManageStampPrograms::class)->callAction(TestAction::make('deactivate')->table($program));
        expect($program->refresh()->is_active)->toBeFalse();

        tacApi($this, 'GET', "/members/{$member->id}")->assertOk()->assertJsonCount(0, 'data.stamp_cards');
        // Tukar ditolak
        assertApiError(
            tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant(1), ['member_id' => $member->id, 'stamp_program_ids' => [$program->id]])),
            'VALIDATION_ERROR', 422,
        );
        // Belanja tanpa tukar: tidak ada stempel baru
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant(2), ['member_id' => $member->id]))->assertCreated()
            ->assertJsonPath('data.stamps_earned', 0);

        tacAsOwner($this);
        Livewire::test(ManageStampPrograms::class)->callAction(TestAction::make('activate')->table($program));
        expect($program->refresh()->is_active)->toBeTrue();

        // Saldo lama tetap 3 → 1 hadiah tersedia
        tacApi($this, 'GET', "/members/{$member->id}")->assertOk()
            ->assertJsonPath('data.stamp_cards.0.name', 'Roti')
            ->assertJsonPath('data.stamp_cards.0.balance', 3)
            ->assertJsonPath('data.stamp_cards.0.rewards_available', 1);
        // Tukar 1 croissant gratis → total 0
        tacApi($this, 'POST', '/checkout', tacCheckoutBody($this, $croissant(1), ['member_id' => $member->id, 'stamp_program_ids' => [$program->id], 'payments' => []]))
            ->assertCreated()
            ->assertJsonPath('data.stamp_free_total', '24000.00')
            ->assertJsonPath('data.grand_total', '0.00');
    });

    it('grup opsi nonaktif hilang dari katalog dan opsinya ditolak; aktif lagi kembali wajib', function () {
        $group = $this->pos->size;

        Livewire::test(ListOptionGroups::class)->callAction(TestAction::make('deactivate')->table($group));
        expect($group->refresh()->is_active)->toBeFalse();

        $catalog = tacApi($this, 'GET', '/catalog')->assertOk();
        expect(array_column($catalog->json('data.option_groups'), 'id'))->not->toContain($group->id)
            ->and(collect($catalog->json('data.products'))->firstWhere('id', $this->pos->coffee->id)['option_group_ids'])->toBe([]);

        // Opsi grup nonaktif tidak sah; tanpa opsi kopi bisa dijual (grup tidak lagi wajib)
        assertApiError(
            tacApi($this, 'POST', '/orders/quote', ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1, 'option_ids' => [$this->pos->regular->id]]]]),
            'VALIDATION_ERROR', 422,
        );
        tacApi($this, 'POST', '/orders/quote', ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1]]])->assertOk()
            ->assertJsonPath('data.grand_total', '25700.00');

        tacAsOwner($this);
        Livewire::test(ListOptionGroups::class)->callAction(TestAction::make('activate')->table($group));
        expect($group->refresh()->is_active)->toBeTrue();

        $catalog = tacApi($this, 'GET', '/catalog')->assertOk();
        expect(array_column($catalog->json('data.option_groups'), 'id'))->toContain($group->id)
            ->and(collect($catalog->json('data.products'))->firstWhere('id', $this->pos->coffee->id)['option_group_ids'])->toBe([$group->id]);
        assertApiError(
            tacApi($this, 'POST', '/orders/quote', ['items' => [['product_id' => $this->pos->coffee->id, 'qty' => 1]]]),
            'VALIDATION_ERROR', 422,
        );
    });

    it('meja nonaktif: halaman QR 404; aktif lagi bisa dibuka', function () {
        $table = app(SaveDiningTable::class)->handle(null, Outlet::query()->findOrFail($this->pos->outlet->id), ['name' => 'Meja 7']);
        $url = $table->orderUrl();

        Livewire::test(ManageDiningTables::class)->callAction(TestAction::make('deactivate')->table($table));
        expect($table->refresh()->is_active)->toBeFalse();

        freshAuth();
        $this->get($url)->assertNotFound();

        tacAsOwner($this);
        Livewire::test(ManageDiningTables::class)->callAction(TestAction::make('activate')->table($table));
        expect($table->refresh()->is_active)->toBeTrue();

        freshAuth();
        $this->get($url)->assertOk()->assertSee('Meja 7');
    });
});

describe('izin menu katalog & promo per role', function () {
    it('Kategori, Grup Opsi, Promo mengikuti product.manage / promo.manage', function (UserRole $role) {
        $user = User::factory()->role($role)->forOutlet(Outlet::query()->findOrFail($this->pos->outlet->id))->create();
        $this->actingAs($user);

        $catalog = $role->hasFullDashboard() && $role->allows('product.manage');
        $promo = $role->hasFullDashboard() && $role->allows('promo.manage');

        expect(CategoryResource::canViewAny())->toBe($catalog)
            ->and(CategoryResource::canCreate())->toBe($catalog)
            ->and(OptionGroupResource::canViewAny())->toBe($catalog)
            ->and(OptionGroupResource::canCreate())->toBe($catalog)
            ->and(PromotionResource::canViewAny())->toBe($promo)
            ->and(PromotionResource::canCreate())->toBe($promo);

        foreach ([CategoryResource::getUrl(), OptionGroupResource::getUrl(), OptionGroupResource::getUrl('create'), PromotionResource::getUrl()] as $url) {
            $this->get($url)->assertStatus($catalog ? 200 : 403);
        }
    })->with([
        'owner' => [UserRole::Owner],
        'manager' => [UserRole::Manager],
        'supervisor' => [UserRole::Supervisor],
        'kasir' => [UserRole::Cashier],
    ]);

    it('pemetaan role: owner & manager mengelola katalog & promo; supervisor & kasir tidak', function () {
        expect(UserRole::Owner->allows('product.manage'))->toBeTrue()
            ->and(UserRole::Manager->allows('product.manage'))->toBeTrue()
            ->and(UserRole::Manager->allows('promo.manage'))->toBeTrue()
            ->and(UserRole::Supervisor->allows('product.manage'))->toBeFalse()
            ->and(UserRole::Supervisor->allows('promo.manage'))->toBeFalse()
            ->and(UserRole::Cashier->allows('product.manage'))->toBeFalse();
    });

    it('supervisor tidak bisa menjalankan aksi aktif/nonaktif promo', function () {
        $promotion = app(SavePromotion::class)->handle(null, ['name' => 'Promo', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '10']);
        $this->actingAs(User::factory()->supervisor()->forOutlet(Outlet::query()->findOrFail($this->pos->outlet->id))->create());

        $this->get(PromotionResource::getUrl())->assertForbidden();
        expect(PromotionResource::canEdit($promotion))->toBeFalse()
            ->and($promotion->refresh()->is_active)->toBeTrue();
    });
});

describe('isolasi tenant di dashboard', function () {
    beforeEach(function () {
        // Tenant B lengkap: shift, karyawan, metode bayar, promo, tagihan langganan
        $b = posSetup();
        $this->b = $b;
        TenantContext::run($b->tenantId, function () use ($b): void {
            $b->employee = User::factory()->manager()->create(['name' => 'Karyawan B']);
            $b->promotion = app(SavePromotion::class)->handle(null, ['name' => 'Promo B', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '10']);
            $plan = app(SavePlan::class)->handle(null, ['code' => 'pro', 'name' => 'Pro', 'price_monthly' => '199000', 'price_yearly' => '1990000']);
            $b->invoice = app(CreateInvoice::class)->handle(Tenant::query()->findOrFail($b->tenantId), $plan, BillingCycle::Monthly);
        });
        tacAsOwner($this);
    });

    it('shift tenant B: URL detail 404 dan tidak tampil di daftar', function () {
        $this->get(ShiftResource::getUrl('view', ['record' => $this->b->shift]))->assertNotFound();
        $this->get(ShiftResource::getUrl('view', ['record' => $this->pos->shift]))->assertOk();

        Livewire::test(ListShifts::class)
            ->assertCanSeeTableRecords([$this->pos->shift])
            ->assertCanNotSeeTableRecords([$this->b->shift]);
    });

    it('karyawan tenant B tidak tampil dan tidak bisa diubah', function () {
        Livewire::test(ManageEmployees::class)
            ->assertCanSeeTableRecords([$this->pos->cashier])
            ->assertCanNotSeeTableRecords([$this->b->employee]);
        // Rekaman tenant lain tidak bisa di-resolve oleh tabel (scope tenant) → aksi ditolak
        expect(fn () => Livewire::test(ManageEmployees::class)->callAction(TestAction::make('edit')->table($this->b->employee), ['name' => 'Dibajak']))
            ->toThrow(ActionNotResolvableException::class);

        expect(User::query()->withoutGlobalScopes()->findOrFail($this->b->employee->id)->name)->toBe('Karyawan B');
    });

    it('metode bayar tenant B tidak tampil dan tidak bisa diubah', function () {
        $foreign = PaymentMethod::allTenants()->where('tenant_id', $this->b->tenantId)->where('category', 'qris')->sole();

        Livewire::test(ManagePaymentMethods::class)
            ->assertCanSeeTableRecords([$this->pos->methods['qris']])
            ->assertCanNotSeeTableRecords([$foreign]);
        expect(fn () => Livewire::test(ManagePaymentMethods::class)->callAction(TestAction::make('edit')->table($foreign), ['name' => 'Dibajak']))
            ->toThrow(ActionNotResolvableException::class)
            ->and(fn () => Livewire::test(ManagePaymentMethods::class)->callAction(TestAction::make('deactivate')->table($foreign)))
            ->toThrow(ActionNotResolvableException::class);

        $foreign = PaymentMethod::allTenants()->findOrFail($foreign->id);
        expect($foreign->name)->not->toBe('Dibajak')->and($foreign->is_active)->toBeTrue();
    });

    it('promo tenant B tidak tampil dan tidak bisa diubah', function () {
        $mine = app(SavePromotion::class)->handle(null, ['name' => 'Promo A', 'type' => 'item_discount', 'discount_type' => 'percent', 'discount_value' => '10']);

        Livewire::test(ManagePromotions::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$this->b->promotion]);
        expect(fn () => Livewire::test(ManagePromotions::class)->callAction(TestAction::make('edit')->table($this->b->promotion), ['name' => 'Dibajak']))
            ->toThrow(ActionNotResolvableException::class)
            ->and(fn () => Livewire::test(ManagePromotions::class)->callAction(TestAction::make('deactivate')->table($this->b->promotion)))
            ->toThrow(ActionNotResolvableException::class);

        $foreign = Promotion::allTenants()->findOrFail($this->b->promotion->id);
        expect($foreign->name)->toBe('Promo B')->and($foreign->is_active)->toBeTrue();
    });

    it('halaman Langganan hanya memuat tenant & tagihan sendiri', function () {
        $page = Livewire::test(SubscriptionSettings::class)
            ->assertOk()
            ->assertDontSee($this->b->invoice->number);

        expect($page->instance()->tenant()->id)->toBe($this->pos->tenantId)
            ->and($page->instance()->invoices()->pluck('id')->all())->not->toContain($this->b->invoice->id)
            ->and($page->instance()->pendingInvoice())->toBeNull()
            ->and(SubscriptionInvoice::allTenants()->whereKey($this->b->invoice->id)->exists())->toBeTrue();
    });
});
