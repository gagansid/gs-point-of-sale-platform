<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Suppliers;

use App\Actions\Stock\SaveSupplier;
use App\Actions\Stock\SetSupplierActive;
use App\Filament\Dashboard\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Stok → Supplier (ADR 0015, Q79; izin supplier.manage): data supplier bisnis untuk stok masuk. */
final class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $slug = 'stock/suppliers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Stok';

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'supplier';

    protected static ?string $pluralModelLabel = 'supplier';

    protected static ?int $navigationSort = 9;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('stock') && $user->role->hasFullDashboard() && $user->hasPermission('supplier.manage');
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('supplier.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('supplier.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = SaveSupplier::rules();

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->rules($rules['name'])->autofocus()->columnSpanFull(),
            TextInput::make('phone')->label('Telepon')->tel()->rules($rules['phone']),
            TextInput::make('email')->label('Email')->email()->rules($rules['email']),
            Textarea::make('address')->label('Alamat')->rows(2)->rules($rules['address'])->columnSpanFull(),
            Textarea::make('notes')->label('Catatan')->rows(2)->rules($rules['notes'])->columnSpanFull(),
            // Tanggal dibuat & diubah (audit)
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable()
                    ->description(fn (Supplier $record): ?string => $record->notes),
                TextColumn::make('phone')->label('Telepon')->placeholder('—')->searchable(),
                TextColumn::make('email')->label('Email')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Supplier $record): string => $record->is_active ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'warning'),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')->successNotificationTitle('Supplier diperbarui')
                    ->using(fn (Supplier $record, array $data, EditAction $action): Supplier => RunBusinessAction::run(
                        fn (): Supplier => app(SaveSupplier::class)->handle($record, $data),
                        $action,
                    )),
                ActionGroup::make(ActiveStatusActions::make(
                    Supplier::class,
                    fn (Supplier $supplier, bool $active) => app(SetSupplierActive::class)->handle($supplier, $active),
                    'supplier',
                    'Supplier tidak bisa dipilih di stok masuk baru. Riwayat tetap ada.',
                    'supplier.manage',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedTruck, 'supplier', 'Tambah supplier untuk dicatat saat barang datang');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }
}
