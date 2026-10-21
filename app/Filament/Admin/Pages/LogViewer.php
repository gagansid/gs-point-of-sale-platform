<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Admin\ClearLogFile;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Tables\TableHeading;
use App\Support\LogReader;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Sistem → Log: membaca log Laravel tanpa SSH (SPEC panel admin) dalam tabel: entri terbaru dari 1 MB terakhir file
 * (maks. LIMIT entri), pencarian teks, filter file/level/lingkungan/tanggal/stack trace, klik baris → detail.
 * Unduh file utuh; kosongkan file (konfirmasi kata sandi). Nama file hanya dari daftar (LogReader::path).
 */
final class LogViewer extends Page implements HasActions, HasSchemas, HasTable
{
    use HasIconBreadcrumbs;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Entri yang dibaca dari akhir file sebelum difilter (memori PHP shared hosting). */
    public const LIMIT = 1000;

    private const COLORS = [
        'emergency' => 'danger', 'alert' => 'danger', 'critical' => 'danger', 'error' => 'danger',
        'warning' => 'warning', 'notice' => 'info', 'info' => 'info', 'debug' => 'gray',
    ];

    protected string $view = 'filament.admin.pages.log-viewer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Log';

    protected static ?string $title = 'Log';

    protected static ?string $slug = 'logs';

    protected static ?int $navigationSort = 9;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** @var array<string, list<array<string, string>>> entri per file (satu request), agar tidak dibaca ulang */
    private array $cache = [];

    public function getSubheading(): string
    {
        return 'Cari error aplikasi tanpa akses server';
    }

    /** File terpilih di filter; tidak dikenal (mis. diubah manual) → file terbaru. */
    public function currentFile(): ?string
    {
        $name = $this->tableFilters['file']['value'] ?? null;

        return LogReader::path(is_string($name) ? $name : null) !== null ? (string) $name : array_key_first(LogReader::files());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(TableHeading::make('Entri log', Heroicon::OutlinedDocumentText))
            ->description(fn (): string => $this->tableDescription())
            ->records(fn (?string $search, array $filters, int|string $page, int|string $recordsPerPage, ?string $sortDirection): LengthAwarePaginator => $this->records($search, $filters, (int) $page, (int) $recordsPerPage, $sortDirection))
            ->defaultSort('time', 'desc')
            ->columns([
                TextColumn::make('time')->label('Waktu')->sortable()->fontFamily(FontFamily::Mono)->size('xs')
                    ->formatStateUsing(fn (string $state): string => self::localTime($state)->translatedFormat('j M Y, H.i.s'))
                    ->description(fn (array $record): string => self::localTime($record['time'])->locale('id')->diffForHumans()),
                TextColumn::make('level')->label('Level')->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => self::COLORS[$state] ?? 'gray'),
                TextColumn::make('text')->label('Pesan')->searchable()->wrap()->weight('medium')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 160))
                    ->description(fn (array $record): ?string => match (true) {
                        $record['exception'] !== null => Str::limit((string) $record['exception'], 140),
                        $record['data'] !== null => Str::limit((string) preg_replace('/\s+/', ' ', $record['data']), 140),
                        default => null,
                    })
                    ->extraCellAttributes(['class' => 'gs-ta-cell-wide']),
                TextColumn::make('env')->label('Lingkungan')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('has_trace')->label('Stack trace')->boolean()->alignCenter()
                    ->trueIcon(Heroicon::OutlinedCodeBracket)->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('warning')->falseColor('gray')
                    ->tooltip(fn (array $record): string => $record['has_trace'] ? 'Ada stack trace / konteks' : 'Tanpa stack trace'),
            ])
            ->filters([
                SelectFilter::make('file')->label('File log')->selectablePlaceholder(false)
                    ->options(fn (): array => collect(LogReader::files())
                        ->mapWithKeys(fn (array $f, string $name): array => [$name => $name.' ('.Number::fileSize($f['size'], 1).')'])->all())
                    ->default(fn (): ?string => array_key_first(LogReader::files())),
                SelectFilter::make('level')->label('Level')->multiple()
                    ->options(collect(LogReader::LEVELS)->mapWithKeys(fn (string $l): array => [$l => ucfirst($l)])->all()),
                SelectFilter::make('env')->label('Lingkungan')
                    ->options(fn (): array => collect($this->entries())->pluck('env')->unique()->sort()->mapWithKeys(fn (string $e): array => [$e => $e])->all()),
                TernaryFilter::make('has_trace')->label('Stack trace')->trueLabel('Ada')->falseLabel('Tidak ada'),
                Filter::make('date')->label('Tanggal')
                    ->schema([
                        DatePicker::make('from')->label('Tanggal dari')->maxDate(now()),
                        DatePicker::make('until')->label('Sampai')->maxDate(now()),
                    ])
                    ->columns(2)->columnSpan(2)
                    ->indicateUsing(function (array $data): array {
                        $fmt = fn (mixed $d): string => CarbonImmutable::parse((string) $d)->locale('id')->translatedFormat('j M Y');

                        return array_values(array_filter([
                            filled($data['from'] ?? null) ? Indicator::make('Dari '.$fmt($data['from'])) : null,
                            filled($data['until'] ?? null) ? Indicator::make('Sampai '.$fmt($data['until'])) : null,
                        ]));
                    }),
            ])
            ->recordAction('detail')
            ->recordActions([
                Action::make('detail')->label('Detail')->iconButton()->icon(Heroicon::OutlinedEye)->tooltip('Lihat detail')
                    ->modalHeading(fn (array $record): string => strtoupper($record['level']).' · '.self::localTime($record['time'])->translatedFormat('j M Y, H.i.s'))
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (array $record) => view('filament.admin.pages.log-entry-detail', [
                        'entry' => $record,
                        'color' => self::COLORS[$record['level']] ?? 'gray',
                        'time' => self::localTime($record['time']),
                        'file' => $this->currentFile(),
                    ])),
            ])
            ->emptyStateHeading(fn (): string => $this->currentFile() === null ? 'Belum ada file log' : 'Tidak ada entri yang cocok')
            ->emptyStateDescription('Ubah kata kunci, pilih file lain, atau atur ulang filter')
            ->emptyStateIcon(Heroicon::OutlinedDocumentMagnifyingGlass);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label('Unduh')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (): bool => $this->currentFile() !== null)
                ->action(fn (): BinaryFileResponse => response()->download((string) LogReader::path($this->currentFile()))),
            Action::make('clear')->label('Kosongkan')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn (): bool => $this->currentFile() !== null)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Kosongkan '.$this->currentFile().'?')
                ->modalDescription('Isi file dihapus permanen. Unduh dulu bila masih diperlukan.')
                ->modalSubmitActionLabel('Kosongkan')
                ->schema([
                    TextInput::make('current_password')->label('Kata sandi Anda')->password()->required()->currentPassword(guard: 'admin'),
                ])
                ->action(function (): void {
                    app(ClearLogFile::class)->handle((string) $this->currentFile());
                    $this->cache = [];
                    Notification::make()->success()->title('File log dikosongkan')->send();
                }),
        ];
    }

    /**
     * Entri file terpilih (terbaru dulu) + id & penanda stack trace.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        $file = $this->currentFile();
        $path = LogReader::path($file);
        if ($path === null) {
            return [];
        }

        // Kunci stabil dari isi entri (bukan urutan): log baru di antara render & klik tidak menggeser detail
        $seen = [];

        return $this->cache[(string) $file] ??= array_map(function (array $e) use (&$seen): array {
            $key = substr(hash('sha256', $e['time'].'|'.$e['level'].'|'.$e['message'].'|'.$e['context']), 0, 16);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            return [...$e, ...self::parse($e['message'], $e['context']), 'id' => $seen[$key] > 1 ? $key.'-'.$seen[$key] : $key];
        }, LogReader::entries($path, limit: self::LIMIT));
    }

    /**
     * Pisahkan entri menjadi teks, data JSON (rapi), exception, dan stack trace untuk tabel & detail.
     * Exception Laravel ditulis `pesan {"exception":"[object] (Kelas(code: 0): pesan at file:baris)\n[stacktrace]\n#0 …"}`
     * — bukan JSON valid (baris baru mentah), jadi diurai dengan pola tersendiri.
     *
     * @return array{text: string, data: string|null, exception: string|null, trace: string|null, has_trace: bool}
     */
    private static function parse(string $message, string $context): array
    {
        $full = rtrim($message."\n".$context);

        if (preg_match('/^(?<text>.*?)\s\{"exception":"\[object\] (?<ex>.*)$/s', $full, $m) === 1) {
            // Isi string JSON: kembalikan escape \\ \/ \" ke karakter aslinya
            $body = str_replace(['\\\\', '\\/', '\\"'], ['\\', '/', '"'], (string) preg_replace('/"\}(\s+(\{.*\}|\[\]))?\s*$/s', '', $m['ex']));
            [$exception, $trace] = array_pad(preg_split('/\R\[stacktrace\]\R/', $body, 2) ?: [], 2, null);

            return ['text' => trim($m['text']), 'data' => null, 'exception' => trim((string) $exception), 'trace' => $trace !== null ? trim($trace) : null, 'has_trace' => true];
        }

        return [...self::splitMessage($message), 'exception' => null, 'trace' => trim($context) !== '' ? trim($context) : null, 'has_trace' => trim($context) !== ''];
    }

    /**
     * Format Laravel "pesan {konteks} {ekstra}": teks dipisah dari JSON di akhir baris; JSON dirapikan untuk detail.
     *
     * @return array{text: string, data: string|null}
     */
    private static function splitMessage(string $message): array
    {
        if (preg_match('/^(?<text>.*?)\s(?<a>\{.*\}|\[\])(?:\s(?<b>\{.*\}|\[\]))?\s*$/s', $message, $m) === 1) {
            $blocks = array_filter([$m['a'], $m['b'] ?? null], fn (?string $b): bool => filled($b) && $b !== '[]');
            $decoded = array_map(fn (string $b): mixed => json_decode($b, true), $blocks);

            if ($decoded !== [] && ! in_array(null, $decoded, true)) {
                $merged = array_merge(...array_map(fn (mixed $d): array => (array) $d, $decoded));

                return ['text' => trim($m['text']), 'data' => (string) json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            }

            if (trim($m['a']) === '[]' && ($m['b'] ?? '') === '') {
                return ['text' => trim($m['text']), 'data' => null];
            }
        }

        return ['text' => $message, 'data' => null];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<array-key, mixed>
     */
    private function records(?string $search, array $filters, int $page, int $perPage, ?string $direction): LengthAwarePaginator
    {
        $levels = array_values(array_intersect((array) ($filters['level']['values'] ?? []), LogReader::LEVELS));
        $env = $filters['env']['value'] ?? null;
        $trace = $filters['has_trace']['value'] ?? null;
        $from = filled($filters['date']['from'] ?? null) ? CarbonImmutable::parse((string) $filters['date']['from'], FilamentTimezone::get())->startOfDay() : null;
        $until = filled($filters['date']['until'] ?? null) ? CarbonImmutable::parse((string) $filters['date']['until'], FilamentTimezone::get())->endOfDay() : null;
        $needle = filled($search) ? mb_strtolower(trim((string) $search)) : null;

        $rows = collect($this->entries())
            ->when($levels !== [], fn ($c) => $c->filter(fn (array $e): bool => in_array($e['level'], $levels, true)))
            ->when(filled($env), fn ($c) => $c->where('env', $env))
            ->when($trace !== null && $trace !== '', fn ($c) => $c->filter(fn (array $e): bool => $e['has_trace'] === (bool) $trace))
            ->when($from !== null || $until !== null, fn ($c) => $c->filter(function (array $e) use ($from, $until): bool {
                $at = self::localTime($e['time']);

                return ($from === null || $at->gte($from)) && ($until === null || $at->lte($until));
            }))
            ->when($needle !== null, fn ($c) => $c->filter(fn (array $e): bool => str_contains(mb_strtolower($e['message']."\n".$e['context']), (string) $needle)))
            ->values();

        if ($direction === 'asc') {
            $rows = $rows->reverse()->values();
        }

        $perPage = max(1, $perPage);

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->keyBy('id')->all(), $rows->count(), $perPage, $page);
    }

    private function tableDescription(): string
    {
        $file = $this->currentFile();
        if ($file === null) {
            return 'Belum ada file *.log di storage/logs';
        }

        return 'Maks. '.number_format(self::LIMIT, 0, ',', '.').' entri terbaru dari 1 MB terakhir '.$file.'. Klik baris untuk detail; unduh untuk seluruh isi';
    }

    /** Waktu log = zona waktu aplikasi (UTC) → zona waktu panel (WIB). */
    private static function localTime(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, (string) config('app.timezone'))->setTimezone(FilamentTimezone::get())->locale('id');
    }
}
