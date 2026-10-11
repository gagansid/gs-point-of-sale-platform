<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Announcements;

use App\Actions\Admin\SaveAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ManageAnnouncements;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Announcement;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Aplikasi → Pengumuman: banner ke semua tenant di dashboard, kasir web, dan aplikasi
 * (GET /v1/announcements). Maks. 3 pengumuman tayang ditampilkan.
 */
final class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $slug = 'announcements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Aplikasi';

    protected static ?string $navigationLabel = 'Pengumuman';

    protected static ?string $modelLabel = 'pengumuman';

    protected static ?string $pluralModelLabel = 'pengumuman';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('Judul')->required()->maxLength(150)->columnSpanFull()
                ->placeholder('Maintenance terjadwal Minggu 02.00–03.00 WIB'),
            Textarea::make('body')->label('Isi')->required()->rows(3)->maxLength(1000)->columnSpanFull()
                ->helperText('Teks polos, tampil di banner dashboard, kasir web, dan aplikasi'),
            DateTimePicker::make('starts_at')->label('Mulai')->required()->seconds(false)->default(now()),
            DateTimePicker::make('ends_at')->label('Selesai')->seconds(false)->after('starts_at')
                ->helperText('Kosongkan = tayang sampai diakhiri'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Judul')->weight('medium')->searchable()
                    ->description(fn (Announcement $record): string => str($record->body)->limit(80)->toString()),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Announcement $record): string => match (true) {
                        $record->isLive() => 'Tayang',
                        $record->starts_at->isFuture() => 'Terjadwal',
                        default => 'Selesai',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Tayang' => 'success',
                        'Terjadwal' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('starts_at')->label('Mulai')->dateTime('j M Y, H.i')->sortable(),
                TextColumn::make('ends_at')->label('Selesai')->dateTime('j M Y, H.i')->placeholder('Sampai diakhiri')->sortable(),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('xl')
                    ->using(fn (Announcement $record, array $data, EditAction $action): Announcement => self::save($record, $data, $action)),
                ActionGroup::make([
                    Action::make('end')->label('Akhiri sekarang')->icon(Heroicon::OutlinedStopCircle)
                        ->visible(fn (Announcement $record): bool => $record->isLive())
                        ->requiresConfirmation()
                        ->action(fn (Announcement $record) => app(SaveAnnouncement::class)->end($record)),
                    DeleteAction::make(),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedMegaphone, 'pengumuman', 'Pengumuman tampil sebagai banner untuk semua tenant');
    }

    /** @param array<string, mixed> $data */
    public static function save(?Announcement $record, array $data, Action $action): Announcement
    {
        return RunBusinessAction::run(fn (): Announcement => app(SaveAnnouncement::class)->handle(
            $record,
            (string) $data['title'],
            (string) $data['body'],
            CarbonImmutable::parse((string) $data['starts_at']),
            filled($data['ends_at'] ?? null) ? CarbonImmutable::parse((string) $data['ends_at']) : null,
        ), $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAnnouncements::route('/')];
    }
}
