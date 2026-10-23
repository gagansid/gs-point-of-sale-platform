<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Support\Edition;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;

/**
 * Detail tenant + statistik pemakaian (infolist.md).
 */
final class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Detail bisnis')->icon(Heroicon::OutlinedBuildingStorefront)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->label('Nama bisnis')->weight('semibold'),
                        TextEntry::make('slug')->label('Slug')->fontFamily('mono')->copyable(),
                        TextEntry::make('business_type')->label('Jenis usaha')->badge()->color('gray'),
                        TextEntry::make('status')->label('Status')->badge(),
                        TextEntry::make('subscription_ends_at')
                            ->label('Langganan berakhir')
                            ->date('j M Y')
                            ->placeholder('Tanpa batas'),
                        TextEntry::make('created_at')->label('Terdaftar')->dateTime('j M Y, H.i'),
                    ]),

                Section::make('Pemakaian')->icon(Heroicon::OutlinedChartBar)
                    ->columns(4)
                    ->schema([
                        TextEntry::make('outlets_count')->label('Outlet')
                            ->state(fn (Tenant $record): int => $record->outlets()->count())->numeric(locale: 'id'),
                        TextEntry::make('users_count')->label('Karyawan')
                            ->state(fn (Tenant $record): int => $record->users()->count())->numeric(locale: 'id'),
                        TextEntry::make('devices_count')->label('Perangkat')
                            ->state(fn (Tenant $record): int => $record->devices()->count())->numeric(locale: 'id'),
                        TextEntry::make('owners')
                            ->label('Owner')
                            ->state(fn (Tenant $record): array => $record->users()
                                ->where('role', UserRole::Owner)
                                ->pluck('email')
                                ->filter()
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                    ]),
                self::subscription(),
                // Tanggal dibuat & diubah (audit)
                AuditInfo::make(),
            ]);
    }

    /** Paket, jadwal perubahan paket & 5 tagihan terbaru (ADR 0024). Hanya edisi SaaS. */
    private static function subscription(): Section
    {
        return Section::make('Langganan')->icon(Heroicon::OutlinedCreditCard)
            ->visible(fn (): bool => Edition::isSaas())
            ->columns(4)
            ->headerActions([
                Action::make('allInvoices')->label('Semua tagihan')->link()
                    ->url(fn (Tenant $record): string => SubscriptionInvoiceResource::getUrl('index', [
                        'filters' => ['tenant_id' => ['value' => $record->id]],
                    ])),
            ])
            ->schema([
                TextEntry::make('plan.name')->label('Paket')->weight('semibold')
                    ->placeholder('Tanpa paket (semua fitur)'),
                TextEntry::make('billing_cycle')->label('Siklus')->placeholder('—'),
                // Pemakaian vs batas (ADR 0025), tampilan sama dengan halaman Langganan owner
                ViewEntry::make('quota_usage')->label('Kuota')->columnSpanFull()
                    ->view('filament.shared.quota-usage-entry')
                    ->helperText(fn (Tenant $record): ?string => $record->limit_overrides ? 'Termasuk batas khusus (form Ubah)' : null),
                // Add-on (ADR 0025 C1): fitur atau tambahan kuota di atas paket
                TextEntry::make('addons_list')->label('Add-on')->columnSpan(2)
                    ->state(fn (Tenant $record): array => $record->activeAddons()
                        ->map(fn (TenantAddon $a): string => $a->addon->name.($a->addon->isQuota() ? ' ×'.$a->quantity : '')
                            .($a->status === TenantAddon::CANCELLING ? ' (berhenti '.$a->cancel_at?->translatedFormat('j M Y').')' : ''))
                        ->all())
                    ->listWithLineBreaks()->placeholder('—'),
                // Downgrade / ganti siklus yang menunggu akhir masa aktif (pos:apply-plan-changes)
                TextEntry::make('scheduled_change')->label('Perubahan terjadwal')->color('warning')
                    ->state(fn (Tenant $record): ?string => $record->plan_changes_at === null ? null : sprintf(
                        '%s · %s mulai %s',
                        $record->nextPlan->name ?? 'Tanpa paket',
                        $record->next_billing_cycle?->getLabel() ?? '—',
                        $record->plan_changes_at->setTimezone(FilamentTimezone::get())->translatedFormat('j M Y, H.i'),
                    ))
                    ->placeholder('—'),
                RepeatableEntry::make('latest_invoices')->label('Tagihan terbaru')->columnSpanFull()
                    ->state(fn (Tenant $record) => $record->subscriptionInvoices()->with('items')->latest()->limit(5)->get())
                    ->placeholder('Belum ada tagihan')
                    ->table([
                        TableColumn::make('Nomor'),
                        TableColumn::make('Rincian'),
                        TableColumn::make('Total')->alignEnd(),
                        TableColumn::make('Jatuh tempo'),
                        TableColumn::make('Status'),
                    ])
                    ->schema([
                        TextEntry::make('number'),
                        // Tagihan manual boleh tanpa paket: uraian dari description()
                        TextEntry::make('description')
                            ->state(fn (SubscriptionInvoice $record): string => $record->description()),
                        TextEntry::make('amount')->alignEnd()->formatStateUsing(fn (string $state): string => Money::format($state)),
                        TextEntry::make('due_at')->date('j M Y'),
                        TextEntry::make('status')->badge(),
                    ]),
            ]);
    }
}
