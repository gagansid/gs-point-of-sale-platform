<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SalesLeads;

use App\Actions\Sales\UpdateSalesLead;
use App\Enums\BusinessType;
use App\Enums\SalesLeadStatus;
use App\Filament\Admin\Resources\SalesLeads\Pages\ListSalesLeads;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\SalesLead;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
