<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriptionInvoices;

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\MarkInvoicePaid;
use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Admin;
use App\Models\SubscriptionInvoice;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Pelanggan → Tagihan (ADR 0024, Q110): semua tagihan langganan, tandai lunas (transfer manual), batalkan. */
final class SubscriptionInvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Tagihan';

    protected static ?string $modelLabel = 'tagihan';

    protected static ?string $pluralModelLabel = 'tagihan';

    protected static ?int $navigationSort = 4;

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

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tenant:id,name'))
            ->columns([
                TextColumn::make('number')->label('Nomor')->weight('medium')->searchable()
                    ->description(fn (SubscriptionInvoice $record): string => (string) $record->tenant?->name),
                TextColumn::make('plan_name')->label('Paket')->description(fn (SubscriptionInvoice $record): string => $record->billing_cycle->getLabel()),
                TextColumn::make('amount')->label('Total')->alignEnd()->sortable()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('due_at')->label('Jatuh tempo')->date('j M Y')->sortable(),
                TextColumn::make('paid_at')->label('Lunas')->dateTime('j M Y, H.i')->placeholder('—')
                    ->description(fn (SubscriptionInvoice $record): ?string => $record->paid_via),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(InvoiceStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('markPaid')->label('Tandai lunas')->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (SubscriptionInvoice $record): bool => $record->status !== InvoiceStatus::Paid)
                        ->modalHeading('Tandai lunas (transfer manual)')
                        ->modalDescription('Paket & masa aktif bisnis diperbarui seperti pembayaran online')
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
                        ->action(function (SubscriptionInvoice $record, Action $action): void {
                            RunBusinessAction::run(fn () => app(CancelInvoice::class)->handle($record), $action);
                            Notification::make()->success()->title('Tagihan dibatalkan')->send();
                        }),
                ])->tooltip('Aksi'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDocumentCurrencyDollar, 'tagihan', 'Tagihan muncul saat owner membuat tagihan di Pengaturan → Langganan');
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptionInvoices::route('/')];
    }
}
