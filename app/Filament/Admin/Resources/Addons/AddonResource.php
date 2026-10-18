<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Addons;

use App\Actions\Billing\SaveAddon;
use App\Filament\Admin\Resources\Addons\Pages\ManageAddons;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Addon;
use App\Models\Feature;
use App\Models\Quota;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Pelanggan → Add-on (ADR 0025 C1): fitur atau tambahan kuota yang dijual di atas paket. */
final class AddonResource extends Resource
{
    protected static ?string $model = Addon::class;

    protected static ?string $slug = 'addons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Add-on';

    protected static ?string $modelLabel = 'add-on';

    protected static ?string $pluralModelLabel = 'add-on';

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        $isQuota = fn (Get $get): bool => $get('type') === Addon::TYPE_QUOTA;

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(60)->placeholder('Outlet tambahan'),
            TextInput::make('code')->label('Kode')->required()->maxLength(30)->placeholder('extra-outlet')->helperText('Huruf kecil, angka, tanda hubung'),
            TextInput::make('description')->label('Keterangan')->maxLength(255)->columnSpanFull(),
            ToggleButtons::make('type')->label('Jenis')->options(Addon::TYPES)->inline()->required()->live()
                ->default(Addon::TYPE_FEATURE)->columnSpanFull()
                ->helperText('Jenis & manfaat tidak bisa diubah setelah add-on dipakai bisnis'),
            Select::make('feature_key')->label('Fitur')->options(fn (): array => Feature::options())
                ->visible(fn (Get $get): bool => ! $isQuota($get))->required(fn (Get $get): bool => ! $isQuota($get)),
            Select::make('quota_key')->label('Kuota')->options(fn (): array => Quota::options())
                ->visible($isQuota)->required($isQuota),
            TextInput::make('quota_amount')->label('Tambahan per unit')->integer()->minValue(1)->maxValue(100000)
                ->visible($isQuota)->required($isQuota)->placeholder('1'),
            MoneyInput::make('price_monthly')->label('Harga bulanan per unit')->required(),
            MoneyInput::make('price_yearly')->label('Harga tahunan per unit')->required(),
            TextInput::make('sort_order')->label('Urutan')->integer()->default(0),
            Toggle::make('is_active')->label('Aktif')->default(true),
            Toggle::make('is_public')->label('Tampil di halaman Langganan owner')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('tenantAddons'))
            ->columns([
                TextColumn::make('name')->label('Add-on')->weight('medium')->description(fn (Addon $record): string => $record->code),
                TextColumn::make('benefit')->label('Manfaat')->state(fn (Addon $record): string => $record->benefit())->wrap(),
                TextColumn::make('price_monthly')->label('Bulanan')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('price_yearly')->label('Tahunan')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('tenant_addons_count')->label('Bisnis')->alignEnd(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Addon $record): string => ! $record->is_active ? 'Nonaktif' : ($record->is_public ? 'Publik' : 'Khusus'))
                    ->color(fn (string $state): string => $state === 'Publik' ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')
                    ->using(fn (Addon $record, array $data, EditAction $action): Addon => self::save($record, $data, $action)),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedPuzzlePiece, 'add-on', 'Buat add-on, mis. Outlet tambahan atau Self-order QR');
    }

    /** @param array<string, mixed> $data */
    public static function save(?Addon $record, array $data, Action $action): Addon
    {
        return RunBusinessAction::run(fn (): Addon => app(SaveAddon::class)->handle($record, $data), $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAddons::route('/')];
    }
}
