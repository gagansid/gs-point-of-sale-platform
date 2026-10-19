<?php

declare(strict_types=1);

use App\Actions\Admin\UpdateOnboardingSettings;
use App\Actions\Billing\CreateInvoice;
use App\Actions\Billing\SaveAddon;
use App\Actions\Billing\SavePlan;
use App\Enums\BillingCycle;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Pages\OnboardingSettings;
use App\Filament\Admin\Resources\Addons\Pages\ManageAddons;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Models\Addon;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Notifications\TenantSignedUp;
use App\Services\Admin\SecurityChecklist;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Database\Seeders\AddonSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Rapikan panel /admin 2026-10-19: Paket (kode, status aktif, periode promo, filter), Add-on (filter, status, katalog
 * bawaan), Pendaftaran (setelan lanjutan), Keamanan (pemeriksaan konfigurasi), tombol "+ Tambah" di header card.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-16 03:00:00');
    TenantContext::forget();
    Filament::setCurrentPanel('admin');
    $this->admin = Admin::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin, 'admin');
    $this->basic = app(SavePlan::class)->handle(null, ['code' => 'basic', 'name' => 'Basic', 'price_monthly' => '99000', 'price_yearly' => '990000', 'features' => ['member'], 'sort_order' => 1]);
    $this->promo = app(SavePlan::class)->handle(null, [
        'code' => 'promo', 'name' => 'Promo', 'price_monthly' => '49000', 'price_yearly' => '490000', 'features' => ['member', 'stock'], 'sort_order' => 2,
        'available_until' => '2026-10-31 16:59:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

describe('Paket', function () {
    it('kolom Kode terpisah, pencarian kode/nama, tab Aktif/Nonaktif', function () {
        $off = app(SavePlan::class)->handle(null, ['code' => 'lama', 'name' => 'Lama', 'price_monthly' => '1', 'price_yearly' => '1', 'is_active' => false]);

        Livewire::test(ManagePlans::class)
            ->assertTableColumnExists('code')
            ->searchTable('prom')->assertCanSeeTableRecords([$this->promo])->assertCanNotSeeTableRecords([$this->basic])
            ->searchTable('')
            ->set('activeTab', 'inactive')->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$this->basic])
            ->set('activeTab', 'active')->assertCanSeeTableRecords([$this->basic, $this->promo])->assertCanNotSeeTableRecords([$off]);
    });

    it('filter periode, tampil, dan fitur', function () {
        $scheduled = app(SavePlan::class)->handle(null, ['code' => 'nanti', 'name' => 'Nanti', 'price_monthly' => '1', 'price_yearly' => '1', 'available_from' => '2026-12-01 00:00:00']);

        Livewire::test(ManagePlans::class)
            ->filterTable('period', Plan::PERIOD_RUNNING)->assertCanSeeTableRecords([$this->promo])->assertCanNotSeeTableRecords([$this->basic, $scheduled])
            ->filterTable('period', Plan::PERIOD_SCHEDULED)->assertCanSeeTableRecords([$scheduled])->assertCanNotSeeTableRecords([$this->promo])
            ->filterTable('period', Plan::PERIOD_UNLIMITED)->assertCanSeeTableRecords([$this->basic])->assertCanNotSeeTableRecords([$this->promo])
            ->resetTableFilters()
            ->filterTable('feature', 'stock')->assertCanSeeTableRecords([$this->promo])->assertCanNotSeeTableRecords([$this->basic]);
    });

    it('menonaktifkan & mengaktifkan dari aksi baris', function () {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('deactivate')->table($this->basic))
            ->assertHasNoActionErrors();
        expect($this->basic->refresh()->is_active)->toBeFalse();

        Livewire::test(ManagePlans::class)->callAction(TestAction::make('activate')->table($this->basic));
        expect($this->basic->refresh()->is_active)->toBeTrue();
    });

    it('membuat paket promo dengan periode; berakhir harus setelah mulai', function () {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Lebaran', 'code' => 'lebaran', 'price_monthly' => '79000', 'price_yearly' => '790000',
                'has_period' => true, 'available_from' => '2026-11-01 00:00', 'available_until' => '2026-11-30 23:59',
                'is_active' => true, 'is_public' => true,
            ])
            ->assertHasNoActionErrors();

        expect(Plan::query()->where('code', 'lebaran')->sole()->periodStatus())->toBe(Plan::PERIOD_SCHEDULED);

        expect(fn () => app(SavePlan::class)->handle(null, ['code' => 'x', 'name' => 'X', 'price_monthly' => '1', 'price_yearly' => '1', 'available_from' => '2026-11-30', 'available_until' => '2026-11-01']))
            ->toThrow(ValidationException::class);
    });

    it('periode dimatikan saat ubah = tanpa batas waktu', function () {
        Livewire::test(ManagePlans::class)
            ->callAction(TestAction::make('edit')->table($this->promo), ['has_period' => false])
            ->assertHasNoActionErrors();

        expect($this->promo->refresh()->available_until)->toBeNull()->and($this->promo->periodStatus())->toBe(Plan::PERIOD_UNLIMITED);
    });

    it('promo berakhir: owner tidak bisa memilih paket baru, kecuali paket miliknya; admin tetap bisa', function () {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(5)]);
        $this->travelTo(Carbon::parse('2026-11-05 03:00:00'));

        expect(fn () => TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant, $this->promo, BillingCycle::Monthly)))
            ->toThrow(BusinessException::class, 'Paket tidak tersedia');

        $invoice = TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant, $this->promo, BillingCycle::Monthly, $this->admin));
        expect($invoice->plan_id)->toBe($this->promo->id);

        $tenant->forceFill(['plan_id' => $this->promo->id])->save();
        $renewal = TenantContext::run($tenant->id, fn () => app(CreateInvoice::class)->handle($tenant->refresh(), $this->promo, BillingCycle::Monthly));
        expect($renewal->plan_id)->toBe($this->promo->id);
    });
});

describe('Add-on', function () {
    it('katalog bawaan lengkap & aman dijalankan ulang (kode yang ada tidak diubah)', function () {
        (new AddonSeeder)->run();
        Addon::query()->where('code', 'extra-outlet')->update(['price_monthly' => '1.00']);
        (new AddonSeeder)->run();

        expect(Addon::query()->count())->toBe(count(AddonSeeder::addons()))
            ->and(Addon::query()->where('type', Addon::TYPE_QUOTA)->pluck('quota_key')->sort()->values()->all())->toBe(['devices', 'members', 'outlets', 'products', 'users'])
            ->and(Addon::query()->where('code', 'extra-outlet')->value('price_monthly'))->toBe('1.00');
    });

    it('filter jenis, pencarian, nonaktifkan', function () {
        $quota = app(SaveAddon::class)->handle(null, ['code' => 'extra-outlet', 'name' => 'Outlet tambahan', 'type' => 'quota', 'quota_key' => 'outlets', 'quota_amount' => 1, 'price_monthly' => '99000', 'price_yearly' => '990000']);
        $feature = app(SaveAddon::class)->handle(null, ['code' => 'self-order', 'name' => 'Self-order QR', 'type' => 'feature', 'feature_key' => 'self_order', 'price_monthly' => '50000', 'price_yearly' => '500000']);

        Livewire::test(ManageAddons::class)
            ->filterTable('type', Addon::TYPE_QUOTA)->assertCanSeeTableRecords([$quota])->assertCanNotSeeTableRecords([$feature])
            ->resetTableFilters()
            ->searchTable('self')->assertCanSeeTableRecords([$feature])->assertCanNotSeeTableRecords([$quota])
            ->callAction(TestAction::make('deactivate')->table($feature))
            ->assertHasNoActionErrors();

        expect($feature->refresh()->is_active)->toBeFalse();
    });
});

it('tombol tambah ada di header card tabel (bukan header halaman)', function (string $page) {
    Livewire::test($page)->assertActionExists(TestAction::make('create')->table());
})->with([ManagePlans::class, ManageAddons::class, ListSubscriptionInvoices::class]);

describe('Pendaftaran', function () {
    beforeEach(function () {
        Notification::fake();
        RateLimiter::clear('signup-h:127.0.0.1');
        RateLimiter::clear('signup-d:127.0.0.1');
    });

    $body = fn (array $o = []): array => ['business_name' => 'Kopi Fajar', 'business_type' => 'cafe', 'name' => 'Fajar', 'email' => 'fajar@kopifajar.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', ...$o];

    it('menyimpan setelan lanjutan; nilai kosong menghapus setelan', function () {
        Livewire::test(OnboardingSettings::class)
            ->set('data.trial_plan_id', $this->basic->id)
            ->set('data.signup_daily_limit', 20)
            ->set('data.signup_blocked_domains', ['Mailinator.com', '@tempmail.io'])
            ->set('data.signup_notify_emails', ['sales@gspos.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $s = app(SystemSettings::class);
        expect($s->trialPlanId())->toBe($this->basic->id)->and($s->signupDailyLimit())->toBe(20)
            ->and($s->signupBlockedDomains())->toBe(['mailinator.com', 'tempmail.io'])
            ->and($s->signupNotifyEmails())->toBe(['sales@gspos.test']);

        Livewire::test(OnboardingSettings::class)->set('data.trial_plan_id', null)->call('save')->assertHasNoFormErrors();
        expect(app(SystemSettings::class)->trialPlanId())->toBeNull()
            ->and(SystemSetting::query()->find(SystemSettings::TRIAL_PLAN_ID))->toBeNull();
    });

    it('validasi domain & email notifikasi', function () {
        Livewire::test(OnboardingSettings::class)
            ->set('data.signup_blocked_domains', ['bukan domain'])
            ->call('save')
            ->assertHasErrors(['data.signup_blocked_domains']);

        expect(fn () => app(UpdateOnboardingSettings::class)->handle($this->admin, ['trial_days' => 14, 'signup_enabled' => true, 'signup_notify_emails' => ['bukan-email']]))
            ->toThrow(ValidationException::class);
    });

    it('daftar mandiri: domain diblokir ditolak, paket trial dipasang, tim diberi tahu', function () use ($body) {
        $s = app(SystemSettings::class);
        $s->setOnboardingExtras($this->basic->id, 0, ['mailinator.com'], ['sales@gspos.test'], null);
        auth('admin')->logout();

        $this->post('/register', $body(['email' => 'x@sub.mailinator.com']))->assertSessionHasErrors('email');
        expect(Tenant::query()->count())->toBe(0);

        $this->post('/register', $body())->assertRedirect();
        $tenant = Tenant::query()->sole();
        expect($tenant->plan_id)->toBe($this->basic->id)->and($tenant->status)->toBe(TenantStatus::Trial);
        Notification::assertSentOnDemand(TenantSignedUp::class, fn (TenantSignedUp $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === ['sales@gspos.test'] && $n->businessName === 'Kopi Fajar');
    });

    it('batas pendaftar per hari: kiriman berikutnya kembali ke form dengan pesan', function () use ($body) {
        app(SystemSettings::class)->setOnboardingExtras(null, 1, [], [], null);
        auth('admin')->logout();

        $this->post('/register', $body())->assertRedirect();
        auth('web')->logout();
        $this->post('/register', $body(['email' => 'lain@kopi.test']))->assertSessionHasErrors('email');

        expect(Tenant::query()->count())->toBe(1);
    });

    it('pesan saat pendaftaran ditutup tampil di halaman daftar', function () {
        $s = app(SystemSettings::class);
        $s->setOnboarding(14, false);
        $s->setOnboardingExtras(null, 0, [], [], 'Dibuka lagi 1 November');

        $this->get('/register')->assertOk()->assertSee('Dibuka lagi 1 November');
    });
});

describe('Keamanan', function () {
    it('pemeriksaan konfigurasi menandai debug, HTTPS, dan admin tanpa 2FA', function () {
        config(['app.debug' => true, 'app.url' => 'http://gspos.test', 'session.secure' => false]);
        Admin::factory()->create();

        $items = collect(app(SecurityChecklist::class)->items())->keyBy('key');

        expect($items['debug']['ok'])->toBeFalse()->and($items['https']['ok'])->toBeFalse()
            ->and($items['secure_cookie']['ok'])->toBeFalse()
            ->and($items['two_factor_all']['ok'])->toBeFalse()->and($items['two_factor_all']['value'])->toBe('1 dari 2 admin');

        config(['app.debug' => false, 'app.url' => 'https://gspos.id']);
        $items = collect(app(SecurityChecklist::class)->items())->keyBy('key');
        expect($items['debug']['ok'])->toBeTrue()->and($items['https']['ok'])->toBeTrue();
    });
});
