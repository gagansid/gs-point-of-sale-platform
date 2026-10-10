<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Outlets;

use App\Actions\Outlet\SetOutletActive;
use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Resources\Outlets\Pages\ManageOutlets;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pengaturan → Outlet (ADR 0010, Q36). Owner menambah & menonaktifkan outlet (outlet.manage);
 * pajak, struk, dsb. diubah di halaman Profil outlet (outlet.settings). Outlet tidak pernah dihapus.
 */
final class OutletResource extends Resource
{
    protected static ?string $model = Outlet::class;

    protected static ?string $slug = 'settings/outlets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Outlet';

    protected static ?string $modelLabel = 'outlet';

    protected static ?string $pluralModelLabel = 'outlet';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->hasPermission('outlet.manage') || $user->hasPermission('outlet.settings'));
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('outlet.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Hanya outlet yang boleh diakses user (owner: semua). */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? $user->accessibleOutlets() : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->columnSpanFull()->schema([
                TextInput::make('name')->label('Nama outlet')->required()->maxLength(100)->autofocus()
                    ->placeholder('Kopi Senja Dago'),
                TextInput::make('code')->label('Kode outlet')->required()->maxLength(10)
                    ->regex('/^[A-Za-z0-9]+$/')
                    ->validationMessages(['regex' => 'Kode outlet hanya boleh huruf dan angka'])
                    ->placeholder('BDG02')
                    ->helperText('Awalan nomor order, tidak bisa diubah'),
            ]),
            Textarea::make('address')->label('Alamat')->rows(2)->maxLength(500)->columnSpanFull(),
            Select::make('timezone')->label('Zona waktu')->options(Timezones::OPTIONS)->required()->native(false)
                ->default(config('pos.default_timezone'))
                ->helperText('Dipakai untuk tanggal nomor order & laporan harian'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('created_at'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable()
                    ->description(fn (Outlet $record): ?string => $record->address),
                TextColumn::make('code')->label('Kode')->badge()->color('gray')->searchable()->sortable(),
                TextColumn::make('users_count')->label('Karyawan')->counts('users')->alignEnd()->sortable()
                    ->tooltip('Karyawan yang ditugaskan (owner memegang semua outlet)'),
                TextColumn::make('devices_count')->label('Perangkat')->counts('devices')->alignEnd()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable(),
            ])
            ->recordActions([
                Action::make('settings')
                    ->label('Profil outlet')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Ubah profil, pajak & struk')
                    ->url(fn (Outlet $record): string => OutletSettings::getUrl(['outlet' => $record->id]))
                    ->visible(fn (): bool => OutletSettings::canAccess()),
                ActionGroup::make(ActiveStatusActions::make(
                    Outlet::class,
                    fn (Outlet $outlet, bool $active) => app(SetOutletActive::class)->handle($outlet, $active),
                    'outlet',
                    'Perangkat di outlet ini tidak bisa bertransaksi sampai diaktifkan lagi. Riwayat transaksi & laporan tetap ada.',
                    'outlet.manage',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedBuildingStorefront, 'outlet', 'Tambahkan outlet untuk cabang baru');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOutlets::route('/')];
    }
}
