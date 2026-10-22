<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\DiningTables;

use App\Actions\SelfOrder\RegenerateTableQr;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SetDiningTableActive;
use App\Filament\Dashboard\Resources\DiningTables\Pages\ManageDiningTables;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Pengaturan → Meja & QR (ADR 0014, Q69; izin table.manage): meja per outlet, QR pesan sendiri (cetak, ganti token),
 * dan setelan self-order outlet (aksi di header halaman).
 */
final class DiningTableResource extends Resource
{
    protected static ?string $model = DiningTable::class;

    protected static ?string $slug = 'settings/tables';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Meja & QR';

    protected static ?string $modelLabel = 'meja';

    protected static ?string $pluralModelLabel = 'meja';

    protected static ?int $navigationSort = 7;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('self_order') && $user->hasFullDashboard() && $user->hasPermission('table.manage');
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('table.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('table.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = SaveDiningTable::rules();

        return $schema->columns(2)->components([
            // "Semua outlet" dipilih di topbar: meja baru wajib memilih outlet
            Select::make('outlet_id')->label('Outlet')->required()->native(false)
                ->options(fn (): array => Outlet::query()->whereIn('id', CurrentOutlet::ids())->orderBy('name')->pluck('name', 'id')->all())
                ->visibleOn('create')
                ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1)
                ->columnSpanFull(),
            TextInput::make('name')->label('Nama meja')->required()->rules($rules['name'])->placeholder('Meja 5')->autofocus(),
            TextInput::make('area')->label('Area')->rules($rules['area'])->placeholder('Indoor, teras, lantai 2'),
            // Tanggal dibuat & diubah (audit)
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->reorderable('sort_order')
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('name'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('outlet:id,name'))
            ->columns([
                TextColumn::make('name')->label('Meja')->weight('medium')->searchable()->sortable()
                    ->description(fn (DiningTable $record): ?string => $record->area),
                TextColumn::make('outlet.name')->label('Outlet')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && count(CurrentOutlet::ids()) > 1),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (DiningTable $record): string => $record->is_active ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'warning'),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                Action::make('printQr')->iconButton()->icon(Heroicon::OutlinedPrinter)->tooltip('Cetak QR')
                    ->url(fn (DiningTable $record): string => route('filament.dashboard.tables.print', ['ids' => $record->id]))
                    ->openUrlInNewTab(),
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('lg')->successNotificationTitle('Meja diperbarui')
                    ->using(fn (DiningTable $record, array $data, EditAction $action): DiningTable => RunBusinessAction::run(
                        fn (): DiningTable => app(SaveDiningTable::class)->handle($record, Outlet::query()->findOrFail($record->outlet_id), $data),
                        $action,
                    )),
                ActionGroup::make([
                    Action::make('regenerateQr')->label('Ganti QR')->icon(Heroicon::OutlinedArrowPath)
                        ->requiresConfirmation()
                        ->modalHeading('Ganti QR meja')
                        ->modalDescription(fn (DiningTable $record): HtmlString => Layout::confirmText(
                            'Ganti QR <strong>'.e($record->name).'</strong>?',
                            'QR lama langsung tidak bisa dipakai. Cetak dan tempel QR baru di meja.',
                        ))
                        ->modalSubmitActionLabel('Ganti QR')
                        ->action(function (DiningTable $record): void {
                            abort_unless(self::canEdit($record), 403);
                            app(RegenerateTableQr::class)->handle($record);
                            Notification::make()->success()->title('QR diganti')->body('Cetak QR baru untuk '.$record->name)->send();
                        }),
                    ...ActiveStatusActions::make(DiningTable::class, function (DiningTable $table, bool $active): void {
                        app(SetDiningTableActive::class)->handle($table, $active);
                    }, 'meja', 'QR meja ini tidak menerima pesanan baru sampai diaktifkan lagi.', 'table.manage'),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedQrCode, 'meja', 'Tambah meja lalu cetak QR-nya agar pelanggan bisa pesan sendiri');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDiningTables::route('/')];
    }
}
