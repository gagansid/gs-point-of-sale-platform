<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Receivables;

use App\Enums\ReceivableEntryType;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\Members\Actions\ReceivableActions;
use App\Filament\Dashboard\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Shared\Columns\MoneyColumn;
use App\Filament\Shared\Concerns\HasSentenceCaseLabels;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Features;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

/**
 * Pelanggan → Kasbon (ADR 0023; izin member.view, pelunasan member.manage): piutang semua member dalam satu daftar —
 * saldo, batas, umur kasbon (sejak saldo terakhir naik dari nol), dan pelunasan terakhir. Ledger tetap di
 * `receivable_entries`; aksi memanggil Action yang sama dengan menu Member.
 */
final class ReceivableResource extends DashboardResource
{
    use HasSentenceCaseLabels;

    /** Kasbon dianggap lama setelah sekian hari belum lunas (tab & warna kolom umur). */
    public const OVERDUE_DAYS = 30;

    protected static ?string $model = Member::class;

    protected static ?string $slug = 'receivables';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Pelanggan';

    protected static ?string $navigationLabel = 'Kasbon';

    protected static ?string $modelLabel = 'kasbon';

    protected static ?string $pluralModelLabel = 'kasbon';

    protected static ?int $navigationSort = 2;

    protected static bool $isGloballySearchable = false;

    /** Tampil bila program kasbon aktif, atau masih ada kasbon tersisa walau program sudah dimatikan. */
    protected static function canUseMenu(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! Features::enabled('member') || ! $user->hasPermission('member.view')) {
            return false;
        }

        return (Features::enabled('credit') && MemberSetting::current()->credit_enabled)
            || Member::query()->where('receivable_balance', '>', 0)->exists();
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
     * Member yang pernah kasbon, plus umur kasbon & pelunasan terakhir (subquery, satu query per halaman).
     *
     * @return Builder<Member>
     */
    public static function getEloquentQuery(): Builder
    {
        return Member::query()
            ->select('members.*')
            ->selectSub(self::outstandingSinceQuery(), 'outstanding_since')
            ->selectSub(fn (QueryBuilder $q): QueryBuilder => $q->from('receivable_entries')->selectRaw('MAX(created_at)')
                ->whereColumn('receivable_entries.member_id', 'members.id')
                ->where('type', ReceivableEntryType::Payment->value), 'last_payment_at')
            ->where(fn (Builder $q) => $q->where('receivable_balance', '>', 0)->orWhereHas('receivables'));
    }

    /**
     * Waktu saldo terakhir naik dari nol (kasbon pertama yang belum lunas). Pelunasan sebagian tidak mengubahnya.
     *
     * @return Closure(QueryBuilder): QueryBuilder
     */
    public static function outstandingSinceQuery(): Closure
    {
        return fn (QueryBuilder $q): QueryBuilder => $q->from('receivable_entries')->selectRaw('MAX(created_at)')
            ->whereColumn('receivable_entries.member_id', 'members.id')
            ->where('type', ReceivableEntryType::Charge->value)
            ->whereRaw('balance_after - amount <= 0');
    }

    /**
     * Belum lunas dan sudah lebih dari OVERDUE_DAYS hari.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public static function overdue(Builder $query): Builder
    {
        // Hari kalender di zona outlet, sama dengan kolom umur (ageDays): umur ≥ N hari ⇔ sejak < awal hari (hari ini − N + 1)
        $tz = CurrentOutlet::timezone();
        $cutoff = CarbonImmutable::now($tz)->startOfDay()->subDays(self::OVERDUE_DAYS - 1)->utc()->format('Y-m-d H:i:s');

        return $query->where('receivable_balance', '>', 0)
            ->where(self::outstandingSinceQuery(), '<', $cutoff);
    }

    /**
     * Saldo melebihi batas (batas member, atau batas bawaan program). Tanpa batas = tidak pernah melebihi.
     *
     * @param  Builder<Member>  $query
     * @return Builder<Member>
     */
    public static function overLimit(Builder $query): Builder
    {
        $default = MemberSetting::current()->default_credit_limit;

        return $query->where('receivable_balance', '>', 0)
            ->whereRaw('receivable_balance > COALESCE(credit_limit, ?)', [$default]);
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->defaultSort('receivable_balance', 'desc')
            // Nomor HP berspasi ("0812 3456 7890") dicari utuh, bukan per kata
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->label('Member')->weight('medium')->sortable()
                    ->description(fn (Member $record): string => collect([$record->code, Phone::display($record->phone)])->filter(fn (?string $v): bool => filled($v) && $v !== '—')->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => Member::applySearch($query, $search)),
                MoneyColumn::make('receivable_balance')->label('Kasbon')->sortable()->weight('medium')
                    ->formatStateUsing(fn (string $state): string => Money::isPositive($state) ? Money::format($state) : 'Lunas')
                    ->color(fn (string $state): string => Money::isPositive($state) ? 'warning' : 'success')
                    ->summarize(Sum::make()->label('Total')->formatStateUsing(fn ($state): string => Money::format(Money::fromDb($state)))),
                TextColumn::make('credit_limit')->label('Batas')->alignEnd()
                    ->state(fn (Member $record): ?string => $record->effectiveCreditLimit())
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? Money::format($state) : '—')
                    ->placeholder('Tanpa batas')
                    ->description(fn (Member $record): ?string => self::usage($record))
                    ->color(fn (Member $record): ?string => self::isOverLimit($record) ? 'danger' : null),
                TextColumn::make('outstanding_since')->label('Belum lunas sejak')->sortable()
                    ->state(fn (Member $record): ?string => Money::isPositive($record->receivable_balance) ? self::since($record) : null)
                    ->formatStateUsing(fn (string $state): string => CarbonImmutable::parse($state, 'UTC')->setTimezone(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y'))
                    ->description(fn (Member $record): ?string => ($days = self::ageDays($record)) !== null ? ($days === 0 ? 'Hari ini' : $days.' hari') : null)
                    ->color(fn (Member $record): ?string => (self::ageDays($record) ?? 0) >= self::OVERDUE_DAYS ? 'danger' : null)
                    ->placeholder('—'),
                TextColumn::make('last_payment_at')->label('Pelunasan terakhir')->sortable()
                    ->formatStateUsing(fn (string $state): string => CarbonImmutable::parse($state, 'UTC')->locale('id')->diffForHumans())
                    ->tooltip(fn (Member $record): ?string => self::localDate($record->getAttribute('last_payment_at')))
                    ->placeholder('Belum pernah'),
            ])
            ->recordAction('receivables')
            ->recordActions([
                ReceivableActions::history()->iconButton()->icon(Heroicon::OutlinedEye)->tooltip('Riwayat kasbon')->visible(true),
                ActionGroup::make([
                    ReceivableActions::pay(),
                    ReceivableActions::creditLimit(),
                ])->tooltip('Aksi lain'),
            ]);

        return TableEmptyState::apply($table, Heroicon::OutlinedBanknotes, 'kasbon',
            'Kasbon member dari kasir (bayar nanti) dan pelunasannya akan muncul di sini');
    }

    /** "72% terpakai" — hanya bila ada batas positif. */
    private static function usage(Member $record): ?string
    {
        $limit = $record->effectiveCreditLimit();

        if ($limit === null || ! Money::isPositive($limit) || ! Money::isPositive($record->receivable_balance)) {
            return null;
        }

        return number_format((float) Money::div(Money::mul($record->receivable_balance, 100), $limit), 0, ',', '.').'% terpakai';
    }

    private static function isOverLimit(Member $record): bool
    {
        $limit = $record->effectiveCreditLimit();

        return $limit !== null && Money::compare($record->receivable_balance, $limit) > 0;
    }

    private static function since(Member $record): ?string
    {
        $value = $record->getAttribute('outstanding_since');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Umur kasbon dalam hari (zona outlet); null bila lunas. */
    public static function ageDays(Member $record): ?int
    {
        $since = Money::isPositive($record->receivable_balance) ? self::since($record) : null;

        if ($since === null) {
            return null;
        }

        $tz = CurrentOutlet::timezone();

        return (int) CarbonImmutable::parse($since, 'UTC')->setTimezone($tz)->startOfDay()
            ->diffInDays(CarbonImmutable::now($tz)->startOfDay());
    }

    private static function localDate(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone(CurrentOutlet::timezone())->locale('id')->translatedFormat('j M Y, H.i')
            : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListReceivables::route('/')];
    }
}
