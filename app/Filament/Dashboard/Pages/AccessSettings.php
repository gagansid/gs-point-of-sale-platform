<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Pages;

use App\Actions\User\UpdateRolePermissions;
use App\Enums\UserRole;
use App\Exceptions\BusinessException;
use App\Filament\Shared\Actions\SettingsFormActions;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnitEnum;

/**
 * Pengaturan → Hak akses (ADR 0026, access.manage = owner): centang permission per role (manager, supervisor, kasir),
 * dikelompokkan seperti menu. Owner selalu semua akses; langganan & hak akses tetap khusus owner. Disimpan lewat
 * UpdateRolePermissions; sama dengan bawaan = disimpan sebagai bawaan.
 */
final class AccessSettings extends Page
{
    use HasIconBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Hak akses';

    protected static ?string $title = 'Hak akses';

    protected static ?string $slug = 'settings/access';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasFullDashboard() && $user->can('access.manage');
    }

    public function getSubheading(): string
    {
        return 'Atur menu & aksi yang boleh dipakai tiap role. Owner selalu punya semua akses; langganan dan hak akses hanya untuk owner';
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('roles')
                    ->contained(false)
                    ->tabs(array_map(fn (UserRole $role): Tab => self::roleTab($role), PermissionCatalog::roles())),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([SettingsFormActions::make(self::getUrl())]),
        ]);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $state = $this->accessForm()->getState();

        try {
            DB::transaction(function () use ($state, $user): void {
                foreach (PermissionCatalog::roles() as $role) {
                    $chosen = self::chosen($state, $role);
                    // Sama dengan bawaan → simpan sebagai bawaan (status "Bawaan", ikut perubahan bawaan versi berikutnya)
                    $isDefault = UserRole::normalize($chosen) === UserRole::normalize($role->grantedPermissions());
                    app(UpdateRolePermissions::class)->handle($user, $role, $isDefault ? null : $chosen);
                }
            });
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->fillForm();
        Notification::make()->success()->title('Hak akses disimpan')
            ->body('Berlaku saat karyawan membuka halaman berikutnya; aplikasi kasir setelah dimuat ulang')->send();
    }

    private static function roleTab(UserRole $role): Tab
    {
        $key = $role->value;

        return Tab::make($role->getLabel())
            ->key("role-{$key}")
            ->icon(match ($role) {
                UserRole::Manager => Heroicon::OutlinedBriefcase,
                UserRole::Supervisor => Heroicon::OutlinedEye,
                default => Heroicon::OutlinedComputerDesktop,
            })
            ->badge(fn (): string => RolePermissions::isCustomized($role, TenantContext::idOrFail()) ? 'Diubah' : 'Bawaan')
            ->badgeColor(fn (): string => RolePermissions::isCustomized($role, TenantContext::idOrFail()) ? 'warning' : 'gray')
            ->schema([
                Actions::make([
                    Action::make("reset_{$key}")->label('Kembalikan bawaan')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                        ->visible(fn (): bool => RolePermissions::isCustomized($role, TenantContext::idOrFail()))
                        ->requiresConfirmation()
                        ->modalIcon(Heroicon::OutlinedArrowUturnLeft)
                        ->modalHeading("Kembalikan hak akses {$role->getLabel()}")
                        ->modalDescription('Semua centang role ini kembali ke bawaan gs.POS. Perubahan lain yang belum disimpan ikut dibatalkan.')
                        ->modalSubmitActionLabel('Kembalikan')
                        ->action(function (self $livewire) use ($role): void {
                            $user = auth()->user();
                            abort_unless($user instanceof User && self::canAccess(), 403);
                            app(UpdateRolePermissions::class)->handle($user, $role, null);
                            Notification::make()->success()->title("Hak akses {$role->getLabel()} kembali ke bawaan")->send();
                            $livewire->fillForm();
                        }),
                ])->key("reset-{$key}")->alignEnd(),
                ...array_map(fn (string $group, array $meta): Section => Section::make($meta['title'])
                    ->icon($meta['icon'])
                    ->description($meta['description'])
                    ->compact()
                    ->schema([
                        CheckboxList::make("{$key}.{$group}")->hiddenLabel()
                            ->options(array_map(fn (array $p): string => $p[0], $meta['permissions']))
                            ->descriptions(array_map(fn (array $p): string => $p[1], $meta['permissions']))
                            ->columns(['default' => 1, 'md' => 2])
                            ->bulkToggleable(),
                    ]), array_keys(PermissionCatalog::groups()), PermissionCatalog::groups()),
            ]);
    }

    /** Isi form dari hak akses yang berlaku (pengaturan owner atau bawaan), per role per kelompok. */
    private function fillForm(): void
    {
        $tenantId = TenantContext::idOrFail();
        $state = [];

        foreach (PermissionCatalog::roles() as $role) {
            $granted = RolePermissions::granted($role, $tenantId);

            foreach (PermissionCatalog::groups() as $group => $meta) {
                $state[$role->value][$group] = array_values(array_intersect(array_keys($meta['permissions']), $granted));
            }
        }

        $this->accessForm()->fill($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private static function chosen(array $state, UserRole $role): array
    {
        $chosen = [];

        foreach ((array) ($state[$role->value] ?? []) as $permissions) {
            foreach ((array) $permissions as $permission) {
                $chosen[] = (string) $permission;
            }
        }

        return $chosen;
    }

    private function accessForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Form hak akses tidak ditemukan');
    }
}
