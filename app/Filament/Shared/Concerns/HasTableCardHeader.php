<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

use function Filament\Support\generate_icon_html;

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

    /** Ikon kotak 28px (warna primary lembut) + judul; markup di-escape di sini. */
    protected function tableCardHeading(): Htmlable
    {
        $resource = static::getResource();
        $icon = $resource::getNavigationIcon();
        $iconHtml = $icon instanceof BackedEnum || is_string($icon) ? generate_icon_html($icon)?->toHtml() : null;

        return new HtmlString(
            '<span class="gs-ta-title">'
            .($iconHtml !== null ? '<span class="gs-ta-title-icon" aria-hidden="true">'.$iconHtml.'</span>' : '')
            .'<span class="gs-ta-title-text">'.e('Daftar '.$resource::getTitleCasePluralModelLabel()).'</span>'
            .'</span>'
        );
    }
}
