<?php

declare(strict_types=1);

use App\Actions\Catalog\ApplyMenuTemplate;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Widgets\SetupChecklistWidget;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\SetupProgress;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create();
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
