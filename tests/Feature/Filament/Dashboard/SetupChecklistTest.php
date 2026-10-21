<?php

declare(strict_types=1);

use App\Actions\Catalog\ApplyMenuTemplate;
use App\Actions\Tenant\UpdateTenantPreferences;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Widgets\SetupChecklistWidget;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SetupProgress;
use App\Support\TenantContext;
use App\Support\TenantPreferences;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    // Outlet baru tanpa alamat = langkah profil outlet belum selesai
    $this->outlet = Outlet::factory()->create(['address' => null]);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->actingAs($this->owner);
    TenantContext::set($this->owner->tenant_id);
});

it('template kafe membuat kategori, grup opsi, dan produk lewat Action', function () {
    $count = app(ApplyMenuTemplate::class)->handle('cafe', $this->owner);

    $latte = Product::query()->where('name', 'Caffe Latte')->sole();

    expect($count)->toBe(10)
        ->and(Category::query()->pluck('name')->all())->toBe(['Kopi', 'Non Kopi', 'Makanan'])
        ->and(OptionGroup::query()->count())->toBe(2)
        ->and($latte->price)->toBe('25000.00')
        ->and($latte->optionGroups()->pluck('option_groups.name')->all())->toBe(['Ukuran', 'Gula'])
        ->and(Product::query()->where('name', 'Croissant')->sole()->optionGroups()->count())->toBe(0);
});

it('template hanya untuk katalog kosong & template tidak dikenal ditolak', function () {
    Category::factory()->create();

    expect(fn () => app(ApplyMenuTemplate::class)->handle('warung', $this->owner))->toThrow(BusinessException::class)
        ->and(fn () => app(ApplyMenuTemplate::class)->handle('pabrik', $this->owner))->toThrow(BusinessException::class)
        ->and(Product::query()->count())->toBe(0);
});

it('checklist tercentang otomatis dan hilang setelah semua selesai', function () {
    expect(SetupChecklistWidget::canView())->toBeTrue()
        ->and(collect(SetupProgress::steps($this->owner->tenant))->pluck('done', 'key')->all())
        ->toBe(['outlet' => false, 'category' => false, 'product' => false]);

    app(ApplyMenuTemplate::class)->handle('warung', $this->owner);
    expect(collect(SetupProgress::steps($this->owner->tenant))->pluck('done', 'key')->all())
        ->toBe(['outlet' => false, 'category' => true, 'product' => true]);

    $this->travel(1)->minutes();
    $this->outlet->update(['address' => 'Jl. Merdeka 1']);

    expect(SetupProgress::isComplete($this->owner->tenant))->toBeTrue()
        ->and(SetupChecklistWidget::canView())->toBeFalse();
});

it('owner baru dari daftar mandiri juga melihat langkah verifikasi email', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();

    expect(collect(SetupProgress::steps($this->owner->tenant))->pluck('key')->all())->toContain('email');
});

it('tombol template di widget membuat menu; tidak tampil lagi setelah katalog terisi', function () {
    Livewire::test(SetupChecklistWidget::class)
        ->assertSee('Mulai berjualan')
        ->callAction(TestAction::make('applyTemplate'), ['template' => 'cafe'])
        ->assertNotified('10 produk contoh dibuat');

    Livewire::test(SetupChecklistWidget::class)->assertActionHidden('applyTemplate');
});

it('beranda menampilkan checklist untuk bisnis baru', function () {
    $this->get('/dashboard')->assertOk()->assertSee('Mulai berjualan')->assertSee('Lengkapi profil outlet');
});

it('owner bisa menyembunyikan panduan; manager tidak', function () {
    Livewire::test(SetupChecklistWidget::class)->assertActionVisible(TestAction::make('dismiss'));

    $this->actingAs(User::factory()->manager()->forOutlet($this->outlet)->create());
    Livewire::test(SetupChecklistWidget::class)->assertActionHidden(TestAction::make('dismiss'));

    $this->actingAs($this->owner);
    Livewire::test(SetupChecklistWidget::class)
        ->callAction(TestAction::make('dismiss'))
        ->assertNotified('Panduan disembunyikan');
    expect(TenantPreferences::setupDismissed($this->owner->tenant->refresh()))->toBeTrue()
        ->and(SetupProgress::shouldShow($this->owner->tenant))->toBeFalse();

    app(UpdateTenantPreferences::class)->handle($this->owner->tenant, [TenantPreferences::SETUP_DISMISSED => null]);
    expect(SetupProgress::shouldShow($this->owner->tenant->refresh()))->toBeTrue();
});

it('panduan tidak tampil untuk bisnis yang sudah punya transaksi selesai', function () {
    $pos = posSetup();
    $tenant = Tenant::query()->findOrFail($pos->tenantId);
    TenantContext::run($tenant->id, function () use ($pos): void {
        $pos->outlet->forceFill(['address' => null])->saveQuietly();
        Outlet::query()->whereKey($pos->outlet->id)->update(['updated_at' => $pos->outlet->created_at]);
    });
    TenantContext::run($tenant->id, fn () => expect(SetupProgress::shouldShow($tenant))->toBeTrue());

    freshAuth();
    $this->withToken($pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 30000]],
    ], apiHeaders())->assertCreated();

    TenantContext::run($tenant->id, fn () => expect(SetupProgress::shouldShow($tenant->refresh()))->toBeFalse());
});
