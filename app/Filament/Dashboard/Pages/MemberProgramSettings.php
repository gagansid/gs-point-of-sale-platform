<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\UpdateBirthdayVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Forms\MoneyInput;
use App\Models\MemberSetting;
use App\Models\MemberTier;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Program member (ADR 0013, Q60, member.settings): aktifkan program, outlet yang ikut, aturan poin,
 * diskon member, tier (M5), dan voucher ulang tahun (M7).
 */
final class MemberProgramSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Program member';

    protected static ?string $title = 'Program member';

    protected static ?string $slug = 'settings/member-program';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission('member.settings');
    }

    public function mount(): void
    {
        $excluded = OutletMemberProgram::query()->where('is_enabled', false)->pluck('outlet_id')->all();

        $setting = MemberSetting::current();

        $this->settingsForm()->fill([
            ...$setting->only(['earn_amount', 'earn_points', 'redeem_value', 'min_redeem_points', 'max_redeem_percent', 'expiry_months', 'member_discount_percent']),
            'enabled' => $setting->enabled,
            'outlet_ids' => Outlet::query()->whereNotIn('id', $excluded)->pluck('id')->all(),
            // Kunci acak per baris (pola Repeater); id tier lama dibawa di field tersembunyi
            'birthday' => $setting->birthdayVoucher(),
            'tiers' => MemberTier::ordered()->mapWithKeys(fn (MemberTier $t): array => [(string) Str::uuid() => [
                'id' => $t->id, 'name' => $t->name, 'min_spend_12m' => $t->min_spend_12m,
                'point_multiplier' => $t->point_multiplier, 'discount_percent' => $t->discount_percent,
            ]])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $rules = array_map(fn (array $r): array => array_values(array_diff($r, ['sometimes'])), UpdateMemberProgram::ruleValidation());

        return $schema
            ->statePath('data')
            ->disabled(! self::canEdit())
            ->components([
                Section::make('Program member')
                    ->description('Member berlaku di semua outlet bisnis ini. Data pelanggan disimpan dengan persetujuan mereka')
                    ->schema([
                        Placeholder::make('portal')->label('Halaman member')
                            ->content(fn (): HtmlString => new HtmlString('<a href="'.e(self::portalUrl()).'" target="_blank" rel="noopener" class="text-primary-600 underline">'.e(self::portalUrl()).'</a><br><span class="text-sm text-gray-500">Bagikan ke pelanggan (mis. QR di meja/kasir): daftar sendiri, lihat poin, dan kode QR untuk kasir</span>'))
                            ->visible(fn (Get $get): bool => (bool) $get('enabled')),
                        Toggle::make('enabled')->label('Aktifkan program member')->live()
                            ->helperText('Nonaktif: member tidak bisa dicari, didaftarkan, atau dipasang ke transaksi baru. Data member tetap ada'),
                        CheckboxList::make('outlet_ids')->label('Outlet yang ikut')
                            ->options(fn (): array => Outlet::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Outlet yang tidak dicentang tetap bisa memasang member (tercatat kunjungan) tanpa benefit')
                            ->visible(fn (Get $get): bool => (bool) $get('enabled') && Outlet::query()->count() > 1)
                            ->columns(2),
                    ]),

                Section::make('Poin')
                    ->description('Poin dihitung dari subtotal setelah diskon, sebelum pajak & service; masuk saat transaksi selesai')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('earn_amount')->label('Setiap belanja')->prefix('Rp')->numeric()->required()->rules($rules['earn_amount']),
                        TextInput::make('earn_points')->label('Dapat poin')->numeric()->required()->rules($rules['earn_points'])->suffix('poin'),
                        TextInput::make('expiry_months')->label('Masa berlaku')->numeric()->required()->rules($rules['expiry_months'])
                            ->suffix('bulan')->helperText('0 = tidak kedaluwarsa'),
                        TextInput::make('redeem_value')->label('Nilai 1 poin')->prefix('Rp')->numeric()->required()->rules($rules['redeem_value'])
                            ->helperText('Saat poin dipakai membayar'),
                        TextInput::make('min_redeem_points')->label('Minimal tukar')->numeric()->required()->rules($rules['min_redeem_points'])->suffix('poin'),
                        TextInput::make('max_redeem_percent')->label('Maksimal per transaksi')->numeric()->required()->rules($rules['max_redeem_percent'])
                            ->suffix('% tagihan'),
                    ]),

                Section::make('Diskon member')
                    ->description('Otomatis untuk setiap transaksi ber-member di outlet yang ikut program, setelah diskon kasir; tidak dihitung ke batas diskon kasir')
                    ->visible(fn (Get $get): bool => (bool) $get('enabled'))
                    ->schema([
                        TextInput::make('member_discount_percent')->label('Diskon')->numeric()->required()->rules($rules['member_discount_percent'])
                            ->suffix('%')->helperText('0 = tanpa diskon member'),
                    ]),

                self::tierSection(),
                self::birthdaySection(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Simpan')->submit('save')->keyBindings(['mod+s'])
                            ->visible(fn (): bool => self::canEdit()),
                    ])->alignEnd(),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canEdit(), 403);

        $data = $this->settingsForm()->getState();
        $outletIds = array_key_exists('outlet_ids', $data)
            ? array_values(array_map('strval', (array) $data['outlet_ids']))
            // Disembunyikan (satu outlet / program mati): pertahankan pilihan yang ada
            : Outlet::query()->whereNotIn('id', OutletMemberProgram::query()->where('is_enabled', false)->pluck('outlet_id'))->pluck('id')->all();

        $rules = array_intersect_key($data, UpdateMemberProgram::ruleValidation());
        try {
            DB::transaction(function () use ($data, $outletIds, $rules): void {
                app(UpdateMemberProgram::class)->handle((bool) $data['enabled'], $outletIds, array_map(fn (mixed $v): string => (string) $v, $rules));

                if (array_key_exists('birthday', $data)) {
                    // Field tersembunyi (voucher nonaktif) tidak ikut state: nilai lama dipertahankan
                    app(UpdateBirthdayVoucher::class)->handle([...MemberSetting::current()->birthdayVoucher(), ...(array) $data['birthday']]);
                }

                if (array_key_exists('tiers', $data)) {
                    app(SaveMemberTiers::class)->handle(array_values(array_map(fn (array $t): array => [
                        'id' => filled($t['id'] ?? null) ? (string) $t['id'] : null,
                        'name' => (string) ($t['name'] ?? ''),
                        'min_spend_12m' => (string) ($t['min_spend_12m'] ?? '0'),
                        'point_multiplier' => (string) ($t['point_multiplier'] ?? '1'),
                        'discount_percent' => (string) ($t['discount_percent'] ?? '0'),
                    ], (array) $data['tiers'])));
                }
            });
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Program member disimpan')->send();
    }

    /** Voucher ulang tahun (M7, Q66): dibuat otomatis di awal bulan lahir, berlaku sampai akhir bulan. */
    private static function birthdaySection(): Section
    {
        $percent = fn (Get $get): bool => $get('birthday.type') === DiscountType::Percent->value;

        return Section::make('Voucher ulang tahun')
            ->description('Dibuat otomatis untuk member di bulan lahirnya (sekali, berlaku sampai akhir bulan). Butuh tanggal lahir member')
            ->visible(fn (Get $get): bool => (bool) $get('enabled'))
            ->columns(2)
            ->schema([
                Toggle::make('birthday.enabled')->label('Aktifkan voucher ulang tahun')->live()->columnSpanFull(),
                ToggleButtons::make('birthday.type')->label('Jenis potongan')->inline()->live()
                    ->options([DiscountType::Fixed->value => 'Nominal', DiscountType::Percent->value => 'Persen'])
                    ->visible(fn (Get $get): bool => (bool) $get('birthday.enabled')),
                TextInput::make('birthday.value')->label('Nilai')->numeric()->required()->minValue(0)
                    ->maxValue(fn (Get $get): ?int => $percent($get) ? 100 : null)
                    ->prefix(fn (Get $get): ?string => $percent($get) ? null : 'Rp')
                    ->suffix(fn (Get $get): ?string => $percent($get) ? '%' : null)
                    ->visible(fn (Get $get): bool => (bool) $get('birthday.enabled')),
                MoneyInput::make('birthday.max_discount')->label('Maks. potongan')->helperText('Kosong = tanpa batas')
                    ->visible(fn (Get $get): bool => (bool) $get('birthday.enabled') && $percent($get)),
                MoneyInput::make('birthday.min_spend')->label('Minimal belanja')
                    ->visible(fn (Get $get): bool => (bool) $get('birthday.enabled')),
            ]);
    }

    /** Tier member (M5, Q64): tabel ringkas seperti opsi produk, tombol tambah di kanan atas card. */
    private static function tierSection(): Section
    {
        $rules = SaveMemberTiers::fieldRules();

        return Section::make('Tier member')
            ->description('Naik/turun otomatis dari total belanja 12 bulan terakhir (dievaluasi saat transaksi selesai & setiap hari). Member di bawah tier terendah tanpa tier')
            ->visible(fn (Get $get): bool => (bool) $get('enabled'))
            ->headerActions([
                Action::make('addTier')->label('Tambah tier')->icon(Heroicon::OutlinedPlus)->size('sm')
                    ->visible(fn (): bool => self::canEdit())
                    ->disabled(fn (Get $get): bool => count($get('tiers') ?? []) >= SaveMemberTiers::MAX_TIERS)
                    ->action(fn (Get $get, Set $set) => $set('tiers', [
                        ...($get('tiers') ?? []),
                        (string) Str::uuid() => ['id' => null, 'name' => null, 'min_spend_12m' => null, 'point_multiplier' => '1', 'discount_percent' => '0'],
                    ])),
            ])
            ->schema([
                Repeater::make('tiers')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Nama tier')->markAsRequired(),
                        TableColumn::make('Minimal belanja 12 bulan')->markAsRequired()->width('240px'),
                        TableColumn::make('Pengali poin')->width('140px'),
                        TableColumn::make('Diskon tambahan')->width('150px'),
                    ])
                    ->compact()
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('name')->hiddenLabel()->placeholder('Silver')->required()->rules($rules['name'])->distinct(),
                        MoneyInput::make('min_spend_12m')->hiddenLabel()->required()->rules($rules['min_spend_12m']),
                        TextInput::make('point_multiplier')->hiddenLabel()->numeric()->required()->rules($rules['point_multiplier'])->suffix('×'),
                        TextInput::make('discount_percent')->hiddenLabel()->numeric()->required()->rules($rules['discount_percent'])->suffix('%'),
                    ])
                    ->maxItems(SaveMemberTiers::MAX_TIERS)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addable(false)
                    ->deleteAction(fn (Action $action): Action => $action->tooltip('Hapus tier')),
            ]);
    }

    private static function portalUrl(): string
    {
        $user = auth()->user();

        return route('member.login', ['business' => $user instanceof User ? $user->tenant->slug : '']);
    }

    private static function canEdit(): bool
    {
        return auth()->user()?->can('member.settings') ?? false;
    }

    private function settingsForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form program member tidak ditemukan');
    }
}
