<?php

declare(strict_types=1);

use App\Actions\Product\SetOptionAvailability;
use App\Actions\Product\SetProductAvailability;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Category;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Menu, pencarian, kategori, dialog opsi & keranjang di halaman pesan meja (ADR 0027 T2, SPEC Q137). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
});

function menuPage(object $test): Testable
{
    SelfOrderContext::set(SelfOrderContext::resolve($test->table->qr_token));

    return Livewire::test(OrderPage::class);
}

it('menu dikelompokkan per kategori (tab & scroll-spy); pencarian menyaring nama', function () {
    $drinks = Category::factory()->create(['name' => 'Minuman', 'sort_order' => 1]);
    $bakery = Category::factory()->create(['name' => 'Roti', 'sort_order' => 2]);
    $this->pos->coffee->update(['category_id' => $drinks->id]);
    $this->pos->croissant->update(['category_id' => $bakery->id]);

    $page = menuPage($this);
    $sections = $page->instance()->menuSections();
    expect(array_column($sections, 'name'))->toBe(['Minuman', 'Roti'])
        ->and($sections[0]['products']->pluck('name')->all())->toBe(['Es Kopi Susu']);

    $page->assertSee('id="sec-'.$drinks->id.'"', false)->assertSee('id="tab-'.$bakery->id.'"', false);

    $page->set('search', 'CROIS');
    $found = $page->instance()->menuSections();
    expect($found)->toHaveCount(1)->and($found[0]['name'])->toBe('Hasil pencarian')
        ->and($found[0]['products']->pluck('name')->all())->toBe(['Croissant']);

    $page->set('search', 'tidak-ada')->assertSee('Menu tidak ditemukan');
});

it('tombol + : produk tanpa opsi langsung masuk & digabung, jadi stepper; produk beropsi membuka dialog', function () {
    menuPage($this)
        ->call('quickAdd', $this->pos->croissant->id)
        ->call('quickAdd', $this->pos->croissant->id)
        ->assertSet('cart.0.qty', 2)
        ->assertCount('cart', 1)
        ->assertSet('pickProductId', null)
        ->assertSeeHtml('aria-label="Kurangi Croissant"')
        ->call('decrementProduct', $this->pos->croissant->id)
        ->assertSet('cart.0.qty', 1)
        ->call('quickAdd', $this->pos->coffee->id)
        ->assertSet('pickProductId', $this->pos->coffee->id)
        ->assertCount('cart', 1);
});

it('dialog opsi: harga di tombol ikut opsi & jumlah, item sama digabung, error di bawah grupnya', function () {
    $page = menuPage($this)->call('addProduct', $this->pos->coffee->id)
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)->set('pickQty', 2);
    expect($page->instance()->pickTotal())->toBe('54000.00');
    $page->assertSee('Rp54.000');

    $page->call('savePick')
        ->call('addProduct', $this->pos->coffee->id)
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)
        ->call('savePick')
        ->assertCount('cart', 1)->assertSet('cart.0.qty', 3)
        ->call('addProduct', $this->pos->coffee->id)
        ->set("pickOptions.{$this->pos->size->id}", '')
        ->call('savePick')
        ->assertSet('pickErrorGroup', $this->pos->size->id)
        ->assertSet('pickError', 'Pilih 1 Ukuran')
        ->assertSeeHtml('id="g-error-'.$this->pos->size->id.'"');

    // Opsi yang habis di outlet tidak bisa dipilih lewat request yang dimanipulasi (keranjang tersimpan dikosongkan dulu)
    session()->forget(OrderPage::cartKey($this->table->id));
    app(SetOptionAvailability::class)->handle($this->pos->large, $this->pos->outlet, false);
    menuPage($this)->call('addProduct', $this->pos->coffee->id)
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)
        ->call('savePick')
        ->assertSet('pickError', 'Ukuran: pilihan sedang habis')
        ->assertCount('cart', 0);
});

it('keranjang: ubah baris lewat dialog, hapus bisa diurungkan, harga baris = pratinjau', function () {
    $page = menuPage($this)
        ->call('quickAdd', $this->pos->croissant->id)
        ->call('addProduct', $this->pos->coffee->id)->call('savePick')
        ->call('show', 'cart')
        ->assertSee('Rp24.000')->assertSee('Rp22.000');

    $page->call('editLine', 1)
        ->assertSet('pickIndex', 1)
        ->assertSet("pickOptions.{$this->pos->size->id}", $this->pos->regular->id)
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)
        ->set('pickNotes', 'less ice')
        ->call('savePick')
        ->assertCount('cart', 2)
        ->assertSet('cart.1.option_names', ['Large'])
        ->assertSet('cart.1.notes', 'less ice')
        ->assertSee('Rp27.000')
        ->call('removeLine', 0)
        ->assertCount('cart', 1)
        ->assertDispatched('so-toast', message: 'Croissant dihapus', undo: true)
        ->call('undoRemove')
        ->assertCount('cart', 2)
        ->assertSet('cart.0.name', 'Croissant')
        ->call('decrement', 0)
        ->assertCount('cart', 1)
        ->assertDispatched('so-toast');
});

it('maks. 50 item per pesanan berlaku di + cepat, stepper, dan dialog', function () {
    $page = menuPage($this);
    for ($i = 0; $i < SubmitSelfOrder::MAX_ITEMS; $i++) {
        $page->call('quickAdd', $this->pos->croissant->id);
    }

    $page->assertSet('cart.0.qty', 50)
        ->call('quickAdd', $this->pos->croissant->id)
        ->assertSet('cart.0.qty', 50)
        ->assertSet('error', 'Maksimal 50 item per pesanan')
        ->call('increment', 0)->assertSet('cart.0.qty', 50)
        ->call('addProduct', $this->pos->coffee->id)->call('savePick')
        ->assertSet('pickError', 'Maksimal 50 item per pesanan')
        ->assertCount('cart', 1);
});

it('produk habis tidak bisa ditambah dari kartu maupun dialog', function () {
    app(SetProductAvailability::class)->handle($this->pos->croissant, $this->pos->outlet, false);

    menuPage($this)
        ->assertSee('Habis')
        ->call('quickAdd', $this->pos->croissant->id)
        ->assertSet('error', 'Menu ini sedang habis')
        ->assertCount('cart', 0);
});

it('keamanan: isi keranjang & baris yang diurungkan tidak bisa diubah dari browser', function (string $property, mixed $value) {
    menuPage($this)->set($property, $value);
})->with([
    'keranjang' => ['cart', [['product_id' => 'x', 'name' => '<b>Gratis</b>', 'qty' => 1, 'option_ids' => [], 'option_names' => [], 'notes' => null]]],
    'baris dihapus' => ['removed', ['index' => 0, 'line' => []]],
    'indeks ubah' => ['pickIndex', 3],
])->throws(CannotUpdateLockedPropertyException::class);
