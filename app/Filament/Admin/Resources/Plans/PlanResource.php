<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Plans;

use App\Actions\Billing\SavePlan;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Plan;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Pelanggan → Paket (ADR 0024, Q108): paket langganan SaaS, harga, batas outlet, fitur. */
final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $slug = 'plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Paket';

    protected static ?string $modelLabel = 'paket';

    protected static ?string $pluralModelLabel = 'paket';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(60)->placeholder('Pro'),
            TextInput::make('code')->label('Kode')->required()->maxLength(30)->placeholder('pro')->helperText('Huruf kecil, angka, tanda hubung'),
            TextInput::make('description')->label('Keterangan')->maxLength(255)->columnSpanFull(),
            MoneyInput::make('price_monthly')->label('Harga bulanan')->required(),
            MoneyInput::make('price_yearly')->label('Harga tahunan')->required(),
            TextInput::make('max_outlets')->label('Maks. outlet aktif')->integer()->minValue(1)->placeholder('Tanpa batas'),
            TextInput::make('sort_order')->label('Urutan')->integer()->default(0),
            CheckboxList::make('features')->label('Fitur')->options(Plan::FEATURES)->columns(2)->columnSpanFull()
                ->helperText('Kasir, laporan, retur, pecah bill, kas masuk/keluar selalu tersedia'),
            Toggle::make('is_active')->label('Aktif')->default(true),
            Toggle::make('is_public')->label('Tampil di halaman Langganan owner')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Paket')->weight('medium')->description(fn (Plan $record): string => $record->code),
                TextColumn::make('price_monthly')->label('Bulanan')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('price_yearly')->label('Tahunan')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('max_outlets')->label('Outlet')->alignEnd()->placeholder('Tanpa batas'),
                TextColumn::make('features')->label('Fitur')->state(fn (Plan $record): string => count($record->features).' dari '.count(Plan::FEATURES)),
                TextColumn::make('tenants_count')->label('Bisnis')->counts('tenants')->alignEnd(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Plan $record): string => ! $record->is_active ? 'Nonaktif' : ($record->is_public ? 'Publik' : 'Khusus'))
                    ->color(fn (string $state): string => $state === 'Publik' ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')
                    ->using(fn (Plan $record, array $data, EditAction $action): Plan => self::save($record, $data, $action)),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedRectangleStack, 'paket', 'Buat paket langganan, mis. Basic dan Pro');
    }

    /** @param array<string, mixed> $data */
    public static function save(?Plan $record, array $data, Action $action): Plan
    {
        return RunBusinessAction::run(fn (): Plan => app(SavePlan::class)->handle($record, $data), $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }
}
