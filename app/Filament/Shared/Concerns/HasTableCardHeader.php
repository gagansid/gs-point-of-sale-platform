<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use App\Filament\Shared\Tables\TableHeading;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Halaman daftar gaya gs-task-tracker (layout.md §4): judul "Daftar {objek}" berikon (ikon menu resource) dan
 * tombol tambah berada di header card tabel, bukan di header halaman.
 *
 * Halaman pemakai mendefinisikan getTableCardActions() alih-alih getHeaderActions().
 */
trait HasTableCardHeader
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading($this->tableCardHeading())
            ->headerActions($this->getTableCardActions());
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getTableCardActions(): array
    {
        return [];
    }

    /** Ikon menu resource + "Daftar {objek}" (TableHeading). */
    protected function tableCardHeading(): Htmlable
    {
        $resource = static::getResource();
        $icon = $resource::getNavigationIcon();

        return TableHeading::make('Daftar '.$resource::getTitleCasePluralModelLabel(), $icon instanceof BackedEnum || is_string($icon) ? $icon : null);
    }
}
