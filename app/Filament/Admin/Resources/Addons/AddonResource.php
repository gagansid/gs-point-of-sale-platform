<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Addons;

use App\Actions\Billing\SaveAddon;
use App\Actions\Billing\SetAddonActive;
use App\Filament\Admin\Resources\Addons\Pages\ManageAddons;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Addon;
use App\Models\Feature;
use App\Models\Quota;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Pelanggan → Add-on (ADR 0025 C1): fitur atau tambahan kuota yang dijual di atas paket; status aktif, filter. */
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
            Section::make('Identitas add-on')->description('Nama tampil di halaman Langganan owner & tagihan')
                ->columns(2)->columnSpanFull()->schema([
                    TextInput::make('name')->label('Nama')->required()->maxLength(60)->placeholder('Outlet tambahan')->autofocus(),
                    TextInput::make('code')->label('Kode')->required()->maxLength(30)->placeholder('extra-outlet')->helperText('Huruf kecil, angka, tanda hubung'),
                    Textarea::make('description')->label('Keterangan')->maxLength(255)->rows(2)->autosize()->columnSpanFull(),
                ]),
            Section::make('Manfaat')->description('Jenis & manfaat tidak bisa diubah setelah add-on dipakai bisnis')
                ->columns(2)->columnSpanFull()->schema([
                    ToggleButtons::make('type')->label('Jenis')->options(Addon::TYPES)->inline()->required()->live()
                        ->default(Addon::TYPE_FEATURE)->columnSpanFull(),
                    Select::make('feature_key')->label('Fitur')->options(fn (): array => Feature::options())
                        ->visible(fn (Get $get): bool => ! $isQuota($get))->required(fn (Get $get): bool => ! $isQuota($get)),
                    Select::make('quota_key')->label('Kuota')->options(fn (): array => Quota::options())
                        ->visible($isQuota)->required($isQuota),
                    TextInput::make('quota_amount')->label('Tambahan per unit')->integer()->minValue(1)->maxValue(100000)
                        ->visible($isQuota)->required($isQuota)->placeholder('1'),
                ]),
            Section::make('Harga per unit')->description('Ditagih prorata sisa masa aktif saat dibeli, lalu ikut tagihan perpanjangan')
                ->columns(3)->columnSpanFull()->schema([
                    MoneyInput::make('price_monthly')->label('Harga bulanan')->required(),
                    MoneyInput::make('price_yearly')->label('Harga tahunan')->required(),
                    TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(999)->default(0)
                        ->helperText('Urutan tampil, kecil di depan'),
                ]),
            Section::make('Status')->description('Add-on nonaktif tidak bisa dibeli; bisnis yang memilikinya tetap memakai')
                ->columns(2)->columnSpanFull()->schema([
                    Toggle::make('is_active')->label('Aktif')->default(true),
                    Toggle::make('is_public')->label('Tampil di halaman Langganan owner')->default(true)
                        ->helperText('Mati = add-on khusus, hanya bisa ditagihkan admin'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('tenantAddons')->withSum('tenantAddons', 'quantity'))
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily(FontFamily::Mono)->size('xs')->searchable()->sortable()
                    ->copyable()->copyMessage('Kode disalin'),
                TextColumn::make('name')->label('Add-on')->weight('medium')->searchable()->sortable()
                    // Keterangan lengkap lewat tooltip agar kolom tetap ramping
                    ->tooltip(fn (Addon $record): ?string => $record->description),
                TextColumn::make('type')->label('Jenis')->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => Addon::TYPES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === Addon::TYPE_QUOTA ? 'warning' : 'info'),
                TextColumn::make('benefit')->label('Manfaat')->state(fn (Addon $record): string => $record->benefit())->wrap(),
                // Satu kolom: bulanan di atas, tahunan di bawah (tabel tetap muat di layar 1366px)
                TextColumn::make('price_monthly')->label('Harga')->alignEnd()->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::format($state).'/bln')
                    ->description(fn (Addon $record): string => Money::format($record->price_yearly).'/thn'),
                TextColumn::make('tenant_addons_count')->label('Bisnis')->alignEnd()->sortable(),
                TextColumn::make('tenant_addons_sum_quantity')->label('Unit terpakai')->alignEnd()->placeholder('0')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_public')->label('Tampil')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Publik' : 'Khusus')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                TextColumn::make('is_active')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ...AuditColumns::make(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Jenis')->options(Addon::TYPES),
                SelectFilter::make('feature_key')->label('Fitur')->options(fn (): array => Feature::options()),
                SelectFilter::make('quota_key')->label('Kuota')->options(fn (): array => Quota::options()),
                SelectFilter::make('is_public')->label('Tampil')->options(['1' => 'Publik', '0' => 'Khusus']),
                TernaryFilter::make('used')->label('Dipakai bisnis')->trueLabel('Dipakai')->falseLabel('Belum dipakai')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('tenantAddons'),
                        false: fn (Builder $query): Builder => $query->doesntHave('tenantAddons'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('3xl')
                    ->using(fn (Addon $record, array $data, EditAction $action): Addon => self::save($record, $data, $action)),
                ActionGroup::make([
                    ...ActiveStatusActions::make(
                        Addon::class,
                        fn (Addon $addon, bool $active) => app(SetAddonActive::class)->handle($addon, $active),
                        'add-on',
                        'Add-on tidak bisa dibeli atau ditagihkan sampai diaktifkan lagi. Bisnis yang memilikinya tetap memakai.',
                        null,
                    ),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedPuzzlePiece, 'add-on', 'Tambah add-on, mis. Outlet tambahan atau Self-order QR');
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
