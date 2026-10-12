<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Stock\CreateStockOpname;
use App\Actions\Stock\DeleteStockOpname;
use App\Actions\Stock\PostStockOpname;
use App\Actions\Stock\SaveOpnameCounts;
use App\Enums\StockDocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\StockOpnames\Pages\CountStockOpname;
use App\Filament\Dashboard\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Dashboard\Resources\StockOpnames\StockOpnameResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Stok opname I2 (ADR 0015, Q81). Croissant lacak stok 10 (modal 9.000), Donat lacak stok 4 (modal 5.000) di JKT01.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    Carbon::setTestNow('2026-10-12 03:00:00');
    $this->pos->croissant->forceFill(['cost_price' => '9000.00'])->save();
    $this->bread = Category::factory()->create(['name' => 'Roti']);
    $this->donut = Product::factory()->tracked(0)->create(['name' => 'Donat', 'cost_price' => '5000.00', 'category_id' => $this->bread->id]);
    stockOf($this->donut, $this->pos->outlet->id)->forceFill(['stock_qty' => 4])->save();
    $this->owner = User::factory()->owner()->create();
});

afterEach(fn () => Carbon::setTestNow());

function opname(object $test, array $data = []): StockOpname
{
    return app(CreateStockOpname::class)->handle($test->pos->outlet, ['id' => uuid(), 'counted_on' => '2026-10-12', ...$data]);
}

/** @return array<string, int|null> product_name => item id */
function opnameItems(StockOpname $opname): array
{
    return $opname->items->pluck('id', 'product_name')->all();
}

it('lembar hitung: produk lacak stok outlet (atau satu kategori) dengan stok sistem; nomor SO; id sama = lembar sama', function () {
    $id = uuid();
    $all = opname($this, ['id' => $id]);
    $again = opname($this, ['id' => $id]);
    $bread = opname($this, ['category_id' => $this->bread->id]);

    expect($all->number)->toBe('JKT01-SO-261012-0001')->and($again->id)->toBe($all->id)
        ->and($all->items->pluck('system_qty', 'product_name')->all())->toBe(['Croissant' => 10, 'Donat' => 4])
        ->and($bread->items->pluck('product_name')->all())->toBe(['Donat']);
});

it('posting: selisih terhadap stok SAAT posting, stok = fisik, riwayat opname, nilai selisih dari modal; tanpa hitungan dilewati', function () {
    $opname = opname($this);
    $items = opnameItems($opname);
    app(SaveOpnameCounts::class)->handle($opname, [$items['Croissant'] => '7', $items['Donat'] => null]);

    // Terjual 1 setelah lembar dibuat: stok 9, fisik 7 → selisih −2
    app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ]));

    $posted = app(PostStockOpname::class)->handle($opname, $this->owner);
    app(PostStockOpname::class)->handle($opname, $this->owner);

    $croissant = $posted->items->firstWhere('product_name', 'Croissant');
    expect($posted->status)->toBe(StockDocumentStatus::Posted)
        ->and($croissant->stock_at_post)->toBe(9)->and($croissant->difference)->toBe(-2)
        ->and($croissant->difference_value)->toBe('-18000.00')
        ->and($posted->difference_qty)->toBe(-2)->and($posted->difference_value)->toBe('-18000.00')
        ->and(stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty)->toBe(7)
        ->and(stockOf($this->donut, $this->pos->outlet->id)->stock_qty)->toBe(4)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->sole()->qty_change)->toBe(-2);
});

it('ditolak: qty negatif/pecahan, posting tanpa hitungan, ubah/hapus yang sudah diposting, tanggal masa depan', function () {
    $opname = opname($this);
    $items = opnameItems($opname);

    expect(fn () => app(SaveOpnameCounts::class)->handle($opname, [$items['Croissant'] => '-1']))->toThrow(BusinessException::class, 'bilangan bulat');
    expect(fn () => app(SaveOpnameCounts::class)->handle($opname, [$items['Croissant'] => '1.5']))->toThrow(BusinessException::class);
    expect(fn () => app(PostStockOpname::class)->handle($opname, $this->owner))->toThrow(BusinessException::class, 'minimal satu produk');

    app(SaveOpnameCounts::class)->handle($opname, [$items['Croissant'] => '10']);
    app(PostStockOpname::class)->handle($opname, $this->owner);
    expect(fn () => app(SaveOpnameCounts::class)->handle($opname, [$items['Croissant'] => '1']))->toThrow(BusinessException::class, 'tidak bisa diubah');
    expect(fn () => app(DeleteStockOpname::class)->handle($opname))->toThrow(BusinessException::class, 'tidak bisa dihapus');
    // Fisik sama dengan stok: tanpa riwayat opname
    expect(StockMovement::query()->where('type', StockMovementType::Opname)->count())->toBe(0);

    expect(fn () => opname($this, ['counted_on' => '2026-10-13']))->toThrow(BusinessException::class, 'masa depan');

    $draft = opname($this);
    app(DeleteStockOpname::class)->handle($draft);
    expect(StockOpname::query()->find($draft->id))->toBeNull();
});

it('isolasi: lembar & baris bisnis lain tidak bisa dibaca atau diubah', function () {
    $opname = opname($this);
    $foreignItem = $opname->items->first()->id;
    $other = posSetup();
    TenantContext::set($other->tenantId);

    expect(StockOpname::query()->find($opname->id))->toBeNull();
    $mine = app(CreateStockOpname::class)->handle($other->outlet, ['counted_on' => '2026-10-12']);
    app(SaveOpnameCounts::class)->handle($mine, [$foreignItem => '0']);

    TenantContext::set($this->pos->tenantId);
    expect($opname->items()->whereKey($foreignItem)->value('counted_qty'))->toBeNull();
});

it('dashboard: manager membuat lembar, mengisi hitungan, posting; supervisor tidak punya menu', function () {
    Filament::setCurrentPanel('dashboard');
    $manager = User::factory()->manager()->forOutlet($this->pos->outlet)->create();
    $this->actingAs($manager);
    CurrentOutlet::set($manager);

    Livewire::test(ListStockOpnames::class)
        ->callAction(TestAction::make('create')->table(), ['counted_on' => '2026-10-12', 'category_id' => $this->bread->id])
        ->assertHasNoActionErrors();
    $opname = StockOpname::query()->sole();

    $page = Livewire::test(CountStockOpname::class, ['record' => $opname->id]);
    $key = array_key_first($page->get('data.counts'));
    $page->set("data.counts.{$key}.counted_qty", 6)
        ->callAction('post')
        ->assertNotified('Stok opname diposting');

    expect($opname->refresh()->status)->toBe(StockDocumentStatus::Posted)
        ->and(stockOf($this->donut, $this->pos->outlet->id)->stock_qty)->toBe(6)
        ->and($opname->difference_value)->toBe('10000.00');

    $this->actingAs($this->pos->supervisor);
    expect(StockOpnameResource::canViewAny())->toBeFalse();
});
