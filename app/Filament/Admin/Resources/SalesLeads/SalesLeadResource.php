<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SalesLeads;

use App\Actions\Sales\CreateTenantFromLead;
use App\Actions\Sales\UpdateSalesLead;
use App\Enums\BusinessType;
use App\Enums\SalesLeadStatus;
use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Admin\Resources\SalesLeads\Pages\ListSalesLeads;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Admin;
use App\Models\SalesLead;
use App\Support\Edition;
use App\Support\SystemSettings;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Menu Pelanggan → Calon pelanggan: masukan form "Hubungi sales" di gspos.id (ADR 0008).
 * Tidak bisa dibuat dari panel; hanya ditindaklanjuti (status + catatan).
 */
final class SalesLeadResource extends Resource
{
    protected static ?string $model = SalesLead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Calon pelanggan';

    protected static ?string $modelLabel = 'calon pelanggan';

    protected static ?string $pluralModelLabel = 'calon pelanggan';

    protected static ?string $recordTitleAttribute = 'business_name';

    protected static ?int $navigationSort = 2;

    /** Angka lead baru di menu agar cepat ditindaklanjuti. */
    public static function getNavigationBadge(): ?string
    {
        $count = SalesLead::query()->where('status', SalesLeadStatus::New)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'info';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Jual putus: tidak ada halaman depan & calon pelanggan (ADR 0009). */
    public static function canAccess(): bool
    {
        return Edition::isSaas() && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->label('Status')->options(SalesLeadStatus::class)->required()->native(false),
            Textarea::make('notes')->label('Catatan')->rows(4)->maxLength(2000)
                ->placeholder('Hasil follow-up, jadwal demo, dll.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('name')->label('Nama'),
            TextEntry::make('business_name')->label('Nama bisnis'),
            TextEntry::make('phone')->label('WhatsApp')
                ->url(fn (SalesLead $record): string => 'https://wa.me/'.$record->whatsappNumber(), shouldOpenInNewTab: true)
                ->color('primary'),
            TextEntry::make('email')->label('Email')->placeholder('—'),
            TextEntry::make('city')->label('Kota')->placeholder('—'),
            TextEntry::make('business_type')->label('Jenis usaha')->placeholder('—')
                ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->getLabel() ?? '—'),
            TextEntry::make('message')->label('Pesan')->placeholder('—')->columnSpanFull(),
            TextEntry::make('status')->label('Status')->badge(),
            TextEntry::make('created_at')->label('Masuk')->dateTime('j M Y, H.i'),
            TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('business_name')
                    ->label('Bisnis')
                    ->weight('medium')
                    ->description(fn (SalesLead $record): string => $record->name)
                    ->searchable(['business_name', 'name', 'phone', 'email'])
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->url(fn (SalesLead $record): string => 'https://wa.me/'.$record->whatsappNumber(), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('city')->label('Kota')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('business_type')->label('Jenis')->placeholder('—')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->getLabel() ?? '—')
                    ->sortable()->toggleable(),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('created_at')->label('Masuk')->since()->dateTimeTooltip('j M Y, H.i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('business_type')->label('Jenis usaha')->options(BusinessType::class),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip('Lihat')->modalWidth('2xl'),
                self::createTenantAction(),
                ActionGroup::make([
                    self::statusAction(SalesLeadStatus::Contacted, 'Tandai dihubungi', Heroicon::OutlinedPhone),
                    self::statusAction(SalesLeadStatus::Won, 'Tandai berhasil', Heroicon::OutlinedCheckCircle),
                    self::statusAction(SalesLeadStatus::Lost, 'Tandai batal', Heroicon::OutlinedXCircle),
                    EditAction::make()->label('Ubah status & catatan')->modalWidth('lg')
                        ->using(fn (SalesLead $record, array $data): SalesLead => app(UpdateSalesLead::class)->handle(
                            $record,
                            SalesLeadStatus::from((string) ($data['status'] instanceof SalesLeadStatus ? $data['status']->value : $data['status'])),
                            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                        )),
                ])->tooltip('Aksi lain'),
            ])
            ->defaultSort('created_at', 'desc');

        return TableEmptyState::apply($table, Heroicon::OutlinedChatBubbleLeftRight, 'calon pelanggan', 'Masukan dari form "Hubungi sales" di halaman depan muncul di sini');
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesLeads::route('/')];
    }

    /** Lead → tenant trial + undangan "atur kata sandi" ke owner (SPEC Q34). */
    private static function createTenantAction(): Action
    {
        return Action::make('createTenant')
            ->label('Buat tenant')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->iconButton()
            ->tooltip('Buat tenant dari lead ini')
            ->visible(fn (SalesLead $record): bool => $record->status !== SalesLeadStatus::Won)
            ->modalHeading(fn (SalesLead $record): string => 'Buat tenant: '.$record->business_name)
            ->modalDescription('Owner menerima email "Atur kata sandi" (berlaku 3 hari). Lead otomatis ditandai Berhasil.')
            ->modalSubmitActionLabel('Buat tenant & kirim undangan')
            ->modalWidth('2xl')
            ->fillForm(fn (SalesLead $record): array => [
                'business_name' => $record->business_name,
                'business_type' => $record->business_type ?? BusinessType::Cafe->value,
                'owner_name' => $record->name,
                'owner_email' => $record->email,
                'status' => TenantStatus::Trial->value,
                'subscription_ends_at' => now()->addDays(app(SystemSettings::class)->trialDays())->toDateString(),
            ])
            ->schema([Grid::make(2)->schema([
                TextInput::make('business_name')->label('Nama bisnis')->required()->maxLength(100),
                Select::make('business_type')->label('Jenis usaha')->options(BusinessType::class)->required()->native(false),
                TextInput::make('owner_name')->label('Nama owner')->required()->maxLength(100),
                TextInput::make('owner_email')->label('Email owner')->email()->required()->maxLength(150)
                    // Rule biasa: unique() Filament mengabaikan record aktif (lead), bukan user
                    ->rules([Rule::unique('users', 'email')])
                    ->helperText('Undangan atur kata sandi dikirim ke email ini'),
                Select::make('status')->label('Status')->native(false)->required()->options([
                    TenantStatus::Trial->value => TenantStatus::Trial->getLabel(),
                    TenantStatus::Active->value => TenantStatus::Active->getLabel(),
                ]),
                DatePicker::make('subscription_ends_at')->label('Langganan berakhir')->native(false)->displayFormat('j M Y')
                    ->minDate(today())->helperText('Kosongkan bila tanpa batas'),
            ])])
            ->action(function (SalesLead $record, array $data, Action $action): void {
                $admin = auth('admin')->user();
                abort_unless($admin instanceof Admin, 403);

                try {
                    $tenant = app(CreateTenantFromLead::class)->handle(
                        $record,
                        (string) $data['business_name'],
                        BusinessType::from((string) ($data['business_type'] instanceof BusinessType ? $data['business_type']->value : $data['business_type'])),
                        (string) $data['owner_name'],
                        mb_strtolower(trim((string) $data['owner_email'])),
                        TenantStatus::from((string) $data['status']),
                        filled($data['subscription_ends_at'] ?? null) ? CarbonImmutable::parse((string) $data['subscription_ends_at']) : null,
                        $admin,
                    );
                } catch (BusinessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title('Tenant '.$tenant->name.' dibuat')
                    ->body('Undangan atur kata sandi dikirim ke '.$data['owner_email'].'.')
                    ->send();
            });
    }

    private static function statusAction(SalesLeadStatus $status, string $label, Heroicon $icon): Action
    {
        return Action::make('mark'.ucfirst($status->value))
            ->label($label)
            ->icon($icon)
            ->visible(fn (SalesLead $record): bool => $record->status !== $status)
            ->action(fn (SalesLead $record) => app(UpdateSalesLead::class)->handle($record, $status, $record->notes))
            ->successNotificationTitle('Status diperbarui');
    }
}
