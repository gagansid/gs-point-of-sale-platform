<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\StampPrograms;

use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\SetStampProgramActive;
use App\Filament\Dashboard\Resources\StampPrograms\Pages\ManageStampPrograms;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\RunBusinessAction;
use App\Filament\Shared\Columns\AuditColumns;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Category;
use App\Models\MemberSetting;
use App\Models\MemberStamp;
use App\Models\Product;
use App\Models\StampProgram;
use App\Models\User;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Pelanggan → Kartu stempel (ADR 0013, Q65; izin member.settings): program "beli N gratis 1" per produk/kategori.
 */
final class StampProgramResource extends Resource
{
    use HasSentenceCaseLabels;

    protected static ?string $model = StampProgram::class;

    protected static ?string $slug = 'stamp-programs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Kartu stempel';

    protected static ?string $modelLabel = 'kartu stempel';

    protected static ?string $pluralModelLabel = 'kartu stempel';

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Features::enabled('member') && $user->role->hasFullDashboard() && $user->hasPermission('member.settings');
    }

    public static function canCreate(): bool
    {
        return (auth()->user()?->can('member.settings') ?? false) && MemberSetting::current()->enabled;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('member.settings') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $rules = SaveStampProgram::rules();

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nama')->required()->rules($rules['name'])->placeholder('Kopi ke-10 gratis')->autofocus(),
            TextInput::make('stamps_required')->label('Stempel untuk 1 gratis')->integer()->required()->rules($rules['stamps_required'])
                ->default(10)->suffix('stempel')->helperText('1 item yang memenuhi = 1 stempel'),
            Select::make('product_ids')->label('Produk')->multiple()->searchable()->preload()
                ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Item gratis = item termurah yang memenuhi di keranjang')
                ->columnSpanFull(),
            Select::make('category_ids')->label('Atau semua produk di kategori')->multiple()->searchable()->preload()
                ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                ->columnSpanFull(),
            // Tanggal dibuat & diubah (audit)
            AuditInfo::make(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('items'))
            ->columns([
                TextColumn::make('name')->label('Nama')->weight('medium')->searchable()->sortable(),
                TextColumn::make('stamps_required')->label('Gratis setiap')->suffix(' stempel')->sortable()->alignEnd(),
                TextColumn::make('coverage')->label('Berlaku untuk')
                    ->state(fn (StampProgram $record): string => self::coverage($record)),
                TextColumn::make('members')->label('Member punya stempel')->alignEnd()
                    ->state(fn (StampProgram $record): int => DB::query()->fromSub(
                        MemberStamp::query()->where('stamp_program_id', $record->id)->groupBy('member_id')->havingRaw('SUM(`change`) > 0')->select('member_id'),
                        'holders',
                    )->count()),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (StampProgram $record): string => $record->is_active ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state): string => $state === 'Aktif' ? 'success' : 'warning'),
                ...AuditColumns::make(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah')->modalWidth('2xl')->successNotificationTitle('Kartu stempel diperbarui')
                    ->mutateRecordDataUsing(fn (StampProgram $record, array $data): array => [
                        ...$data,
                        'product_ids' => $record->productIds(),
                        'category_ids' => $record->categoryIds(),
                    ])
                    ->using(fn (StampProgram $record, array $data, EditAction $action): StampProgram => RunBusinessAction::run(
                        fn (): StampProgram => app(SaveStampProgram::class)->handle($record, $data),
                        $action,
                    )),
                ActionGroup::make(ActiveStatusActions::make(
                    StampProgram::class,
                    fn (StampProgram $program, bool $active) => app(SetStampProgramActive::class)->handle($program, $active),
                    'kartu stempel',
                    'Member tidak mendapat stempel baru dan tidak bisa menukar gratis. Saldo stempel tetap tersimpan.',
                    'member.settings',
                ))->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedTicket, 'kartu stempel',
            MemberSetting::current()->enabled
                ? 'Mis. "Kopi ke-10 gratis": pilih produk kopi, 10 stempel'
                : 'Aktifkan dulu di Pengaturan → Program member');
    }

    private static function coverage(StampProgram $program): string
    {
        $products = Product::query()->withTrashed()->whereIn('id', $program->productIds())->orderBy('name')->pluck('name');
        $categories = Category::query()->whereIn('id', $program->categoryIds())->orderBy('name')->pluck('name')
            ->map(fn (string $name): string => 'kategori '.$name);
        $names = $products->merge($categories);

        return $names->count() > 3 ? $names->take(3)->implode(', ').' +'.($names->count() - 3) : $names->implode(', ');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStampPrograms::route('/')];
    }
}
