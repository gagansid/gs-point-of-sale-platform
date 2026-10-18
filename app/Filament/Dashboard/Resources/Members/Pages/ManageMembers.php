<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Members\Pages;

use App\Actions\Member\Data\MemberData;
use App\Actions\Member\ImportMembers;
use App\Actions\Member\SaveMember;
use App\Exceptions\BusinessException;
use App\Exports\MemberImportTemplate;
use App\Exports\MembersExport;
use App\Filament\Dashboard\Resources\Members\MemberResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Imports\MemberImportSheet;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\ExportThrottle;
use App\Support\Features;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ManageMembers extends ManageRecords
{
    use HasCardTabs;
    use HasIconBreadcrumbs;
    use HasTableCardHeader;

    protected static string $resource = MemberResource::class;

    /**
     * Tanpa #[Url(as: 'filters')]: filter disimpan di session (persistFiltersInSession).
     *
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = null;

    /** Tanpa #[Url(as: 'tab')]: tab aktif disimpan di session (HasCardTabs). */
    public ?string $activeTab = null;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::make(Member::class);
    }

    /** @return array<Action> */
    protected function getTableCardActions(): array
    {
        return [
            $this->exportAction(),
            $this->importAction(),
            CreateAction::make()
                ->label('Tambah')
                ->icon(Heroicon::OutlinedPlus)
                // Program nonaktif / tanpa izin: tombol disembunyikan, bukan sekadar dinonaktifkan
                ->visible(fn (): bool => MemberResource::canCreate())
                ->modalHeading('Tambah member')
                ->modalWidth('2xl')
                ->createAnother(false)
                ->successNotificationTitle('Member didaftarkan')
                ->using(fn (array $data, CreateAction $action): Member => MemberResource::orNotify(
                    fn (): Member => app(SaveMember::class)->handle(null, MemberData::fromArray($data), CurrentOutlet::selectedId() !== null ? CurrentOutlet::get() : null)['member'],
                    $action,
                )),
        ];
    }

    /** Export daftar member ke Excel (M7, member.manage); dibatasi seperti export laporan (R3). */
    public function exportAction(): Action
    {
        return Action::make('export')->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(fn (): bool => self::canManage() && Features::enabled('report_export'))
            ->action(function (): ?BinaryFileResponse {
                $user = auth()->user();
                abort_unless($user instanceof User && self::canManage() && Features::enabled('report_export'), 403);

                if (($wait = ExportThrottle::attempt($user)) !== null) {
                    Notification::make()->danger()->title(ExportThrottle::message($wait))->send();

                    return null;
                }

                return Excel::download(new MembersExport(CurrentOutlet::timezone()), 'member-'.now(CurrentOutlet::timezone())->format('Y-m-d').'.xlsx');
            });
    }

    /** Import member dari Excel/CSV (M7, member.manage); semua baris valid atau tidak ada yang disimpan. */
    public function importAction(): Action
    {
        return Action::make('import')->label('Import')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn (): bool => self::canManage() && MemberSetting::current()->enabled)
            ->modalHeading('Import member dari Excel')
            ->modalDescription('Unduh template, isi satu member per baris, lalu unggah. Nomor HP yang sudah terdaftar akan diperbarui (poin & kode member tetap).')
            ->modalSubmitActionLabel('Import')
            ->modalWidth('lg')
            ->schema([
                FileUpload::make('file')->label('File Excel atau CSV')
                    ->disk('local')->directory('imports')->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel', 'text/csv', 'text/plain',
                    ])
                    ->maxSize(2048)
                    ->required()
                    ->helperText('.xlsx atau .csv · maks. 2 MB · maks. '.ImportMembers::MAX_ROWS.' baris'),
                Checkbox::make('consent')->label('Semua pelanggan di file ini sudah menyetujui datanya dipakai untuk program member')
                    ->accepted(),
            ])
            ->extraModalFooterActions([
                Action::make('template')->label('Unduh template')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn () => Excel::download(new MemberImportTemplate, 'template-import-member.xlsx')),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(self::canManage(), 403);
                $path = (string) $data['file'];

                try {
                    $rows = Excel::toArray(new MemberImportSheet, $path, 'local')[0] ?? [];
                    $result = app(ImportMembers::class)->handle($rows, (bool) ($data['consent'] ?? false),
                        CurrentOutlet::selectedId() !== null ? CurrentOutlet::get() : null);

                    Notification::make()->success()->title('Import selesai')
                        ->body("{$result['created']} member baru, {$result['updated']} member diperbarui")->send();
                } catch (BusinessException $e) {
                    /** @var array<string, list<string>> $details */
                    $details = is_array($e->details) ? $e->details : [];
                    $lines = collect($details)->take(8)
                        ->map(fn (mixed $messages, string $line): string => ucfirst($line).': '.implode('; ', (array) $messages))
                        ->implode("\n");

                    Notification::make()->danger()->title($e->getMessage())->body($lines !== '' ? $lines : null)->persistent()->send();
                    $action->halt();
                } finally {
                    Storage::disk('local')->delete($path);
                }
            });
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can('member.manage') ?? false;
    }
}
