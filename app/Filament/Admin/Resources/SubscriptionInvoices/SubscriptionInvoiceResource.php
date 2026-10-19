<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices;

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Enums\BillingCycle;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\CreateSubscriptionInvoice;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Admin\Resources\SubscriptionInvoices\Schemas\ManualInvoiceForm;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Forms\MoneyStateCast;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Pelanggan → Tagihan (ADR 0024, Q110): semua tagihan langganan lintas tenant, filter lengkap, detail, tandai lunas
 * (transfer manual), batalkan, dan tagihan manual admin (biaya bebas / add-on harga khusus, CreateManualInvoice).
 */
final class SubscriptionInvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Tagihan';

    protected static ?string $modelLabel = 'tagihan';

    protected static ?string $pluralModelLabel = 'tagihan';

    protected static ?int $navigationSort = 5;

    protected static bool $isGloballySearchable = false;

    /**
     * Panel /admin tanpa konteks tenant: semua tenant.
     *
     * @return Builder<SubscriptionInvoice>
     */
    public static function getEloquentQuery(): Builder
    {
        return SubscriptionInvoice::query()->withoutGlobalScopes();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ManualInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['tenant:id,name', 'items', 'createdByAdmin:id,name']))
            ->columns([
                TextColumn::make('number')->label('Nomor')->fontFamily(FontFamily::Mono)->size('xs')->searchable()->sortable()
                    ->copyable()->copyMessage('Nomor disalin'),
                TextColumn::make('tenant.name')->label('Bisnis')->weight('medium')->searchable()->sortable()
                    ->description(fn (SubscriptionInvoice $record): string => $record->created_by_admin_id !== null
                        ? 'Dibuat admin'.($record->createdByAdmin !== null ? ' · '.$record->createdByAdmin->name : '')
                        : 'Dibuat owner'),
                TextColumn::make('kind')->label('Jenis')->badge()->sortable()
                    ->color(fn (InvoiceKind $state): string => match ($state) {
                        InvoiceKind::Manual => 'warning',
                        InvoiceKind::Addon => 'info',
                        default => 'gray',
                    }),
                // Paket + add-on (ADR 0025 C2) / judul tagihan manual
                TextColumn::make('plan_name')->label('Rincian')->state(fn (SubscriptionInvoice $record): string => $record->description())->wrap(),
                TextColumn::make('amount')->label('Total')->alignEnd()->sortable()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('created_at')->label('Dibuat')->dateTime('j M Y, H.i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_at')->label('Jatuh tempo')->date('j M Y')->sortable()
                    ->color(fn (SubscriptionInvoice $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (SubscriptionInvoice $record): ?string => $record->isOverdue() ? 'Lewat jatuh tempo' : null),
                TextColumn::make('paid_at')->label('Lunas')->dateTime('j M Y, H.i')->placeholder('—')->sortable()
                    ->description(fn (SubscriptionInvoice $record): ?string => match ($record->paid_via) {
                        'manual' => 'Transfer manual',
                        'midtrans' => 'Online (Midtrans)',
                        default => null,
                    }),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(InvoiceStatus::class),
                // Dipakai juga oleh tautan "Semua tagihan" di detail bisnis
                SelectFilter::make('tenant_id')->label('Bisnis')->relationship('tenant', 'name')->searchable()->preload(),
                SelectFilter::make('kind')->label('Jenis')->options(InvoiceKind::class),
                SelectFilter::make('plan_id')->label('Paket')->options(fn (): array => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('billing_cycle')->label('Siklus')->options(BillingCycle::class),
                SelectFilter::make('paid_via')->label('Dibayar via')->options(['manual' => 'Transfer manual', 'midtrans' => 'Online (Midtrans)']),
                TernaryFilter::make('created_by_admin_id')->label('Dibuat oleh')->nullable()
                    ->trueLabel('Admin')->falseLabel('Owner'),
                TernaryFilter::make('overdue')->label('Lewat jatuh tempo')->trueLabel('Ya')->falseLabel('Tidak')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Pending)->where('due_at', '<', now()),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->where('status', '!=', InvoiceStatus::Pending)->orWhere('due_at', '>=', now())),
                    ),
                self::dateRange('created_at', 'Tanggal dibuat'),
                self::dateRange('due_at', 'Jatuh tempo'),
                self::dateRange('paid_at', 'Tanggal lunas'),
                self::amountRange(),
            ])
            ->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Detail')->modalHeading(fn (SubscriptionInvoice $record): string => 'Tagihan '.$record->number)
                    ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->schema([])
                    ->modalContent(fn (SubscriptionInvoice $record) => view('filament.admin.invoices.detail', ['invoice' => $record->loadMissing(['items', 'tenant', 'createdByAdmin'])])),
                ActionGroup::make([
                    Action::make('markPaid')->label('Tandai lunas')->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (SubscriptionInvoice $record): bool => $record->status !== InvoiceStatus::Paid)
                        ->modalHeading('Tandai lunas (transfer manual)')
                        ->modalDescription(fn (SubscriptionInvoice $record): string => $record->kind === InvoiceKind::Manual
                            ? 'Baris add-on (bila ada) langsung aktif; paket & masa aktif tidak berubah'
                            : 'Paket & masa aktif bisnis diperbarui seperti pembayaran online')
                        ->modalSubmitActionLabel('Tandai lunas')
                        ->schema([TextInput::make('notes')->label('Catatan')->maxLength(255)->placeholder('mis. transfer BCA 14 Okt')])
                        ->action(function (SubscriptionInvoice $record, array $data): void {
                            $admin = auth('admin')->user();
                            abort_unless($admin instanceof Admin, 403);
                            app(MarkInvoicePaid::class)->handle($record, 'manual', $admin, $data['notes'] ?? null);
                            Notification::make()->success()->title('Tagihan lunas')->send();
                        }),
                    Action::make('cancel')->label('Batalkan')->icon(Heroicon::OutlinedXCircle)->color('danger')
                        ->visible(fn (SubscriptionInvoice $record): bool => $record->status === InvoiceStatus::Pending)
                        ->requiresConfirmation()
                        ->modalHeading(fn (SubscriptionInvoice $record): string => 'Batalkan tagihan '.$record->number.'?')
                        ->modalDescription('Owner tidak bisa membayar tagihan ini lagi')
                        ->modalSubmitActionLabel('Batalkan tagihan')
                        ->action(function (SubscriptionInvoice $record, Action $action): void {
                            RunBusinessAction::run(fn () => app(CancelInvoice::class)->handle($record), $action);
                            Notification::make()->success()->title('Tagihan dibatalkan')->send();
                        }),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDocumentCurrencyDollar, 'tagihan', 'Tagihan muncul saat owner membuat tagihan di Pengaturan → Langganan, atau tambah tagihan manual');
    }

    /** Rentang tanggal lokal platform (pos.default_timezone) → UTC. */
    private static function dateRange(string $column, string $label): Filter
    {
        $tz = fn (): string => FilamentTimezone::get();

        return Filter::make($column)->label($label)
            ->schema([
                DatePicker::make('from')->label($label.' dari'),
                DatePicker::make('until')->label('Sampai'),
            ])
            ->columns(2)->columnSpan(2)
            ->query(function (Builder $query, array $data) use ($column, $tz): Builder {
                return $query
                    ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where($column, '>=', CarbonImmutable::parse((string) $data['from'], $tz())->startOfDay()->utc()))
                    ->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where($column, '<=', CarbonImmutable::parse((string) $data['until'], $tz())->endOfDay()->utc()));
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $fmt = fn (mixed $d): string => CarbonImmutable::parse((string) $d)->locale('id')->translatedFormat('j M Y');
                $from = filled($data['from'] ?? null) ? $fmt($data['from']) : null;
                $until = filled($data['until'] ?? null) ? $fmt($data['until']) : null;

                return match (true) {
                    $from !== null && $until !== null => [Indicator::make("{$label}: {$from} – {$until}")],
                    $from !== null => [Indicator::make("{$label} mulai {$from}")],
                    $until !== null => [Indicator::make("{$label} s/d {$until}")],
                    default => [],
                };
            });
    }

    private static function amountRange(): Filter
    {
        $cast = new MoneyStateCast;
        $value = fn (mixed $v): ?string => is_numeric($cast->get($v)) ? (string) $cast->get($v) : null;

        return Filter::make('amount')->label('Total')
            ->schema([
                MoneyInput::make('min')->label('Total minimal'),
                MoneyInput::make('max')->label('Total maksimal'),
            ])
            ->columns(2)->columnSpan(2)
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($value($data['min'] ?? null) !== null, fn (Builder $q) => $q->where('amount', '>=', $value($data['min'])))
                ->when($value($data['max'] ?? null) !== null, fn (Builder $q) => $q->where('amount', '<=', $value($data['max']))))
            ->indicateUsing(function (array $data) use ($value): array {
                $min = $value($data['min'] ?? null);
                $max = $value($data['max'] ?? null);

                return match (true) {
                    $min !== null && $max !== null => [Indicator::make('Total '.Money::format($min).' – '.Money::format($max))],
                    $min !== null => [Indicator::make('Total ≥ '.Money::format($min))],
                    $max !== null => [Indicator::make('Total ≤ '.Money::format($max))],
                    default => [],
                };
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionInvoices::route('/'),
            'create' => CreateSubscriptionInvoice::route('/create'),
        ];
    }
}
