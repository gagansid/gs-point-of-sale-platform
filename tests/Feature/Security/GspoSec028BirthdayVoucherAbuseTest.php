<?php

declare(strict_types=1);

use App\Actions\Member\Data\MemberData;
use App\Actions\Member\IssueBirthdayVouchers;
use App\Actions\Member\SaveMember;
use App\Actions\Member\UpdateBirthdayVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\VoucherSource;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/*
 * GSPOS-SEC-028 (docs/security/reports/2026-10-23-audit.md): voucher ulang tahun tidak bisa didapat berulang
 * dengan memindah bulan lahir sendiri, dan akun baru tidak langsung "berulang tahun".
 */
beforeEach(function () {
    $this->pos = posSetup();
    Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-senja']);
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateBirthdayVoucher::class)->handle(['enabled' => true, 'type' => 'fixed', 'value' => '10000', 'max_discount' => null, 'min_spend' => '0']);
});

function birthdayVouchers(Member $member): int
{
    return Voucher::query()->where('member_id', $member->id)->where('source', VoucherSource::Birthday)->count();
}

it('halaman member: tanggal lahir hanya bisa diisi sekali, nama tetap bisa diubah', function () {
    $member = Member::factory()->create(['name' => 'Rina', 'phone' => '+6281234567890', 'birth_day' => null, 'birth_month' => null]);
    $member->forceFill(['password' => 'rahasia-123'])->save();
    TenantContext::forget();

    $this->post('/m/kopi-senja/login', ['phone' => '0812 3456 7890', 'password' => 'rahasia-123'])->assertRedirect('/m/kopi-senja');

    $this->get('/m/kopi-senja/profile')->assertOk()->assertSee('Hanya bisa diisi sekali');
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina', 'birth_day' => 17, 'birth_month' => 8])->assertSessionHasNoErrors();

    // Sudah terisi: pindah bulan ditolak, data tetap
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina', 'birth_day' => 1, 'birth_month' => 11])
        ->assertSessionHasErrors(['birth_day' => 'Tanggal lahir sudah terisi. Minta kasir untuk mengubahnya']);
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina', 'birth_day' => '', 'birth_month' => 11])->assertSessionHasErrors('birth_day');

    // Form tanpa field tanggal lahir (tampilan terkunci) atau nilai sama tetap bisa menyimpan nama
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina K'])->assertSessionHasNoErrors();
    $this->post('/m/kopi-senja/profile', ['name' => 'Rina K.', 'birth_day' => 17, 'birth_month' => 8])->assertSessionHasNoErrors();

    $member = Member::allTenants()->findOrFail($member->id);
    expect($member->name)->toBe('Rina K.')->and($member->birth_day)->toBe(17)->and($member->birth_month)->toBe(8);

    $this->get('/m/kopi-senja/profile')->assertOk()->assertSee('17 Agustus')->assertSee('Minta kasir bila perlu diubah')
        ->assertDontSee('name="birth_month"', false);
});

it('satu voucher ulang tahun per member per tahun walau bulan lahir diubah kasir', function () {
    Carbon::setTestNow('2026-01-10 03:00:00');
    $member = Member::factory()->create(['birth_day' => 17, 'birth_month' => 8]);

    Carbon::setTestNow('2026-08-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(1);

    // Kasir/owner mengubah bulan lahir ke September → tidak ada voucher kedua tahun ini
    app(SaveMember::class)->handle($member, MemberData::fromArray([
        'name' => $member->name, 'phone' => (string) $member->phone, 'birth_day' => 5, 'birth_month' => 9,
    ]));
    Carbon::setTestNow('2026-09-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(0)->and(birthdayVouchers($member))->toBe(1);

    // Tahun berikutnya dapat lagi
    Carbon::setTestNow('2027-09-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(1)->and(birthdayVouchers($member))->toBe(2);
});

it('akun baru yang mendaftar di bulan lahirnya tidak langsung mendapat voucher', function () {
    Carbon::setTestNow('2026-07-31 16:59:59'); // 31 Juli 23.59.59 WIB
    $lama = Member::factory()->create(['birth_day' => 2, 'birth_month' => 8]);

    Carbon::setTestNow('2026-07-31 17:00:00'); // 1 Agustus 00.00 WIB
    $baru = Member::factory()->create(['birth_day' => 2, 'birth_month' => 8]);

    Carbon::setTestNow('2026-08-15 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(1)
        ->and(birthdayVouchers($lama))->toBe(1)
        ->and(birthdayVouchers($baru))->toBe(0);

    // Tahun berikutnya akun itu sudah terdaftar sebelum bulan lahirnya
    Carbon::setTestNow('2027-08-01 01:00:00');
    app(IssueBirthdayVouchers::class)->handle();
    expect(birthdayVouchers($baru))->toBe(1);
});

it('isolasi tenant: voucher ulang tahun bisnis lain tidak menghalangi member bisnis ini', function () {
    Carbon::setTestNow('2026-01-10 03:00:00');
    $member = Member::factory()->create(['birth_day' => 17, 'birth_month' => 8]);

    $other = posSetup();
    TenantContext::set($other->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]);
    app(UpdateBirthdayVoucher::class)->handle(['enabled' => true, 'type' => 'fixed', 'value' => '5000', 'max_discount' => null, 'min_spend' => '0']);
    $otherMember = Member::factory()->create(['birth_day' => 17, 'birth_month' => 8, 'phone' => $member->phone]);

    Carbon::setTestNow('2026-08-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(2);
    expect(Voucher::allTenants()->where('member_id', $member->id)->count())->toBe(1)
        ->and(Voucher::allTenants()->where('member_id', $otherMember->id)->count())->toBe(1);
});
