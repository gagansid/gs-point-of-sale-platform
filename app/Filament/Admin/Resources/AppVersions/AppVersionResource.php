<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AppVersions;

use App\Actions\Admin\SaveAppVersion;
use App\Enums\AppPlatform;
use App\Filament\Admin\Resources\AppVersions\Pages\ManageAppVersions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\AppVersion;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Aplikasi → Versi aplikasi: versi minimum & terbaru aplikasi kasir per platform (Android/iOS).
 * Di bawah minimum → API menolak 426 APP_UPDATE_REQUIRED; di bawah terbaru → aplikasi menawarkan
 * update (wajib bila "Paksa update").
 */
final class AppVersionResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = AppVersion::class;

    protected static ?string $slug = 'app-versions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Aplikasi';

    protected static ?string $navigationLabel = 'Versi aplikasi';

    protected static ?string $modelLabel = 'versi aplikasi';

    protected static ?string $pluralModelLabel = 'versi aplikasi';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, string> platform aplikasi native (kasir web tidak berversi) */
    public static function platforms(): array
    {
        return collect([AppPlatform::Android, AppPlatform::Ios])->mapWithKeys(fn (AppPlatform $p): array => [$p->value => $p->getLabel()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $version = fn (string $name, string $label, string $help): TextInput => TextInput::make($name)->label($label)->required()->maxLength(20)
            ->regex(SaveAppVersion::PATTERN)->validationMessages(['regex' => 'Format versi: tiga angka bertitik, mis. 1.4.0'])
            ->placeholder('1.0.0')->helperText($help);

        return $schema->components([
            Select::make('platform')->label('Platform')->required()->native(false)
                ->options(fn (?AppVersion $record): array => array_diff_key(self::platforms(), $record === null
                    ? array_flip(AppVersion::query()->pluck('platform')->map(fn (AppPlatform $p): string => $p->value)->all())
                    : []))
                ->disabledOn('edit'),
            $version('min_version', 'Versi minimum', 'Di bawah ini aplikasi ditolak & wajib update'),
            $version('latest_version', 'Versi terbaru', 'Versi di Play Store / App Store')
                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if (is_string($value) && is_string($get('min_version')) && version_compare($value, $get('min_version'), '<')) {
                        $fail('Harus sama atau di atas versi minimum.');
                    }
                }),
            Toggle::make('force_update')->label('Paksa update ke versi terbaru')
                ->helperText('Aplikasi tidak bisa dipakai sebelum update ke versi terbaru'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->paginated(false)
            ->defaultSort('platform')
            ->columns([
                TextColumn::make('platform')->label('Platform')->badge()->color('gray'),
                TextColumn::make('min_version')->label('Minimum')->fontFamily('mono'),
                TextColumn::make('latest_version')->label('Terbaru')->fontFamily('mono'),
                // Badge Ya/Tidak (bukan ikon ✕ merah: "tidak" bukan kesalahan)
                TextColumn::make('force_update')->label('Paksa update')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Ya' : 'Tidak')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                ...AuditColumns::make(created: false),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('md')
                    ->using(fn (AppVersion $record, array $data, EditAction $action): AppVersion => RunBusinessAction::run(fn (): AppVersion => app(SaveAppVersion::class)->handle(
                        $record,
                        $record->platform,
                        (string) $data['min_version'],
                        (string) $data['latest_version'],
                        (bool) ($data['force_update'] ?? false),
                    ), $action)),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedDevicePhoneMobile, 'versi aplikasi', 'Tambahkan Android/iOS agar aplikasi lama bisa dipaksa update');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAppVersions::route('/')];
    }
}
