<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Member\Data\BenefitSelection;
use App\Actions\Member\Data\MemberData;
use App\Actions\Member\PayReceivable;
use App\Actions\Member\SaveMember;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\SetMemberCreditLimit;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\Data\RefundData;
use App\Actions\Order\QuoteOrder;
use App\Actions\Order\RefundOrder;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VoidOrder;
use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveCategory;
use App\Actions\Product\SaveOptionGroup;
use App\Actions\Product\SaveProduct;
use App\Actions\Promotion\SavePromotion;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\Shift\CloseShift;
use App\Actions\Shift\Data\CashMovementData;
use App\Actions\Shift\OpenShift;
use App\Actions\Shift\RecordCashMovement;
use App\Actions\Stock\CreateStockOpname;
use App\Actions\Stock\PostStockOpname;
use App\Actions\Stock\PostStockReceipt;
use App\Actions\Stock\ReceivePurchaseOrder;
use App\Actions\Stock\ReceiveStockTransfer;
use App\Actions\Stock\SaveOpnameCounts;
use App\Actions\Stock\SavePurchaseOrder;
use App\Actions\Stock\SaveStockReceipt;
use App\Actions\Stock\SaveStockTransfer;
use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SendPurchaseOrder;
use App\Actions\Stock\SendStockTransfer;
use App\Actions\User\Data\EmployeeData;
use App\Actions\User\SaveEmployee;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\ProductKind;
use App\Enums\PromotionType;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Member;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Promotion;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Shift\ShiftSummary;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Data demo lengkap untuk semua menu dashboard bisnis "Kopi Senja (Demo)" di semua outlet (lokal saja):
 * katalog kafe nyata (minuman, makanan, bahan baku + resep, opsi), karyawan, supplier, PO, stok masuk, transfer,
 * opname, member + kasbon, voucher, promo, meja, dan ±35 hari transaksi per outlet (shift, pesanan, void, retur,
 * kas keluar/masuk, open bill hari ini).
 *
 * Semua dibuat lewat Action bisnis (CheckoutOrder, PostStockReceipt, …) dengan jam tiruan (Carbon::setTestNow)
 * sehingga stok, HPP rata-rata, poin, dan laporan konsisten. HANYA MENAMBAH data; dilewati bila sudah pernah
 * dijalankan (penanda: supplier "CV Biji Kopi Nusantara").
 *
 *   php artisan db:seed --class=DemoFullDataSeeder
 */
final class DemoFullDataSeeder extends Seeder
{
    private const DAYS = 35;

    private const MARKER_SUPPLIER = 'CV Biji Kopi Nusantara';

    private Tenant $tenant;

    private User $owner;

    /** @var array<string, Outlet> kode → outlet */
    private array $outlets = [];

    /** @var array<string, Product> nama → produk/bahan */
    private array $p = [];

    /** @var array<string, OptionGroup> */
    private array $groups = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var array<string, array{device: Device, cashiers: list<User>, supervisor: User, weight: float}> */
    private array $stations = [];

    /** @var array<string, PaymentMethod> kategori → metode */
    private array $methods = [];

    /** @var list<Member> */
    private array $members = [];

    /** @var array<string, string> id member → PIN (bukti untuk kasbon) */
    private array $memberPins = [];

    /** @var array{orders: int, skipped: int, voids: int, refunds: int} */
    private array $stats = ['orders' => 0, 'skipped' => 0, 'voids' => 0, 'refunds' => 0];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoFullDataSeeder hanya untuk environment local/testing');
        }

        $this->tenant = Tenant::query()->where('slug', 'kopi-senja-demo')->firstOrFail();
        // Email (verifikasi, tagihan, ringkasan) tidak dikirim saat seeding
        Notification::fake();
        mt_srand(2026);

        TenantContext::run($this->tenant->id, function (): void {
            if (Supplier::query()->where('name', self::MARKER_SUPPLIER)->exists()) {
                $this->command->info('Data demo lengkap sudah ada — dilewati.');

                return;
            }

            $this->owner = User::query()->where('role', UserRole::Owner)->where('email', 'owner@demo.test')->firstOrFail();
            Auth::guard('web')->setUser($this->owner);
            $this->outlets = Outlet::query()->orderBy('created_at')->get()->keyBy('code')->all();

            $start = CarbonImmutable::now('Asia/Jakarta')->subDays(self::DAYS)->setTime(7, 0);
            $this->at($start);

            $this->outletSettings();
            $this->catalog();
            $this->people();
            $this->suppliers();
            $this->programs();
            $this->tables();
            $this->initialStock($start);

            $this->history($start);

            Carbon::setTestNow();
            $this->command->info(sprintf('Selesai: %d transaksi, %d void, %d retur (%d dilewati karena aturan bisnis).',
                $this->stats['orders'], $this->stats['voids'], $this->stats['refunds'], $this->stats['skipped']));
        });

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------------------------------------- master data

    private function outletSettings(): void
    {
        // Target omzet realistis (sebelumnya contoh Rp120.000/hari)
        $this->outlets['DEMO01']->forceFill(['daily_sales_target' => '2200000.00', 'monthly_sales_target' => '66000000.00'])->save();

        $cilandak = $this->outlets['KSC01'] ?? null;
        $cilandak?->forceFill([
            'address' => $cilandak->address ?? 'Jl. Cilandak KKO No. 12, Jakarta Selatan',
            'tax_rate' => '11.00', 'service_charge_rate' => '0.00', 'rounding' => 100,
            'receipt_footer' => $cilandak->receipt_footer ?? 'Terima kasih sudah ngopi di Kopi Senja Cilandak',
            'daily_sales_target' => '1400000.00', 'monthly_sales_target' => '42000000.00',
        ])->save();
    }

    private function catalog(): void
    {
        $outlet = $this->outlets['DEMO01'];
        $saveCategory = app(SaveCategory::class);
        $cat = fn (string $name, int $sort): Category => Category::query()->where('name', $name)->first()
            ?? $saveCategory->handle(null, $name, $sort)['category'];

        $coffee = $cat('Kopi', 1);
        $nonCoffee = $cat('Non Kopi', 2);
        $tea = $cat('Teh', 3);
        $mains = $cat('Makanan Berat', 4);
        $snack = $cat('Snack', 5);
        $pastry = $cat('Pastry & Roti', 6);
        $bahan = $cat('Bahan Baku', 9);

        // Bahan baku (stok per outlet, HPP per satuan). Stok awal lewat Stok masuk dari supplier.
        $ingredients = [
            ['Biji Kopi House Blend', 'gram', '0.24', 2000], ['Biji Kopi Single Origin Gayo', 'gram', '0.38', 1000],
            ['Susu Full Cream', 'ml', '0.022', 10000], ['Susu Oat', 'ml', '0.055', 3000], ['Krimer Kental Manis', 'ml', '0.035', 3000],
            ['Sirup Gula Aren', 'ml', '0.06', 3000], ['Sirup Vanilla', 'ml', '0.12', 1000], ['Sirup Hazelnut', 'ml', '0.12', 1000],
            ['Bubuk Matcha', 'gram', '0.65', 500], ['Bubuk Cokelat', 'gram', '0.18', 1000], ['Teh Melati', 'gram', '0.12', 800],
            ['Teh Hitam', 'gram', '0.10', 800], ['Cup Plastik 16oz', 'pcs', '850', 300], ['Cup Panas 12oz', 'pcs', '700', 200],
            ['Beras', 'gram', '0.014', 10000], ['Telur', 'butir', '2200', 120], ['Ayam Fillet', 'gram', '0.06', 5000],
        ];
        foreach ($ingredients as $i => [$name, $unit, $cost, $min]) {
            $this->p[$name] = $this->product($outlet, [
                'name' => $name, 'kind' => ProductKind::Ingredient, 'unit' => $unit, 'category_id' => $bahan->id,
                'price' => 0, 'cost_price' => $cost, 'track_stock' => true, 'min_stock' => $min, 'sku' => sprintf('BHN-%03d', $i + 1),
            ]);
        }

        // Opsi: ukuran (+bahan), level gula, es, tambahan (extra shot memakai biji kopi)
        $saveGroup = app(SaveOptionGroup::class);
        $group = function (string $name, int $min, int $max, array $options) use ($saveGroup): OptionGroup {
            return OptionGroup::query()->where('name', $name)->first()
                ?? $saveGroup->handle(null, OptionGroupData::fromArray(['name' => $name, 'min_select' => $min, 'max_select' => $max, 'options' => $options]))['group'];
        };
        // Pilihan wajib dengan bawaan (Q129); Tambahan tetap opsional
        $this->groups['Ukuran'] = $group('Ukuran', 1, 1, [['name' => 'Regular', 'price_delta' => 0, 'is_default' => true], ['name' => 'Large', 'price_delta' => 5000]]);
        $this->groups['Gula'] = $group('Gula', 1, 1, [['name' => 'Normal', 'is_default' => true], ['name' => 'Less sugar'], ['name' => 'Tanpa gula']]);
        $this->groups['Es'] = $group('Level Es', 1, 1, [['name' => 'Normal ice', 'is_default' => true], ['name' => 'Less ice'], ['name' => 'Tanpa es']]);
        $this->groups['Tambahan'] = $group('Tambahan', 0, 3, [
            ['name' => 'Extra shot espresso', 'price_delta' => 6000, 'components' => [['component_id' => $this->p['Biji Kopi House Blend']->id, 'qty' => 18]]],
            ['name' => 'Ganti susu oat', 'price_delta' => 7000, 'components' => [['component_id' => $this->p['Susu Oat']->id, 'qty' => 150]]],
            ['name' => 'Sirup vanilla', 'price_delta' => 4000, 'components' => [['component_id' => $this->p['Sirup Vanilla']->id, 'qty' => 15]]],
            ['name' => 'Sirup hazelnut', 'price_delta' => 4000, 'components' => [['component_id' => $this->p['Sirup Hazelnut']->id, 'qty' => 15]]],
        ]);
        $this->groups['Pedas'] = $group('Level Pedas', 1, 1, [['name' => 'Tidak pedas', 'is_default' => true], ['name' => 'Sedang'], ['name' => 'Pedas']]);

        $drink = [$this->groups['Ukuran']->id, $this->groups['Gula']->id, $this->groups['Es']->id, $this->groups['Tambahan']->id];
        $hot = [$this->groups['Gula']->id, $this->groups['Tambahan']->id];
        $b = fn (string $n): string => $this->p[$n]->id;
        $cupCold = ['component_id' => $b('Cup Plastik 16oz'), 'qty' => 1];
        $cupHot = ['component_id' => $b('Cup Panas 12oz'), 'qty' => 1];
        $shot = fn (int $g = 18): array => ['component_id' => $b('Biji Kopi House Blend'), 'qty' => $g];

        // [nama, kategori, harga, SKU, opsi, resep, favorit]
        $menu = [
            ['Es Kopi Susu Gula Aren', $coffee, 23000, 'KOP-001', $drink, [$shot(), ['component_id' => $b('Susu Full Cream'), 'qty' => 150], ['component_id' => $b('Sirup Gula Aren'), 'qty' => 25], $cupCold], true],
            ['Kopi Susu Pandan', $coffee, 25000, 'KOP-002', $drink, [$shot(), ['component_id' => $b('Susu Full Cream'), 'qty' => 150], ['component_id' => $b('Krimer Kental Manis'), 'qty' => 20], $cupCold], false],
            ['Cappuccino', $coffee, 28000, 'KOP-003', $hot, [$shot(), ['component_id' => $b('Susu Full Cream'), 'qty' => 160], $cupHot], false],
            ['Flat White', $coffee, 30000, 'KOP-004', $hot, [$shot(36), ['component_id' => $b('Susu Full Cream'), 'qty' => 130], $cupHot], false],
            ['Espresso', $coffee, 18000, 'KOP-005', [$this->groups['Tambahan']->id], [$shot()], false],
            ['V60 Gayo', $coffee, 32000, 'KOP-006', [], [['component_id' => $b('Biji Kopi Single Origin Gayo'), 'qty' => 15], $cupHot], false],
            ['Es Kopi Hazelnut', $coffee, 27000, 'KOP-007', $drink, [$shot(), ['component_id' => $b('Susu Full Cream'), 'qty' => 140], ['component_id' => $b('Sirup Hazelnut'), 'qty' => 20], $cupCold], false],
            ['Es Cokelat', $nonCoffee, 25000, 'NKO-001', $drink, [['component_id' => $b('Bubuk Cokelat'), 'qty' => 30], ['component_id' => $b('Susu Full Cream'), 'qty' => 180], $cupCold], true],
            ['Red Velvet Latte', $nonCoffee, 27000, 'NKO-002', $drink, [['component_id' => $b('Susu Full Cream'), 'qty' => 180], $cupCold], false],
            ['Lemon Tea', $tea, 18000, 'TEH-001', [$this->groups['Gula']->id, $this->groups['Es']->id], [['component_id' => $b('Teh Hitam'), 'qty' => 6], $cupCold], false],
            ['Es Teh Melati', $tea, 12000, 'TEH-002', [$this->groups['Gula']->id, $this->groups['Es']->id], [['component_id' => $b('Teh Melati'), 'qty' => 6], $cupCold], false],
            ['Lychee Tea', $tea, 22000, 'TEH-003', [$this->groups['Gula']->id, $this->groups['Es']->id], [['component_id' => $b('Teh Hitam'), 'qty' => 6], $cupCold], false],
            ['Nasi Goreng Kampung', $mains, 35000, 'MKN-001', [$this->groups['Pedas']->id], [['component_id' => $b('Beras'), 'qty' => 200], ['component_id' => $b('Telur'), 'qty' => 1]], true],
            ['Rice Bowl Ayam Sambal Matah', $mains, 38000, 'MKN-002', [$this->groups['Pedas']->id], [['component_id' => $b('Beras'), 'qty' => 180], ['component_id' => $b('Ayam Fillet'), 'qty' => 120]], false],
            ['Mie Goreng Telur', $mains, 30000, 'MKN-003', [$this->groups['Pedas']->id], [['component_id' => $b('Telur'), 'qty' => 1]], false],
            ['Kentang Goreng', $snack, 22000, 'SNK-001', [], [], false],
            ['Pisang Goreng Keju', $snack, 20000, 'SNK-002', [], [], false],
            ['Roti Bakar Cokelat Keju', $snack, 24000, 'SNK-003', [], [], false],
        ];
        foreach ($menu as [$name, $category, $price, $sku, $groups, $recipe, $fav]) {
            $this->p[$name] = $this->product($outlet, [
                'name' => $name, 'category_id' => $category->id, 'price' => $price, 'sku' => $sku, 'is_favorite' => $fav,
                'option_group_ids' => $groups, 'components' => $recipe,
                // HPP produk resep dihitung dari bahan; tanpa resep diisi perkiraan
                'cost_price' => $recipe === [] ? (string) round($price * 0.35) : null,
            ]);
        }

        // Pastry dari supplier roti: stok per pcs
        foreach ([['Almond Croissant', 28000, 'PST-001', 13000], ['Cinnamon Roll', 26000, 'PST-002', 11000], ['Cheese Danish', 25000, 'PST-003', 10500]] as [$name, $price, $sku, $cost]) {
            $this->p[$name] = $this->product($outlet, [
                'name' => $name, 'category_id' => $pastry->id, 'price' => $price, 'sku' => $sku, 'cost_price' => (string) $cost,
                'track_stock' => true, 'min_stock' => 8,
            ]);
        }

        // Produk lama demo (Croissant/Banana Bread) ikut kategori pastry
        foreach (['Croissant', 'Banana Bread'] as $old) {
            $product = Product::query()->where('name', $old)->first();
            if ($product !== null) {
                $product->forceFill(['category_id' => $pastry->id])->save();
                $this->p[$old] = $product;
            }
        }
        foreach (['Es Kopi Susu', 'Americano', 'Caffe Latte', 'Matcha Latte', 'Teh Tarik'] as $old) {
            if (($product = Product::query()->where('name', $old)->first()) !== null) {
                $this->p[$old] = $product;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function product(Outlet $outlet, array $data): Product
    {
        return Product::query()->where('name', $data['name'])->first()
            ?? app(SaveProduct::class)->handle(null, ProductData::fromArray($data), $outlet, $this->owner)['product'];
    }

    private function people(): void
    {
        $kemang = $this->outlets['DEMO01'];
        $cilandak = $this->outlets['KSC01'];
        $save = app(SaveEmployee::class);

        $employee = function (string $name, UserRole $role, array $outletIds, string $pin) use ($save): User {
            $username = Str::lower(Str::before($name, ' '));

            return User::query()->where('name', $name)->first() ?? $save->handle(null, EmployeeData::fromArray([
                'name' => $name, 'role' => $role, 'email' => $username.'@demo.test', 'username' => $username,
                'password' => 'password', 'pin' => $pin, 'outlet_ids' => $outletIds, 'web_cashier_access' => true,
            ]), $this->owner)['user'];
        };

        $budi = User::query()->where('name', 'Budi')->firstOrFail();
        $sari = User::query()->where('name', 'Sari')->firstOrFail();
        $andi = $employee('Andi Pratama', UserRole::Cashier, [$kemang->id], '314159');
        $dewi = $employee('Dewi Lestari', UserRole::Cashier, [$kemang->id], '271828');
        $fajar = $employee('Fajar Nugroho', UserRole::Cashier, [$cilandak->id], '161803');
        $rina = $employee('Rina Maharani', UserRole::Cashier, [$cilandak->id], '141421');
        $maya = $employee('Maya Anggraini', UserRole::Supervisor, [$cilandak->id], '173205');
        User::query()->where('name', 'Manager Demo')->first()?->outlets()->syncWithoutDetaching([$kemang->id, $cilandak->id]);

        // Perangkat tablet khusus riwayat (shift lama perangkat lain tidak terganggu)
        $device = fn (Outlet $o, string $uid, string $name): Device => Device::query()->firstOrCreate(['device_uid' => $uid], ['outlet_id' => $o->id, 'name' => $name]);
        $this->stations = [
            'DEMO01' => ['device' => $device($kemang, 'demo-tab-kemang-bar', 'Tablet Bar Kemang'), 'cashiers' => [$budi, $andi, $dewi], 'supervisor' => $sari, 'weight' => 1.0],
            'KSC01' => ['device' => $device($cilandak, 'demo-tab-cilandak-01', 'Tablet Kasir Cilandak'), 'cashiers' => [$fajar, $rina], 'supervisor' => $maya, 'weight' => 0.65],
        ];

        $this->methods = PaymentMethod::query()->get()->keyBy(fn (PaymentMethod $m): string => $m->category->value)->all();
    }

    private function suppliers(): void
    {
        $save = app(SaveSupplier::class);
        $rows = [
            [self::MARKER_SUPPLIER, '0812-8800-1122', 'order@bijikopinusantara.id', 'Jl. Raya Bogor KM 21, Jakarta Timur', 'Biji kopi house blend & single origin, kirim tiap Senin'],
            ['PT Susu Segar Lembang', '0813-2211-4455', 'sales@susulembang.co.id', 'Jl. Raya Lembang No. 88, Bandung Barat', 'Susu full cream & oat, termin 14 hari'],
            ['Toko Bahan Kue Sentosa', '021-5551234', null, 'Pasar Mayestik Blok B No. 12, Jakarta Selatan', 'Sirup, bubuk cokelat & matcha, gula aren'],
            ['Roti Prancis Bakery', '0857-1000-2020', 'b2b@rotiprancis.id', 'Jl. Panglima Polim IX No. 5, Jakarta Selatan', 'Pastry titip jual, antar setiap pagi'],
            ['UD Kemasan Jaya', '0821-7788-9900', null, 'Jl. Pangeran Jayakarta No. 47, Jakarta Pusat', 'Cup, sedotan, paper bag'],
            ['Pasar Induk Kramat Jati (Pak Darto)', '0819-3344-5566', null, 'Pasar Induk Kramat Jati Los C, Jakarta Timur', 'Beras, telur, ayam fillet — bayar tunai'],
        ];
        foreach ($rows as [$name, $phone, $email, $address, $notes]) {
            $this->suppliers[$name] = Supplier::query()->where('name', $name)->first()
                ?? $save->handle(null, ['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'notes' => $notes]);
        }
    }

    private function programs(): void
    {
        $kemang = $this->outlets['DEMO01'];
        $cilandak = $this->outlets['KSC01'];

        // Kasbon member aktif, batas bawaan Rp300.000
        app(UpdateCreditProgram::class)->handle(true, '300000');
        $this->methods = PaymentMethod::query()->get()->keyBy(fn (PaymentMethod $m): string => $m->category->value)->all();

        $first = ['Andi', 'Budi', 'Citra', 'Dimas', 'Eka', 'Fitri', 'Gilang', 'Hana', 'Indra', 'Joko', 'Kartika', 'Lukman', 'Mega', 'Nadia', 'Oki',
            'Putri', 'Raka', 'Salsa', 'Tegar', 'Umi', 'Vina', 'Wahyu', 'Yoga', 'Zahra', 'Bayu', 'Dinda', 'Faisal', 'Gita', 'Hendra', 'Intan',
            'Kevin', 'Laras', 'Nanda', 'Rizky', 'Tiara', 'Yudha', 'Ayu', 'Bima', 'Cahya', 'Dafa'];
        $last = ['Saputra', 'Wijaya', 'Lestari', 'Pratama', 'Santoso', 'Rahmawati', 'Hidayat', 'Kusuma', 'Nugraha', 'Permata', 'Siregar', 'Halim', 'Utami', 'Setiawan'];
        $save = app(SaveMember::class);
        foreach ($first as $i => $name) {
            $full = $name.' '.$last[$i % count($last)];
            $phone = '08'.(12 + $i % 7).sprintf('%08d', 31_000_000 + $i * 7_391);
            $member = Member::query()->where('phone', $phone)->first() ?? $save->handle(null, MemberData::fromArray([
                'name' => $full, 'phone' => $phone, 'email' => $i % 3 === 0 ? Str::lower($name).'.'.Str::lower($last[$i % count($last)]).'@gmail.com' : null,
                'birth_day' => ($i * 7) % 28 + 1, 'birth_month' => ($i * 5) % 12 + 1, 'consent' => true,
                'notes' => $i % 9 === 0 ? 'Suka kopi less sugar' : null,
            ]), $i % 3 === 0 ? $cilandak : $kemang)['member'];
            if ($i < 6) {
                app(SetMemberCreditLimit::class)->handle($member, $i < 2 ? '500000' : null);
            }
            // Member langganan punya PIN: bukti saat kasbon (ADR 0023)
            $pins = ['2580', '1379', '4826', '7395', '5172', '3864', '9241', '6058', '8317', '2749'];
            if (isset($pins[$i])) {
                app(SetMemberPin::class)->handle($member, $pins[$i]);
                $this->memberPins[$member->id] = $pins[$i];
            }
            $this->members[] = $member;
        }

        $voucher = app(SaveVoucher::class);
        $vouchers = [
            ['code' => 'KOPIPAGI', 'name' => 'Diskon pagi Rp5.000', 'type' => DiscountType::Fixed->value, 'value' => '5000', 'min_spend' => '40000', 'quota' => 500, 'per_member_quota' => 4],
            ['code' => 'MEMBER20', 'name' => 'Member 20% (maks. Rp20.000)', 'type' => DiscountType::Percent->value, 'value' => '20', 'max_discount' => '20000', 'min_spend' => '60000', 'member_only' => true, 'per_member_quota' => 2],
            ['code' => 'CILANDAK10', 'name' => 'Pembukaan Cilandak 10%', 'type' => DiscountType::Percent->value, 'value' => '10', 'max_discount' => '15000', 'outlet_ids' => [$cilandak->id], 'quota' => 200],
        ];
        foreach ($vouchers as $data) {
            if (! Voucher::query()->where('code', $data['code'])->exists()) {
                $voucher->handle(null, [...$data, 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDays(60)->toDateTimeString(), 'is_active' => true]);
            }
        }

        $promotion = app(SavePromotion::class);
        $promos = [
            ['name' => 'Happy Hour Kopi 20%', 'type' => PromotionType::ItemDiscount->value, 'discount_type' => DiscountType::Percent->value, 'discount_value' => '20',
                'applies_to' => Promotion::APPLIES_CATEGORIES, 'category_ids' => [Category::query()->where('name', 'Kopi')->value('id')],
                'days' => [1, 2, 3, 4, 5], 'time_start' => '14:00', 'time_end' => '16:00', 'is_active' => true],
            ['name' => 'Beli 2 Pastry Gratis 1', 'type' => PromotionType::BuyGet->value, 'buy_qty' => 2, 'get_qty' => 1,
                'applies_to' => Promotion::APPLIES_CATEGORIES, 'category_ids' => [Category::query()->where('name', 'Pastry & Roti')->value('id')],
                'days' => [6, 7], 'is_active' => true],
            ['name' => 'Potongan Rp15.000 belanja Rp150.000', 'type' => PromotionType::OrderDiscount->value, 'discount_type' => DiscountType::Fixed->value,
                'discount_value' => '15000', 'min_subtotal' => '150000', 'is_active' => true],
        ];
        foreach ($promos as $data) {
            if (! Promotion::query()->where('name', $data['name'])->exists()) {
                $promotion->handle(null, [...$data, 'starts_on' => now('Asia/Jakarta')->toDateString(), 'ends_on' => now('Asia/Jakarta')->addDays(90)->toDateString()]);
            }
        }
    }

    private function tables(): void
    {
        $save = app(SaveDiningTable::class);
        $plan = ['DEMO01' => [['Indoor', 8], ['Outdoor', 6], ['Bar', 4]], 'KSC01' => [['Indoor', 6], ['Teras', 4]]];
        foreach ($plan as $code => $areas) {
            $outlet = $this->outlets[$code];
            $n = 1;
            foreach ($areas as [$area, $count]) {
                for ($i = 0; $i < $count; $i++, $n++) {
                    if (! DiningTable::query()->where('outlet_id', $outlet->id)->where('name', 'Meja '.$n)->exists()) {
                        $save->handle(null, $outlet, ['name' => 'Meja '.$n, 'area' => $area]);
                    }
                }
            }
        }
    }

    // --------------------------------------------------------------------------------------------- stok

    private function initialStock(CarbonImmutable $start): void
    {
        foreach ($this->outlets as $code => $outlet) {
            // Stok awal ±8 hari pemakaian
            $this->restock($outlet, $start->addMinutes(30), $code === 'DEMO01' ? 2.0 : 1.5, true);
        }

        // Stok minimum sama di semua outlet (SaveProduct hanya mengisi outlet pembuat)
        $kemang = $this->outlets['DEMO01']->id;
        foreach ($this->p as $product) {
            $min = ProductStock::query()->where('product_id', $product->id)->where('outlet_id', $kemang)->value('min_stock');
            if ($min !== null) {
                ProductStock::query()->where('product_id', $product->id)->whereNull('min_stock')->update(['min_stock' => $min]);
            }
        }
    }

    /** Stok masuk dari supplier (Stok masuk), kelipatan $scale; $all = semua bahan (stok awal). */
    private function restock(Outlet $outlet, CarbonImmutable $at, float $scale, bool $all = false): void
    {
        $this->at($at);
        // Kiriman ±4 hari pemakaian outlet Kemang ($scale 1); biji kopi utama juga dari PO mingguan
        $lots = [
            self::MARKER_SUPPLIER => [['Biji Kopi House Blend', 1600, '235'], ['Biji Kopi Single Origin Gayo', 250, '380']],
            'PT Susu Segar Lembang' => [['Susu Full Cream', 18000, '21.5'], ['Susu Oat', 1500, '54']],
            // Barang kering datang ±2× lebih jarang: jumlah per kiriman 2×
            'Toko Bahan Kue Sentosa' => [['Sirup Gula Aren', 3200, '58'], ['Sirup Vanilla', 400, '118'], ['Sirup Hazelnut', 700, '118'],
                ['Bubuk Matcha', 600, '640'], ['Bubuk Cokelat', 1200, '176'], ['Krimer Kental Manis', 1200, '34'], ['Teh Melati', 400, '118'], ['Teh Hitam', 400, '98']],
            'Roti Prancis Bakery' => [['Almond Croissant', 8, '13000'], ['Cinnamon Roll', 6, '11000'], ['Cheese Danish', 6, '10500'], ['Croissant', 5, '10000'], ['Banana Bread', 5, '8500']],
            'UD Kemasan Jaya' => [['Cup Plastik 16oz', 360, '850'], ['Cup Panas 12oz', 120, '700']],
            'Pasar Induk Kramat Jati (Pak Darto)' => [['Beras', 8000, '14'], ['Telur', 60, '2200'], ['Ayam Fillet', 2200, '62']],
        ];

        foreach ($lots as $supplier => $items) {
            // Supplier kering (sirup, kemasan) tidak setiap kali datang
            if (! $all && in_array($supplier, ['Toko Bahan Kue Sentosa', 'UD Kemasan Jaya'], true) && mt_rand(0, 1) === 0) {
                continue;
            }
            $rows = [];
            foreach ($items as [$name, $qty, $cost]) {
                if (! isset($this->p[$name])) {
                    continue;
                }
                $rows[] = ['product_id' => $this->p[$name]->id, 'qty' => max(1, (int) round($qty * $scale * (mt_rand(85, 115) / 100))), 'unit_cost' => $cost];
            }
            if ($rows === []) {
                continue;
            }
            $receipt = app(SaveStockReceipt::class)->handle(null, $outlet, [
                'supplier_id' => $this->suppliers[$supplier]->id, 'received_on' => $at->setTimezone('Asia/Jakarta')->toDateString(),
                'invoice_number' => 'INV/'.Str::upper(Str::substr(Str::slug($supplier, ''), 0, 4)).'/'.$at->format('ymd').'/'.mt_rand(100, 999),
                'notes' => null, 'items' => $rows,
            ]);
            app(PostStockReceipt::class)->handle($receipt, $this->owner);
        }
    }

    /** PO ke supplier kopi: dikirim, diterima (jadi stok masuk); satu PO terakhir masih menunggu. */
    private function purchaseOrder(Outlet $outlet, CarbonImmutable $at, bool $receive): void
    {
        $this->at($at);
        $po = app(SavePurchaseOrder::class)->handle(null, $outlet, [
            'supplier_id' => $this->suppliers[self::MARKER_SUPPLIER]->id,
            'ordered_on' => $at->setTimezone('Asia/Jakarta')->toDateString(),
            'expected_on' => $at->setTimezone('Asia/Jakarta')->addDays(2)->toDateString(),
            'notes' => 'Order mingguan biji kopi',
            'items' => [
                ['product_id' => $this->p['Biji Kopi House Blend']->id, 'qty' => 2000, 'unit_cost' => '235'],
                ['product_id' => $this->p['Biji Kopi Single Origin Gayo']->id, 'qty' => 250, 'unit_cost' => '380'],
            ],
        ]);
        app(SendPurchaseOrder::class)->handle($po, $this->owner);

        if ($receive) {
            $this->at($at->addDays(2));
            $receipt = app(ReceivePurchaseOrder::class)->handle($po->refresh());
            app(PostStockReceipt::class)->handle($receipt, $this->owner);
        }
    }

    private function transfer(CarbonImmutable $at): void
    {
        $this->at($at);
        $transfer = app(SaveStockTransfer::class)->handle(null, $this->owner, [
            'from_outlet_id' => $this->outlets['DEMO01']->id, 'to_outlet_id' => $this->outlets['KSC01']->id,
            'notes' => 'Cilandak kehabisan biji kopi & susu oat',
            'items' => [['product_id' => $this->p['Biji Kopi House Blend']->id, 'qty' => 1000], ['product_id' => $this->p['Susu Oat']->id, 'qty' => 500]],
        ]);
        app(SendStockTransfer::class)->handle($transfer, $this->owner);
        $this->at($at->addHours(3));
        app(ReceiveStockTransfer::class)->handle($transfer->refresh(), $this->owner);
    }

    /** Stok opname bahan baku: hasil hitung sedikit selisih (tumpah, susut). */
    private function opname(Outlet $outlet, CarbonImmutable $at, bool $post): void
    {
        $this->at($at);
        $opname = app(CreateStockOpname::class)->handle($outlet, [
            'category_id' => Category::query()->where('name', 'Bahan Baku')->value('id'),
            'counted_on' => $at->setTimezone('Asia/Jakarta')->toDateString(),
            'notes' => $post ? 'Opname mingguan bahan baku' : 'Opname akhir bulan (sedang dihitung)',
        ]);
        $counts = [];
        foreach ($opname->items as $item) {
            $system = (int) $item->system_qty;
            $counts[$item->id] = max(0, $system - (mt_rand(0, 3) === 0 ? (int) round($system * mt_rand(1, 4) / 100) : 0));
        }
        app(SaveOpnameCounts::class)->handle($opname, $counts, $opname->notes);
        if ($post) {
            app(PostStockOpname::class)->handle($opname->refresh(), $this->owner);
        }
    }

    // ------------------------------------------------------------------------------------------ transaksi

    private function history(CarbonImmutable $start): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        for ($d = 0; $d <= self::DAYS; $d++) {
            $day = $start->startOfDay()->addDays($d);
            $isToday = $day->isSameDay($today);
            $weekend = $day->isWeekend();

            // Stok: kiriman rutin tiap 4 hari, PO kopi mingguan, transfer & opname
            if ($d > 0 && $d % 4 === 0) {
                foreach ($this->outlets as $code => $outlet) {
                    $this->restock($outlet, $day->setTime(7, 15), $code === 'DEMO01' ? 1.0 : 0.8);
                }
            }
            if ($d % 7 === 2) {
                // PO minggu terakhir masih menunggu kiriman
                $this->purchaseOrder($this->outlets['DEMO01'], $day->setTime(9, 0), $d < self::DAYS - 6);
            }
            if ($d === 17) {
                $this->transfer($day->setTime(10, 0));
            }
            if ($d === self::DAYS - 3) {
                foreach ($this->outlets as $outlet) {
                    $this->opname($outlet, $day->setTime(7, 30), true);
                }
            }

            foreach ($this->stations as $code => $station) {
                $this->businessDay($this->outlets[$code], $station, $day, $weekend, $isToday);
            }

            if ($isToday) {
                $this->opname($this->outlets['DEMO01'], $day->setTime(7, 40), false);
            }
        }
    }

    /** @param array{device: Device, cashiers: list<User>, supervisor: User, weight: float} $station */
    private function businessDay(Outlet $outlet, array $station, CarbonImmutable $day, bool $weekend, bool $isToday): void
    {
        $device = $station['device'];
        $cashier = $station['cashiers'][$day->dayOfYear % count($station['cashiers'])];
        $openAt = $day->setTime(8, mt_rand(0, 10));
        $this->at($openAt);
        $firstShift = ! Shift::query()->where('device_id', $device->id)->exists();
        $shift = app(OpenShift::class)->handle($cashier, $device, $firstShift ? '300000.00' : null)['shift'];

        $count = (int) round(mt_rand($weekend ? 34 : 22, $weekend ? 48 : 34) * $station['weight']);
        $nowLocal = CarbonImmutable::now('Asia/Jakarta');
        $times = $this->orderTimes($day, $count);
        $orders = [];

        foreach ($times as $i => $at) {
            if ($isToday && $at->isAfter($nowLocal->subMinutes(20))) {
                break;
            }
            $this->at($at);
            if (($order = $this->sale($outlet, $device, $cashier)) !== null) {
                $orders[] = $order;
            }

            // Kas keluar sekali sehari: beli es batu / galon (operasional)
            if ($i === 3 && mt_rand(0, 2) > 0) {
                $this->cash($cashier, $device, 'out', 'operational', (string) (mt_rand(3, 9) * 5000), ['Beli es batu', 'Beli galon air', 'Ongkos parkir supplier', 'Beli gas'][mt_rand(0, 3)]);
            }
        }

        // Void (salah input) & retur sesekali, oleh supervisor
        if ($orders !== [] && mt_rand(0, 3) === 0) {
            $order = $orders[mt_rand(0, count($orders) - 1)];
            $this->at(CarbonImmutable::parse($order->created_at)->addMinutes(4));
            try {
                app(VoidOrder::class)->handle($station['supervisor'], $order, ['Salah input menu', 'Pelanggan batal pesan', 'Pesanan dobel'][mt_rand(0, 2)], null);
                $this->stats['voids']++;
            } catch (BusinessException) {
                $this->stats['skipped']++;
            }
        }
        if (count($orders) > 2 && mt_rand(0, 4) === 0) {
            $this->refund($station['supervisor'], $device, $orders[mt_rand(0, count($orders) - 2)]);
        }

        // Kasbon member dibayar beberapa hari kemudian (setoran tunai di shift)
        if (mt_rand(0, 5) === 0) {
            $this->payReceivable($cashier, $device);
        }

        if ($isToday) {
            $this->openBills($outlet, $device, $cashier);

            return;
        }

        $this->at($day->setTime(21, mt_rand(30, 50)));
        $summary = app(ShiftSummary::class)->for($shift->refresh());
        $expected = $summary['expected_cash'];
        // Hitung fisik: kadang selisih kecil (kembalian kurang/lebih)
        $diff = mt_rand(0, 6) === 0 ? (string) ((mt_rand(0, 1) === 0 ? -1 : 1) * mt_rand(1, 5) * 1000) : '0';
        $actual = Money::add($expected, $diff);
        app(CloseShift::class)->handle($cashier, $shift, $device, $actual, Money::max(Money::sub($actual, '300000.00'), '0.00'), $diff !== '0' ? 'Selisih kembalian' : null);
    }

    /**
     * Jam pesanan dengan puncak makan siang & sore.
     *
     * @return list<CarbonImmutable>
     */
    private function orderTimes(CarbonImmutable $day, int $count): array
    {
        $weights = [8 => 4, 9 => 5, 10 => 5, 11 => 8, 12 => 12, 13 => 10, 14 => 6, 15 => 7, 16 => 9, 17 => 9, 18 => 8, 19 => 7, 20 => 5, 21 => 2];
        $bag = [];
        foreach ($weights as $hour => $w) {
            $bag = [...$bag, ...array_fill(0, $w, $hour)];
        }
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $times[] = $day->setTime($bag[mt_rand(0, count($bag) - 1)], mt_rand(0, 59), mt_rand(0, 59));
        }
        usort($times, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $times;
    }

    /** Satu transaksi selesai lewat CheckoutOrder (promo otomatis, member, voucher, berbagai metode bayar). */
    private function sale(Outlet $outlet, Device $device, User $cashier): ?Order
    {
        $items = $this->basket();
        $member = mt_rand(0, 9) < 3 ? $this->members[mt_rand(0, count($this->members) - 1)] : null;
        $voucher = $member !== null && mt_rand(0, 9) === 0 ? ['KOPIPAGI', 'MEMBER20'][mt_rand(0, 1)] : null;
        $dineIn = mt_rand(0, 9) < 6;
        $pick = mt_rand(1, 100);
        $category = match (true) {
            // Kasbon: member langganan (batas bawaan Rp300.000) sesekali bayar nanti
            $member !== null && isset($this->memberPins[$member->id]) && $pick > 80 => PaymentCategory::Receivable,
            $pick <= 38 => PaymentCategory::Cash,
            $pick <= 76 => PaymentCategory::Qris,
            $pick <= 88 => PaymentCategory::Debit,
            $pick <= 94 => PaymentCategory::Transfer,
            default => PaymentCategory::Credit,
        };

        $base = [
            'order_type' => $dineIn ? 'dine_in' : 'takeaway',
            'table_label' => $dineIn ? 'Meja '.mt_rand(1, $outlet->code === 'DEMO01' ? 18 : 10) : null,
            'items' => $items,
            'member_id' => $member?->id,
            'voucher_code' => $voucher,
            'member_pin' => $member !== null && $category === PaymentCategory::Receivable ? $this->memberPins[$member->id] : null,
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $benefits = BenefitSelection::fromArray($base);
                $total = app(QuoteOrder::class)->handle($outlet, $items, null, '0.00', $member, $benefits)->grandTotal;
                $method = $this->methods[$category->value] ?? $this->methods['cash'];
                $payment = ['id' => (string) Str::uuid7(), 'payment_method_id' => $method->id, 'amount' => $total];
                if ($category === PaymentCategory::Cash) {
                    // Uang pas atau pecahan besar → kembalian
                    $tendered = (int) (ceil(((int) $total) / 50000) * 50000);
                    $payment['tendered'] = mt_rand(0, 2) === 0 ? (int) $total : max($tendered, (int) $total);
                } elseif ($method->requires_reference) {
                    $payment['reference'] = (string) mt_rand(100000, 999999);
                }

                $order = app(CheckoutOrder::class)->handle($cashier, $device, CheckoutData::fromArray([
                    ...$base, 'id' => (string) Str::uuid7(), 'payments' => [$payment],
                ]))['order'];
                $this->stats['orders']++;

                return $order;
            } catch (BusinessException $e) {
                // Voucher habis/tidak memenuhi syarat, kasbon melebihi batas, stok bahan: ulang tanpa benefit, tunai
                $base['voucher_code'] = null;
                $category = PaymentCategory::Cash;
                if ($attempt === 1) {
                    $this->stats['skipped']++;
                }
            }
        }

        return null;
    }

    /** @return list<array{product_id: string, qty: int, option_ids: list<string>}> */
    private function basket(): array
    {
        // Bobot popularitas (menu baru + menu demo lama)
        $popular = [
            'Es Kopi Susu Gula Aren' => 16, 'Es Kopi Susu' => 8, 'Kopi Susu Pandan' => 6, 'Cappuccino' => 6, 'Caffe Latte' => 6, 'Americano' => 6,
            'Flat White' => 3, 'Espresso' => 2, 'V60 Gayo' => 2, 'Es Kopi Hazelnut' => 4, 'Es Cokelat' => 6, 'Matcha Latte' => 5, 'Red Velvet Latte' => 3,
            'Lemon Tea' => 3, 'Es Teh Melati' => 5, 'Lychee Tea' => 3, 'Teh Tarik' => 2, 'Nasi Goreng Kampung' => 5, 'Rice Bowl Ayam Sambal Matah' => 4,
            'Mie Goreng Telur' => 3, 'Kentang Goreng' => 4, 'Pisang Goreng Keju' => 3, 'Roti Bakar Cokelat Keju' => 3,
            'Almond Croissant' => 3, 'Cinnamon Roll' => 2, 'Cheese Danish' => 2, 'Croissant' => 2, 'Banana Bread' => 2,
        ];
        $bag = [];
        foreach ($popular as $name => $w) {
            if (isset($this->p[$name])) {
                $bag = [...$bag, ...array_fill(0, $w, $name)];
            }
        }

        $lines = [];
        $n = [1, 1, 1, 2, 2, 2, 3, 3, 4][mt_rand(0, 8)];
        for ($i = 0; $i < $n; $i++) {
            $product = $this->p[$bag[mt_rand(0, count($bag) - 1)]];
            $product->loadMissing('optionGroups.options');
            $options = [];
            foreach ($product->optionGroups as $group) {
                if ($group->options->isEmpty()) {
                    continue;
                }
                $required = $group->min_select > 0;
                if ($required || mt_rand(0, 9) < ($group->name === 'Tambahan' ? 1 : 3)) {
                    $options[] = $group->name === 'Ukuran' && mt_rand(0, 9) < 7 ? $group->options->first()->id : $group->options->random()->id;
                }
            }
            $lines[] = ['product_id' => $product->id, 'qty' => mt_rand(0, 9) < 8 ? 1 : 2, 'option_ids' => $options];
        }

        return $lines;
    }

    private function refund(User $supervisor, Device $device, Order $order): void
    {
        $order->refresh()->load('items');
        if ($order->status !== OrderStatus::Completed || $order->items->isEmpty()) {
            return;
        }
        $item = $order->items->first();
        $this->at(CarbonImmutable::parse($order->created_at)->addMinutes(mt_rand(20, 90)));
        try {
            app(RefundOrder::class)->handle($supervisor, $device, $order, RefundData::fromArray([
                'id' => (string) Str::uuid7(),
                'items' => [['order_item_id' => $item->id, 'qty' => 1]],
                'reason' => ['Minuman terlalu manis, dibuat ulang', 'Salah pesanan', 'Produk tidak sesuai'][mt_rand(0, 2)],
                'method' => 'cash', 'restock' => false,
            ]), null);
            $this->stats['refunds']++;
        } catch (BusinessException) {
            $this->stats['skipped']++;
        }
    }

    private function cash(User $actor, Device $device, string $type, string $reason, string $amount, string $note): void
    {
        try {
            app(RecordCashMovement::class)->handle($actor, $device, CashMovementData::fromArray([
                'id' => (string) Str::uuid7(), 'type' => $type, 'reason' => $reason, 'amount' => $amount, 'note' => $note,
            ]));
        } catch (BusinessException) {
            $this->stats['skipped']++;
        }
    }

    private function payReceivable(User $cashier, Device $device): void
    {
        foreach ($this->members as $member) {
            $member->refresh();
            $balance = (string) $member->receivable_balance;
            if (bccomp($balance, '0', 2) <= 0) {
                continue;
            }
            try {
                app(PayReceivable::class)->handle($cashier, $member, $device, (string) Str::uuid7(), $balance, $this->methods['cash']->id, 'Pelunasan kasbon');
            } catch (BusinessException) {
                $this->stats['skipped']++;
            }

            return;
        }
    }

    /** Hari ini: 2 open bill meja yang belum dibayar. */
    private function openBills(Outlet $outlet, Device $device, User $cashier): void
    {
        foreach ([3, 7] as $table) {
            try {
                app(SaveOpenBill::class)->handle($cashier, $device, (string) Str::uuid7(), OpenBillData::fromArray([
                    'order_type' => 'dine_in', 'table_label' => 'Meja '.$table, 'items' => $this->basket(), 'notes' => 'Tamu masih menambah pesanan',
                ]));
            } catch (Throwable) {
                $this->stats['skipped']++;
            }
        }
    }

    private function at(CarbonImmutable $local): void
    {
        Carbon::setTestNow($local->utc());
    }
}
