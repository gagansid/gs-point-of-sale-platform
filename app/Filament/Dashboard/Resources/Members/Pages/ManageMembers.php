<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\Members\Pages;

use App\Actions\Member\Data\MemberData;
use App\Actions\Member\SaveMember;
use App\Filament\Dashboard\Resources\Members\MemberResource;
use App\Filament\Shared\Concerns\HasCardTabs;
use App\Filament\Shared\Concerns\HasIconBreadcrumbs;
use App\Filament\Shared\Concerns\HasTableCardHeader;
use App\Filament\Shared\Tables\StatusTabs;
use App\Models\Member;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

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
}
