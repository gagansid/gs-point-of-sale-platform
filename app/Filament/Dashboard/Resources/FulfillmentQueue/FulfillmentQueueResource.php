<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\FulfillmentQueue;

use App\Actions\Fulfillment\AdvanceOrderFulfillment;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\FulfillmentQueue\Pages\ListFulfillmentQueue;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Filters\DateRangeFilter;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Transaksi → Antrean pesanan (ADR 0027 K8; izin order.fulfill, fitur self_order): pesanan meja lunas dari Antre
 * sampai Diantar / Selesai otomatis. Memantau (statistik hari ini, tab, riwayat) dan memajukan tahap dengan Action yang
 * sama dengan kasir web /cashier/queue & API. Polling 10 detik.
 */
final class FulfillmentQueueResource extends DashboardResource
{
    use HasSentenceCaseLabels;

    /** Pesanan dianggap terlambat bila belum diantar lebih dari ini sejak lunas. */
    public const LATE_MINUTES = 30;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'order-queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?string $navigationLabel = 'Antrean pesanan';

    protected static ?string $modelLabel = 'pesanan';

    protected static ?string $pluralModelLabel = 'antrean pesanan';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('self_order') && $user->hasPermission('order.fulfill');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Pesanan meja yang sudah masuk alur pengantaran, outlet pilihan sidebar (ADR 0010).
     *
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        return CurrentOutlet::scope(Order::query())->where('channel', OrderChannel::SelfOrder)->whereNotNull('fulfillment_status');
    }

    public static function table(Table $table): Table
    {
        $tz = fn (): string => CurrentOutlet::timezone();

        $table = $table
            ->poll('10s')
            ->defaultSort('completed_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['selfOrder:id,order_id,customer_name', 'diningTable:id,area', 'outlet:id,name'])->withSum('items as item_qty', 'qty'))
            ->columns([
                TextColumn::make('table_label')->label('Meja')->weight('medium')->placeholder('—')
                    ->description(fn (Order $record): ?string => $record->diningTable?->area),
                TextColumn::make('order_number')->label('No. pesanan')->fontFamily('mono')->size('sm')->searchable()
                    ->url(fn (Order $record): ?string => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record]) : null),
                // Tampil saat "Semua outlet" dipilih dan bisnis punya lebih dari satu outlet (ADR 0010)
                TextColumn::make('outlet.name')->label('Outlet')->badge()->color('gray')
                    ->visible(fn (): bool => CurrentOutlet::selectedId() === null && Outlet::query()->count() > 1),
                TextColumn::make('customer')->label('Pelanggan')
                    ->state(fn (Order $record): string => $record->selfOrder->customer_name ?? $record->member_name ?? '—')
                    ->description(fn (Order $record): ?string => $record->member_name !== null ? 'Member' : null),
                TextColumn::make('item_qty')->label('Item')->alignEnd()->numeric(locale: 'id'),
                TextColumn::make('completed_at')->label('Lunas')->sortable()
                    ->formatStateUsing(fn (Order $record): string => $record->completed_at?->setTimezone($tz())->format('H.i') ?? '—')
                    ->description(fn (Order $record): string => $record->completed_at?->setTimezone($tz())->translatedFormat('j M') ?? ''),
                TextColumn::make('age')->label('Umur')->badge()->alignEnd()
                    ->state(fn (Order $record): string => self::ageMinutes($record).' mnt')
                    ->color(fn (Order $record): string => match (true) {
                        $record->fulfillment_status?->isFinal() ?? false => 'gray',
                        self::ageMinutes($record) > self::LATE_MINUTES => 'danger',
                        self::ageMinutes($record) > 15 => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (Order $record): string => $record->fulfillment_status?->isFinal() ? 'Lunas → diantar' : 'Sejak lunas'),
                TextColumn::make('fulfillment_status')->label('Tahap')->badge()->sortable()
                    ->description(fn (Order $record): ?string => $record->served_at !== null ? $record->served_at->setTimezone($tz())->format('H.i') : null),
            ])
            ->filters([
                DateRangeFilter::make('completed_at', 'Tanggal lunas'),
                SelectFilter::make('area')->label('Area meja')
                    ->options(fn (): array => DiningTable::query()->whereIn('outlet_id', CurrentOutlet::ids())->whereNotNull('area')->where('area', '!=', '')
                        ->distinct()->orderBy('area')->pluck('area', 'area')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereIn('dining_table_id', DiningTable::query()->where('area', $data['value'])->select('id'))
                        : $query),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                Action::make('advance')
                    ->label(fn (Order $record): string => $record->fulfillment_status?->next()?->actionLabel() ?? '')
                    ->icon(fn (Order $record): ?string => $record->fulfillment_status?->next()?->getIcon())
                    ->button()->size('sm')
                    ->visible(fn (Order $record): bool => $record->fulfillment_status?->next() !== null && $record->status === OrderStatus::Completed)
                    ->action(function (Order $record, Action $action): void {
                        $user = auth()->user();
                        $next = $record->fulfillment_status?->next();
                        if (! $user instanceof User || $next === null) {
                            return;
                        }

                        RunBusinessAction::run(fn () => app(AdvanceOrderFulfillment::class)->handle($user, $record, $next), $action);
                        Notification::make()->success()->title(($record->table_label ?: $record->order_number).' · '.$next->getLabel())->send();
                    }),
                ActionGroup::make([
                    Action::make('detail')->label('Detail pesanan')->icon(Heroicon::OutlinedEye)
                        ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
                        ->visible(fn (): bool => OrderResource::canViewAny()),
                    Action::make('cancel')->label('Batalkan / refund')->icon(Heroicon::OutlinedXCircle)->color('danger')
                        ->tooltip('Void dengan alasan; pesanan lunas online ditandai perlu refund manual')
                        ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
                        ->visible(fn (Order $record): bool => OrderResource::canViewAny() && $record->status === OrderStatus::Completed),
                ])->tooltip('Aksi lainnya'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedInboxStack, 'pesanan di antrean',
            'Pesanan meja yang sudah dibayar muncul di sini sampai diantar ke meja');
    }

    /** Menit sejak lunas sampai diantar (atau sampai sekarang bila belum). */
    public static function ageMinutes(Order $order): int
    {
        return (int) ($order->completed_at?->diffInMinutes($order->served_at ?? now()) ?? 0);
    }

    /**
     * Tahap terbuka (belum diantar) yang sudah lewat batas terlambat.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function late(Builder $query): Builder
    {
        return $query->whereIn('fulfillment_status', FulfillmentStatus::open())->where('completed_at', '<', now()->subMinutes(self::LATE_MINUTES));
    }

    public static function getPages(): array
    {
        return ['index' => ListFulfillmentQueue::route('/')];
    }
}
