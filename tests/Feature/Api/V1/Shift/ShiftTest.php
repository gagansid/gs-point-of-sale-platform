<?php

declare(strict_types=1);

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->pos = posSetup(openShift: false);
});

describe('buka shift', function () {
    it('membuka shift di device kasir dan bisa dibaca sebagai shift saat ini', function () {
        $this->withToken($this->pos->token)->getJson('/api/v1/shifts/current', apiHeaders())->assertOk()->assertJsonPath('data', null);

        freshAuth();
        $response = $this->withToken($this->pos->token)->postJson('/api/v1/shifts', ['opening_cash' => 200000], apiHeaders());
        $response->assertCreated()
            ->assertJsonPath('message', 'Shift berhasil dibuka')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_cash', '200000.00')
            ->assertJsonPath('data.opened_by_name', 'Budi')
            ->assertJsonPath('data.device_id', $this->pos->device->id);

        freshAuth();
        $this->withToken($this->pos->token)->getJson('/api/v1/shifts/current', apiHeaders())
            ->assertJsonPath('data.id', $response->json('data.id'));
    });

    it('request ganda / shift sudah terbuka → shift yang sama (satu shift per device)', function () {
        $first = $this->withToken($this->pos->token)->postJson('/api/v1/shifts', ['opening_cash' => 100000], apiHeaders())->json('data.id');

        // Kasir lain di device yang sama
        freshAuth();
        $this->withToken(userToken($this->pos->supervisor, $this->pos->device))
            ->postJson('/api/v1/shifts', ['opening_cash' => 50000], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('meta.idempotent_replay', true);

        expect(Shift::allTenants()->count())->toBe(1);
    });

    it('database menolak dua shift terbuka di device yang sama', function () {
        Shift::factory()->forDevice($this->pos->device)->create();
        Shift::factory()->forDevice($this->pos->device)->create();
    })->throws(UniqueConstraintViolationException::class);

    it('validasi kas awal', function (mixed $cash) {
        assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/shifts', ['opening_cash' => $cash], apiHeaders()), 'VALIDATION_ERROR', 422);
    })->with([[null], [-1], ['abc'], ['10.123']]);

    it('token yang tidak terikat device kasir ditolak', function () {
        $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

        assertApiError($this->withToken(userToken($owner))->postJson('/api/v1/shifts', ['opening_cash' => 0], apiHeaders()), 'DEVICE_NOT_REGISTERED', 403);
    });

    it('owner login email di device kasir terdaftar bisa membuka shift', function () {
        User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId, 'email' => 'owner@kopi.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'owner@kopi.test', 'password' => 'password', 'device_uid' => 'kasir-1'], apiHeaders())->json('data.token');

        freshAuth();
        $this->withToken($token)->postJson('/api/v1/shifts', ['opening_cash' => 0], apiHeaders())->assertCreated();
    });
});

describe('tutup shift', function () {
    beforeEach(function () {
        $this->shift = Shift::factory()->forDevice($this->pos->device, $this->pos->cashier)->create(['opening_cash' => '200000.00']);
    });

    it('pembuka shift menutup dengan kas aktual; selisih dihitung server', function () {
        $response = $this->withToken($this->pos->token)
            ->postJson("/api/v1/shifts/{$this->shift->id}/close", ['actual_cash' => 195000, 'note' => 'Kurang 5rb'], apiHeaders());

        $response->assertOk()
            ->assertJsonPath('data.shift.status', 'closed')
            ->assertJsonPath('data.shift.expected_cash', '200000.00')
            ->assertJsonPath('data.shift.actual_cash', '195000.00')
            ->assertJsonPath('data.shift.difference', '-5000.00')
            ->assertJsonPath('data.summary.order_count', 0);

        expect($this->shift->refresh()->open_device_key)->toBeNull();
    });

    it('menutup dua kali mengembalikan hasil yang sama', function () {
        $this->withToken($this->pos->token)->postJson("/api/v1/shifts/{$this->shift->id}/close", ['actual_cash' => 200000], apiHeaders())->assertOk();
        freshAuth();
        $this->withToken($this->pos->token)->postJson("/api/v1/shifts/{$this->shift->id}/close", ['actual_cash' => 1], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.shift.actual_cash', '200000.00')
            ->assertJsonPath('meta.idempotent_replay', true);
    });

    it('kasir lain tidak boleh menutup shift biasa', function () {
        $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();

        assertApiError(
            $this->withToken(userToken($other, $this->pos->device))->postJson("/api/v1/shifts/{$this->shift->id}/close", ['actual_cash' => 0], apiHeaders()),
            'FORBIDDEN',
            403,
        );
    });

    it('supervisor menutup paksa dengan catatan wajib', function () {
        $token = userToken($this->pos->supervisor, $this->pos->device);

        assertApiError($this->withToken($token)->postJson("/api/v1/shifts/{$this->shift->id}/force-close", ['actual_cash' => 0], apiHeaders()), 'VALIDATION_ERROR', 422);

        freshAuth();
        $this->withToken($token)->postJson("/api/v1/shifts/{$this->shift->id}/force-close", ['actual_cash' => 200000, 'note' => 'Kasir lupa tutup'], apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.shift.status', 'force_closed');

        expect($this->shift->refresh()->closed_by)->toBe($this->pos->supervisor->id);
    });

    it('kasir tidak punya izin tutup paksa', function () {
        assertApiError(
            $this->withToken($this->pos->token)->postJson("/api/v1/shifts/{$this->shift->id}/force-close", ['actual_cash' => 0, 'note' => 'x'], apiHeaders()),
            'FORBIDDEN',
            403,
        );
    });

    it('isolasi: shift tenant lain → 404', function () {
        $foreign = Shift::factory()->create();

        assertApiError($this->withToken($this->pos->token)->postJson("/api/v1/shifts/{$foreign->id}/close", ['actual_cash' => 0], apiHeaders()), 'NOT_FOUND', 404);
        freshAuth();
        assertApiError($this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$foreign->id}/summary", apiHeaders()), 'NOT_FOUND', 404);
        expect($foreign->refresh()->status)->toBe(ShiftStatus::Open);
    });

    it('ringkasan: shift sendiri atau dengan shift.view_all', function () {
        $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->shift->id}/summary", apiHeaders())->assertOk();

        $other = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        freshAuth();
        assertApiError($this->withToken(userToken($other, $this->pos->device))->getJson("/api/v1/shifts/{$this->shift->id}/summary", apiHeaders()), 'FORBIDDEN', 403);

        freshAuth();
        $this->withToken(userToken($this->pos->supervisor, $this->pos->device))->getJson("/api/v1/shifts/{$this->shift->id}/summary", apiHeaders())->assertOk();
    });
});
