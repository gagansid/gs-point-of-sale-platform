<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Banks;

use App\Actions\Admin\SaveBank;
use App\Actions\Admin\SetBankActive;
use App\Filament\Admin\Resources\Banks\Pages\ManageBanks;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Bank;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Sistem → Bank (Q124): master bank untuk rekening transfer manual tagihan langganan. Logo vektor otomatis dari singkatan
 * & warna merek, atau unggah logo (PNG/JPG/WEBP). Bank tidak dihapus, hanya dinonaktifkan.
 */
final class BankResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = Bank::class;

    protected static ?string $slug = 'banks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Bank';

    protected static ?string $modelLabel = 'bank';

    protected static ?string $pluralModelLabel = 'bank';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 3])->columnSpanFull()->schema([
                TextInput::make('short_name')->label('Singkatan')->required()->maxLength(12)->live(onBlur: true)->placeholder('BCA')
                    ->helperText('Tampil di logo vektor'),
                TextInput::make('name')->label('Nama bank')->required()->maxLength(80)->placeholder('Bank Central Asia')->columnSpan(['sm' => 2]),
                TextInput::make('code')->label('Kode')->required()->maxLength(20)->placeholder('bca')
                    ->regex('/^[a-z0-9_]+$/')->validationMessages(['regex' => 'Huruf kecil, angka, atau _'])
                    ->helperText('Unik, huruf kecil'),
                ColorPicker::make('color')->label('Warna merek')->required()->default('#2d4282')->live(),
                ColorPicker::make('text_color')->label('Warna teks logo')->required()->default('#ffffff')->live(),
            ]),
            Grid::make(['default' => 1, 'sm' => 3])->columnSpanFull()->schema([
                Placeholder::make('preview')->label('Pratinjau logo')
                    ->content(fn (Get $get, ?Bank $record): HtmlString => new HtmlString(Blade::render('<x-bank-logo :bank="$bank" :size="56" />', [
                        'bank' => (new Bank)->forceFill([
                            'short_name' => (string) ($get('short_name') ?: 'BANK'),
                            'name' => (string) ($get('name') ?: 'Bank'),
                            'color' => (string) ($get('color') ?: '#2d4282'),
                            'text_color' => (string) ($get('text_color') ?: '#ffffff'),
                            // Pratinjau logo tersimpan; unggahan baru tampil di kolom unggah
                            'logo_path' => $record?->logo_path,
                        ]),
                    ]))),
                FileUpload::make('logo_path')->label('Logo (opsional)')->image()->disk('public')->directory(SaveBank::LOGO_DIRECTORY)
                    ->visibility('public')->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(512)
                    ->imageEditor(false)->columnSpan(['sm' => 2])
                    ->helperText('PNG/JPG/WEBP persegi, maks. 512 KB. Kosong = logo vektor dari singkatan & warna'),
            ]),
            Grid::make(['default' => 1, 'sm' => 3])->columnSpanFull()->schema([
                TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(999)->default(0),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('sort_order')
            ->columns([
                ViewColumn::make('logo')->label('Logo')->view('filament.admin.banks.logo-column'),
                TextColumn::make('short_name')->label('Singkatan')->weight('medium')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama bank')->searchable()->sortable(),
                TextColumn::make('code')->label('Kode')->fontFamily(FontFamily::Mono)->size('xs')->searchable()->sortable()->copyable(),
                TextColumn::make('sort_order')->label('Urutan')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_active')->label('Status')->badge()->sortable()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')->successNotificationTitle('Bank diperbarui')
                    ->using(fn (Bank $record, array $data, EditAction $action): Bank => self::save($record, $data, $action)),
                ActionGroup::make(ActiveStatusActions::make(
                    Bank::class,
                    fn (Bank $bank, bool $active) => app(SetBankActive::class)->handle($bank, $active),
                    'bank',
                    'Bank tidak bisa dipilih untuk rekening baru. Rekening yang sudah ada tetap tampil.',
                    null,
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedBuildingLibrary, 'bank', 'Tambah bank untuk rekening transfer manual');
    }

    /** @param  array<string, mixed>  $data */
    public static function save(?Bank $record, array $data, Action $action): Bank
    {
        return RunBusinessAction::run(fn (): Bank => app(SaveBank::class)->handle($record, $data), $action);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBanks::route('/')];
    }
}
