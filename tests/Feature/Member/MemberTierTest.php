<?php

declare(strict_types=1);

use App\Actions\Member\EvaluateMemberTiers;
use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\MemberProgramSettings;
use App\Models\Member;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Tier member M5 (ADR 0013, Q64). Croissant 24.000 → total 28.000 (service 5%, pajak 11%, pembulatan Rp100);
 * 2 × croissant = 55.900. Silver ≥ 50.000 (poin ×2, diskon 5%), Gold ≥ 200.000 (poin ×3, diskon 10%).
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(SaveMemberTiers::class)->handle([
        ['name' => 'Silver', 'min_spend_12m' => '50000', 'point_multiplier' => '2', 'discount_percent' => '5'],
        ['name' => 'Gold', 'min_spend_12m' => '200000', 'point_multiplier' => '3', 'discount_percent' => '10'],
    ]);
    $this->member = Member::factory()->create(['name' => 'Rina']);
});

afterEach(fn () => Carbon::setTestNow());

function tierSale(object $test, int $qty = 1, ?string $id = null): Order
{
    return app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => $id ?? uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 200000]],
    ]))['order'];
}

it('naik tier saat order selesai; pengali poin & diskon tier berlaku mulai transaksi berikutnya', function () {
    // Order pertama: belum ada tier → poin ×1 (48.000 → 4 poin), tanpa diskon
    $first = tierSale($this, 2);
    $member = $this->member->refresh();

    expect($first->points_earned)->toBe(4)
        ->and($first->member_tier)->toBeNull()
        ->and($first->member_discount)->toBe('0.00')
        ->and($member->spend_12m)->toBe('55900.00')
        ->and($member->tier?->name)->toBe('Silver');

    // Order kedua sebagai Silver: diskon 5% (1.200), poin floor(22.800 / 10.000) × 1 × 2 = 4
    $second = tierSale($this);

    expect($second->member_tier)->toBe('Silver')
        ->and($second->member_discount)->toBe('1200.00')
        ->and($second->grand_total)->toBe('26600.00')
        ->and($second->points_earned)->toBe(4)
        ->and($this->member->refresh()->points_balance)->toBe(8);
});

it('diskon tier ditambahkan ke diskon member dasar dan tidak dihitung ke batas diskon kasir', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id], ['member_discount_percent' => '10']);
    $this->member->forceFill(['tier_id' => MemberTier::query()->where('name', 'Gold')->value('id')])->save();

    // 10% dasar + 10% Gold = 20% (di atas batas diskon kasir 10%) tetap lolos tanpa PIN
    $order = tierSale($this);

    expect($order->member_discount)->toBe('4800.00')->and($order->approved_by)->toBeNull();
});

it('void order mengembalikan belanja 12 bulan dan menurunkan tier', function () {
    $first = tierSale($this, 2);
    expect($this->member->refresh()->tier?->name)->toBe('Silver');

    app(VoidOrder::class)->handle($this->pos->supervisor, $first, 'Salah input', null);

    $member = $this->member->refresh();
    expect($member->spend_12m)->toBe('0.00')->and($member->tier_id)->toBeNull();
});

it('kiriman ulang checkout tidak menghitung belanja dua kali', function () {
    $id = uuid();
    tierSale($this, 1, $id);
    tierSale($this, 1, $id);

    expect($this->member->refresh()->spend_12m)->toBe('28000.00')
        ->and(Order::query()->count())->toBe(1);
});

it('jadwal harian: belanja lebih dari 12 bulan keluar dari hitungan sehingga tier turun', function () {
    Carbon::setTestNow('2026-01-10 10:00:00');
    tierSale($this, 2);
    expect($this->member->refresh()->tier?->name)->toBe('Silver');

    Carbon::setTestNow('2027-01-11 10:00:00');
    expect(app(EvaluateMemberTiers::class)->handle())->toBe(1);

    $member = $this->member->refresh();
    expect($member->tier_id)->toBeNull()->and($member->spend_12m)->toBe('0.00')
        // Total belanja seumur hidup tidak berubah
        ->and($member->total_spent)->toBe('55900.00');

    // Aman diulang
    expect(app(EvaluateMemberTiers::class)->handle())->toBe(0);
});

it('simpan tier: ubah, hapus (member jatuh ke tier lain), validasi gagal', function () {
    tierSale($this, 2);
    $silver = MemberTier::query()->where('name', 'Silver')->sole();

    // Silver dinaikkan ke 60.000 → member (55.900) tanpa tier; Gold dihapus
    app(SaveMemberTiers::class)->handle([
        ['id' => $silver->id, 'name' => 'Silver', 'min_spend_12m' => '60000', 'point_multiplier' => '2', 'discount_percent' => '5'],
    ]);

    expect(MemberTier::query()->pluck('name')->all())->toBe(['Silver'])
        ->and($silver->refresh()->min_spend_12m)->toBe('60000.00')
        ->and($this->member->refresh()->tier_id)->toBeNull();

    expect(fn () => app(SaveMemberTiers::class)->handle([
        ['name' => 'A', 'min_spend_12m' => '1000', 'point_multiplier' => '1', 'discount_percent' => '0'],
        ['name' => 'B', 'min_spend_12m' => '1000', 'point_multiplier' => '1', 'discount_percent' => '0'],
    ]))->toThrow(BusinessException::class, 'Minimal belanja tiap tier harus berbeda');

    expect(fn () => app(SaveMemberTiers::class)->handle([
        ['name' => 'A', 'min_spend_12m' => '-1', 'point_multiplier' => '1', 'discount_percent' => '120'],
    ]))->toThrow(ValidationException::class);

    // Validasi gagal tidak mengubah data
    expect(MemberTier::query()->count())->toBe(1);
});

it('isolasi tenant: tier bisnis lain tidak terlihat dan ID-nya tidak bisa diubah', function () {
    $silverA = MemberTier::query()->where('name', 'Silver')->sole();

    $other = posSetup();
    TenantContext::set($other->tenantId);

    expect(MemberTier::query()->count())->toBe(0);

    // ID tier bisnis A dari form bisnis B = tier baru milik B, tier A tidak berubah
    app(SaveMemberTiers::class)->handle([
        ['id' => $silverA->id, 'name' => 'Bronze', 'min_spend_12m' => '1', 'point_multiplier' => '1', 'discount_percent' => '0'],
    ]);

    expect(MemberTier::query()->sole()->id)->not->toBe($silverA->id);
    TenantContext::set($this->pos->tenantId);
    expect($silverA->refresh()->name)->toBe('Silver')->and(MemberTier::query()->count())->toBe(2);
});

it('API: member & order menampilkan tier', function () {
    tierSale($this, 2);
    $order = tierSale($this);

    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.tier.name', 'Silver')
        ->assertJsonPath('data.tier.point_multiplier', '2.00')
        ->assertJsonPath('data.tier.discount_percent', '5.00');

    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$order->id}", apiHeaders())
        ->assertOk()->assertJsonPath('data.member.tier', 'Silver');

    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$order->id}/receipt", apiHeaders())
        ->assertOk()->assertJsonPath('data.member_tier', 'Silver');
});

it('dashboard: owner mengelola tier di Program member; manager ditolak', function () {
    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    $component = Livewire::test(MemberProgramSettings::class);
    $tiers = $component->get('data.tiers');
    expect($tiers)->toHaveCount(2);

    $first = array_key_first($tiers);
    $component->set("data.tiers.{$first}.name", 'Perak')->call('save')->assertNotified('Program member disimpan');
    expect(MemberTier::query()->orderBy('min_spend_12m')->first()?->name)->toBe('Perak');

    // Batas belanja sama → ditolak, data tidak berubah
    $keys = array_keys($tiers);
    $component->set("data.tiers.{$keys[1]}.min_spend_12m", '50000')->call('save')->assertNotified('Minimal belanja tiap tier harus berbeda');
    expect(MemberTier::query()->where('name', 'Gold')->value('min_spend_12m'))->toBe('200000.00');

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create());
    $this->get(MemberProgramSettings::getUrl())->assertForbidden();
});
