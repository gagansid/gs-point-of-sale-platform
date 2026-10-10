<?php

declare(strict_types=1);

use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Actions\User\SendEmployeeVerification;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Models\Outlet;
use App\Models\User;
use App\Notifications\VerifyEmployeeEmail;
use App\Notifications\VerifyOwnerEmail;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Email wajib & verifikasi karyawan (SPEC Q46) + daftar pilih outlet di form Karyawan.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Notification::fake();
    $this->outlet = Outlet::factory()->create(['name' => 'Kemang']);
    TenantContext::set($this->outlet->tenant_id);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
});

function cashierForm(array $override = []): array
{
    return ['name' => 'Budi', 'role' => UserRole::Cashier->value, 'email' => 'budi@kopi.test', 'pin' => '481920',
        'username' => 'budi', 'password' => 'rahasia123', ...$override];
}

it('kasir wajib email', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('create')->table(), cashierForm(['email' => null]))
        ->assertHasFormErrors(['email' => 'required']);
});

it('karyawan baru belum terverifikasi dan menerima link verifikasi', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('create')->table(), cashierForm())
        ->assertHasNoFormErrors();

    $budi = User::query()->where('email', 'budi@kopi.test')->sole();
    expect($budi->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($budi, VerifyEmployeeEmail::class);
});

it('mengganti email mereset verifikasi; menyimpan tanpa mengganti email tidak mengirim ulang', function () {
    $budi = User::factory()->cashier()->forOutlet($this->outlet)->create(['email' => 'lama@kopi.test', 'email_verified_at' => now()]);
    $save = fn (string $email) => app(SaveEmployee::class)->handle($budi->fresh(), EmployeeData::fromArray(cashierForm(['email' => $email, 'pin' => null, 'password' => null])), $this->owner);

    $save('lama@kopi.test');
    expect($budi->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();

    $save('baru@kopi.test');
    expect($budi->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($budi->fresh(), VerifyEmployeeEmail::class);
});

it('aksi "Kirim ulang verifikasi" dibatasi 3 kali per 10 menit', function () {
    $budi = User::factory()->cashier()->forOutlet($this->outlet)->create(['email' => 'budi@kopi.test', 'email_verified_at' => null]);

    foreach (range(1, 3) as $_) {
        Livewire::test(ManageEmployees::class)->callAction(TestAction::make('resendVerification')->table($budi));
    }

    expect(fn () => app(SendEmployeeVerification::class)->handle($budi))->toThrow(BusinessException::class);
    Notification::assertSentToTimes($budi, VerifyEmployeeEmail::class, 3);
});

it('link verifikasi kasir menandai terverifikasi tanpa tombol dashboard', function () {
    $budi = User::factory()->cashier()->forOutlet($this->outlet)->create(['email' => 'budi@kopi.test', 'email_verified_at' => null]);

    $this->get(VerifyOwnerEmail::url($budi))->assertOk()
        ->assertSee('Email Anda sudah terverifikasi')
        ->assertDontSee('Lanjut ke dashboard');

    expect($budi->fresh()->hasVerifiedEmail())->toBeTrue();
});

describe('pilihan outlet', function () {
    it('satu outlet: pilihan outlet tidak tampil', function () {
        Livewire::test(ManageEmployees::class)
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldHidden('outlet_ids');
    });

    it('dua outlet: daftar pilih outlet tampil', function () {
        Outlet::factory()->create(['name' => 'Cilandak']);

        Livewire::test(ManageEmployees::class)
            ->mountAction(TestAction::make('create')->table())
            ->assertFormFieldVisible('outlet_ids');
    });
});
