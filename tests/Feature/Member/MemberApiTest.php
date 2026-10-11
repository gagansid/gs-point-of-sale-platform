<?php

declare(strict_types=1);

use App\Actions\Member\AnonymizeMember;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\TenantStatus;
use App\Models\Member;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/** API member M1 (ADR 0013, SPEC Q60–Q61): cari, daftar, detail, pasang ke checkout & open bill. */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::run($this->pos->tenantId, function (): void {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $this->member = Member::factory()->create(['name' => 'Rina Kusuma', 'phone' => '+6281234567890', 'code' => 'RKX7P2']);
        $this->owner = User::factory()->owner()->create();
    });
});

function memberApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

function newMember(array $override = []): array
{
    return ['id' => uuid(), 'name' => 'Budi Santoso', 'phone' => '0812 9999 0000', 'consent' => true, ...$override];
}

it('kasir mendaftarkan member: HP dinormalisasi, kode dibuat, outlet asal tercatat', function () {
    $body = newMember();

    memberApi($this, 'POST', '/members', $body)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Budi Santoso')
        ->assertJsonPath('data.phone_masked', '•••• 0000')
        ->assertJsonMissingPath('data.phone')
        ->assertJsonPath('meta.idempotent_replay', false);

    // Request ganda = satu member
    memberApi($this, 'POST', '/members', $body)->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    $member = Member::allTenants()->where('name', 'Budi Santoso')->sole();
    expect($member->phone)->toBe('+6281299990000')
        ->and($member->code)->toMatch('/^[A-Z2-9]{6}$/')
        ->and($member->consent_at)->not->toBeNull()
        ->and($member->joined_outlet_id)->toBe($this->pos->outlet->id);
});

it('validasi daftar: HP sudah terdaftar (format lain), HP tidak valid, tanpa persetujuan, tanggal lahir salah', function (array $override, string $field) {
    $response = memberApi($this, 'POST', '/members', newMember($override));

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'HP terdaftar' => [['phone' => '+62 812-3456-7890'], 'phone'],
    'HP tidak valid' => [['phone' => '12345'], 'phone'],
    'tanpa persetujuan' => [['consent' => false], 'consent'],
    'nama kosong' => [['name' => ''], 'name'],
    '31 Februari' => [['birth_day' => 31, 'birth_month' => 2], 'birth_day'],
]);

it('cari lewat HP format apa pun, kode, nama, atau 4 digit terakhir; HP disamarkan untuk kasir', function (string $term) {
    memberApi($this, 'GET', '/members?search='.urlencode($term))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->member->id)
        ->assertJsonPath('data.0.phone_masked', '•••• 7890')
        ->assertJsonMissingPath('data.0.phone');
})->with(['081234567890', '+62 812 3456 7890', 'rkx7p2', 'rina', '7890']);

it('owner (member.manage) melihat data lengkap', function () {
    memberApi($this, 'GET', '/members/'.$this->member->id, token: userToken($this->owner, $this->pos->device))
        ->assertOk()
        ->assertJsonPath('data.phone', '+6281234567890');
});

it('pencarian dibatasi 30/menit per user', function () {
    foreach (range(1, 30) as $i) {
        memberApi($this, 'GET', '/members?search=rina')->assertOk();
    }

    assertApiError(memberApi($this, 'GET', '/members?search=rina'), 'TOO_MANY_REQUESTS', 429);
});

it('isolasi tenant: member tenant lain tidak bisa dicari, dibuka, atau dipasang', function () {
    $foreign = posSetup();
    $other = TenantContext::run($foreign->tenantId, function () use ($foreign): Member {
        app(UpdateMemberProgram::class)->handle(true, [$foreign->outlet->id]);

        return Member::factory()->create(['name' => 'Rina Tetangga', 'phone' => '+6281200001111']);
    });

    memberApi($this, 'GET', '/members?search=tetangga')->assertOk()->assertJsonCount(0, 'data');
    assertApiError(memberApi($this, 'GET', '/members/'.$other->id), 'MEMBER_NOT_FOUND', 404);
    // HP sama di bisnis lain = member lain (boleh)
    memberApi($this, 'POST', '/members', newMember(['phone' => '081200001111']))->assertCreated();
});

it('program nonaktif: cari, daftar, dan pasang ditolak MEMBER_PROGRAM_DISABLED', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(UpdateMemberProgram::class)->handle(false, []));

    assertApiError(memberApi($this, 'GET', '/members?search=rina'), 'MEMBER_PROGRAM_DISABLED', 422);
    assertApiError(memberApi($this, 'POST', '/members', newMember()), 'MEMBER_PROGRAM_DISABLED', 422);
    assertApiError(memberApi($this, 'POST', '/checkout', memberCheckoutBody($this, ['member_id' => $this->member->id])), 'MEMBER_PROGRAM_DISABLED', 422);
});

it('permission: bisnis hanya-baca tidak bisa mendaftarkan member', function () {
    Tenant::query()->whereKey($this->pos->tenantId)->update(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->subDay()]);

    expect(memberApi($this, 'POST', '/members', newMember())->status())->toBe(403);
    expect(Member::allTenants()->where('name', 'Budi Santoso')->exists())->toBeFalse();
});

function memberCheckoutBody(object $test, array $override = []): array
{
    return [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 50000]],
        ...$override,
    ];
}

it('checkout dengan member: tersimpan dengan snapshot nama, tampil di respons & struk', function () {
    $response = memberApi($this, 'POST', '/checkout', memberCheckoutBody($this, ['member_id' => $this->member->id]))
        ->assertCreated()
        ->assertJsonPath('data.member.id', $this->member->id)
        ->assertJsonPath('data.member.name', 'Rina Kusuma');

    $order = Order::allTenants()->findOrFail($response->json('data.id'));
    expect($order->member_name)->toBe('Rina Kusuma');

    memberApi($this, 'GET', "/orders/{$order->id}/receipt")->assertOk()->assertJsonPath('data.member_name', 'Rina Kusuma');
});

it('checkout dengan member nonaktif/dihapus/acak ditolak MEMBER_NOT_FOUND tanpa membuat order', function (Closure $prepare) {
    $id = $prepare($this);

    assertApiError(memberApi($this, 'POST', '/checkout', memberCheckoutBody($this, ['member_id' => $id])), 'MEMBER_NOT_FOUND', 404);
    expect(Order::allTenants()->count())->toBe(0);
})->with([
    'nonaktif' => [function (object $t): string {
        TenantContext::run($t->pos->tenantId, fn () => $t->member->forceFill(['is_active' => false])->save());

        return $t->member->id;
    }],
    'dihapus' => [function (object $t): string {
        TenantContext::run($t->pos->tenantId, fn () => app(AnonymizeMember::class)->handle($t->member));

        return $t->member->id;
    }],
    'acak' => [fn () => uuid()],
]);

it('outlet dikecualikan tetap boleh memasang member (kunjungan)', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(UpdateMemberProgram::class)->handle(true, []));

    memberApi($this, 'POST', '/checkout', memberCheckoutBody($this, ['member_id' => $this->member->id]))
        ->assertCreated()->assertJsonPath('data.member.name', 'Rina Kusuma');
});

it('open bill: member dipasang, tetap bila field tidak dikirim, dilepas bila null', function () {
    $id = uuid();
    $items = ['items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]];

    memberApi($this, 'PUT', "/orders/{$id}", [...$items, 'member_id' => $this->member->id])->assertJsonPath('data.member.name', 'Rina Kusuma');
    // Aplikasi lama tanpa field member_id: member tetap
    memberApi($this, 'PUT', "/orders/{$id}", $items)->assertJsonPath('data.member.name', 'Rina Kusuma');
    memberApi($this, 'PUT', "/orders/{$id}", [...$items, 'member_id' => null])->assertJsonPath('data.member', null);
});
