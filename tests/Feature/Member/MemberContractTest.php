<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * Kontrak API member (ADR 0013, ADR 0023): isolasi tenant riwayat poin & kasbon, validasi pencarian, permission.
 *
 * Catatan permission: member.view dimiliki SEMUA role tenant (UserRole::permissions(): owner '*', manager,
 * supervisor, kasir). Jalur 403 karena role tidak bisa dicapai; test di bawah mendokumentasikan hal itu.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::run($this->pos->tenantId, function (): void {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        $this->member = Member::factory()->create(['name' => 'Rina Kusuma', 'phone' => '+6281234567890']);
        $this->owner = User::factory()->owner()->create();
    });

    // Member milik bisnis lain (program member aktif juga di sana)
    $foreign = posSetup();
    $this->foreignMember = TenantContext::run($foreign->tenantId, function () use ($foreign): Member {
        app(UpdateMemberProgram::class)->handle(true, [$foreign->outlet->id]);
        $member = Member::factory()->create(['name' => 'Rina Tetangga', 'phone' => '+6281200001111']);
        $owner = User::factory()->owner()->create();
        app(AdjustMemberPoints::class)->handle($member, 120, 'Saldo awal', $owner);

        return $member;
    });
});

function mcApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

describe('GET /members/{id}/points', function () {
    it('mengembalikan saldo & mutasi member sendiri', function () {
        TenantContext::run($this->pos->tenantId, fn () => app(AdjustMemberPoints::class)->handle($this->member, 40, 'Saldo awal', $this->owner));

        mcApi($this, 'GET', "/members/{$this->member->id}/points")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance', 40)
            ->assertJsonCount(1, 'data.entries');
    });

    it('menolak member tenant lain dengan MEMBER_NOT_FOUND tanpa membocorkan saldo', function () {
        $response = mcApi($this, 'GET', "/members/{$this->foreignMember->id}/points");

        assertApiError($response, 'MEMBER_NOT_FOUND', 404);
        $response->assertJsonMissingPath('data.balance');
    });

    it('menolak id acak dengan MEMBER_NOT_FOUND', function () {
        assertApiError(mcApi($this, 'GET', '/members/'.uuid().'/points'), 'MEMBER_NOT_FOUND', 404);
    });
});

describe('GET /members/{id}/receivables', function () {
    it('mengembalikan saldo kasbon member sendiri', function () {
        mcApi($this, 'GET', "/members/{$this->member->id}/receivables")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonCount(0, 'data.entries');
    });

    it('menolak member tenant lain dengan MEMBER_NOT_FOUND', function () {
        $response = mcApi($this, 'GET', "/members/{$this->foreignMember->id}/receivables");

        assertApiError($response, 'MEMBER_NOT_FOUND', 404);
        $response->assertJsonMissingPath('data.balance');
    });
});

describe('GET /members', function () {
    it('menolak tanpa parameter search dengan VALIDATION_ERROR', function () {
        $response = mcApi($this, 'GET', '/members');

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor('search', 'error.details');
    });

    it('menolak search terlalu pendek atau terlalu panjang dengan VALIDATION_ERROR', function (string $search) {
        $response = mcApi($this, 'GET', '/members?search='.urlencode($search));

        assertApiError($response, 'VALIDATION_ERROR', 422);
        $response->assertJsonValidationErrorFor('search', 'error.details');
    })->with([
        '1 karakter' => ['r'],
        '101 karakter' => [str_repeat('a', 101)],
    ]);

    it('semua role tenant memegang member.view, jadi tidak ada role yang mendapat 403', function () {
        // Dokumentasi kontrak: jalur 403 di MemberController::index tidak tercapai lewat role
        foreach (UserRole::cases() as $role) {
            expect($role->allows('member.view'))->toBeTrue();
        }

        $supervisorToken = userToken($this->pos->supervisor, $this->pos->device);
        mcApi($this, 'GET', '/members?search=rina', token: $supervisorToken)->assertOk()->assertJsonCount(1, 'data');
    });

    it('bisnis hanya-baca tetap boleh mencari dan melihat riwayat poin (member.view bukan aksi mengubah)', function () {
        Tenant::query()->whereKey($this->pos->tenantId)->update(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->subDay()]);

        mcApi($this, 'GET', '/members?search=rina')->assertOk();
        mcApi($this, 'GET', "/members/{$this->member->id}/points")->assertOk();
    });
});
