<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\Member\UpdateMemberProgram;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\MemberSetting;
use App\Models\Outlet;
use App\Models\OutletMemberProgram;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Program member (ADR 0013, Q60, member.settings): aktifkan program & pilih outlet yang ikut.
 * Aturan poin, tier, stempel, dan voucher ditambahkan di tahap berikutnya (M2–M7).
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
            ...$setting->only(['earn_amount', 'earn_points', 'redeem_value', 'min_redeem_points', 'max_redeem_percent', 'expiry_months']),
            'enabled' => $setting->enabled,
            'outlet_ids' => Outlet::query()->whereNotIn('id', $excluded)->pluck('id')->all(),
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
        app(UpdateMemberProgram::class)->handle((bool) $data['enabled'], $outletIds, array_map(fn (mixed $v): string => (string) $v, $rules));

        Notification::make()->success()->title('Program member disimpan')->send();
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
