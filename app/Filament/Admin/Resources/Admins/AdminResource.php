<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Admins;

use App\Actions\Admin\ManageAdmin;
use App\Filament\Admin\Resources\Admins\Pages\ManageAdmins;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Admin;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Sistem → Admin: akun super admin (tanpa akun default; akun pertama dari `php artisan pos:create-admin`).
 * Tambah, ubah, reset 2FA admin lain, hapus. Aksi sensitif minta kata sandi admin yang sedang login.
 */
final class AdminResource extends Resource
{
    protected static ?string $model = Admin::class;

    protected static ?string $slug = 'admins';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Admin';

    protected static ?string $modelLabel = 'admin';

    protected static ?string $pluralModelLabel = 'admin';

    protected static ?int $navigationSort = 5;

    protected static bool $isGloballySearchable = false;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return list<Component> */
    public static function formFields(bool $creating): array
    {
        return [
            TextInput::make('name')->label('Nama')->required()->maxLength(100)->autofocus(),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                ->unique(Admin::class, 'email', ignoreRecord: true),
            TextInput::make('password')->label($creating ? 'Kata sandi' : 'Kata sandi baru')->password()->revealable()
                ->required($creating)->dehydrated(fn (?string $state): bool => filled($state))
                // Sama dengan CreateAdmin/ManageAdmin: super admin memegang akses lintas tenant
                ->rule(Password::defaults()->min(12)->mixedCase()->symbols())
                ->helperText(($creating ? '' : 'Kosongkan bila tidak diganti. ').'Min. 12 karakter, huruf besar & kecil, simbol'),
            self::confirmPassword(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formFields(creating: false));
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable()
                    ->description(fn (Admin $record): ?string => $record->is(auth('admin')->user()) ? 'Anda' : null),
                TextColumn::make('email')->label('Email')->searchable()->sortable()->copyable()->copyMessage('Email disalin'),
                TextColumn::make('two_factor')->label('2FA')->badge()
                    ->state(fn (Admin $record): string => $record->hasTwoFactorEnabled() ? 'Aktif' : 'Belum')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'warning')
                    ->icon(fn (string $state): Heroicon => $state === 'Aktif' ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedExclamationTriangle),
                TextColumn::make('last_login_at')->label('Login terakhir')->since()->dateTimeTooltip('j M Y, H.i')->placeholder('Belum pernah')->sortable(),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('two_factor')->label('2FA')->trueLabel('Aktif')->falseLabel('Belum')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('app_authentication_secret'),
                        false: fn (Builder $query): Builder => $query->whereNull('app_authentication_secret'),
                    ),
                TernaryFilter::make('logged_in')->label('Pernah login')->trueLabel('Pernah')->falseLabel('Belum pernah')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('last_login_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('last_login_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('lg')
                    ->using(fn (Admin $record, array $data): Admin => app(ManageAdmin::class)->update(
                        $record, (string) $data['name'], (string) $data['email'], filled($data['password'] ?? null) ? (string) $data['password'] : null,
                    )),
                ActionGroup::make([
                    Action::make('resetTwoFactor')->label('Reset 2FA')->icon(Heroicon::OutlinedKey)
                        ->visible(fn (Admin $record): bool => $record->hasTwoFactorEnabled() && ! $record->is(auth('admin')->user()))
                        ->modalDescription('Untuk admin yang kehilangan HP. Ia harus mengatur 2FA lagi saat login berikutnya.')
                        ->schema([self::confirmPassword()])
                        ->action(fn (Admin $record, Action $action) => RunBusinessAction::run(function () use ($record): void {
                            app(ManageAdmin::class)->resetTwoFactor(self::actor(), $record);
                            Notification::make()->success()->title('2FA direset')->send();
                        }, $action)),
                    Action::make('delete')->label('Hapus')->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->visible(fn (Admin $record): bool => ! $record->is(auth('admin')->user()))
                        ->modalHeading(fn (Admin $record): string => "Hapus {$record->name}?")
                        ->schema([self::confirmPassword()])
                        ->action(fn (Admin $record, Action $action) => RunBusinessAction::run(function () use ($record): void {
                            app(ManageAdmin::class)->delete(self::actor(), $record);
                            Notification::make()->success()->title('Admin dihapus')->send();
                        }, $action)),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedUserGroup, 'admin', 'Akun pertama dibuat dengan php artisan pos:create-admin');
    }

    /** Sesi yang dibajak tidak bisa mengelola akun admin tanpa kata sandi. */
    private static function confirmPassword(): TextInput
    {
        return TextInput::make('current_password')->label('Kata sandi Anda')->password()->required()
            ->currentPassword(guard: 'admin')->dehydrated(false);
    }

    private static function actor(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdmins::route('/')];
    }
}
