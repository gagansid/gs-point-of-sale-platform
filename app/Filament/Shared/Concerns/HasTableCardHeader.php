<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;

/**
 * Halaman daftar gaya gs-task-tracker (layout.md §4): judul "Daftar {objek}" dan tombol
 * tambah berada di header card tabel, bukan di header halaman.
 *
 * Halaman pemakai mendefinisikan getTableCardActions() alih-alih getHeaderActions().
 */
trait HasTableCardHeader
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('Daftar '.static::getResource()::getTitleCasePluralModelLabel())
            ->headerActions($this->getTableCardActions());
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getTableCardActions(): array
    {
        return [];
    }
}
