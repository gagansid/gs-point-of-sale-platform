<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms;

use App\Models\Outlet;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Daftar pilih outlet (docs/standards/ui/components/pick-list.md): kotak bergaris dengan pencarian,
 * satu baris per outlet (inisial · nama · kode di kanan), dan jumlah terpilih di label.
 * Sumber pilihan = query outlet (sudah dibatasi tenant & akses user oleh pemanggil).
 */
final class OutletPickList extends CheckboxList
{
    /** @var (Closure(): Builder<Outlet>)|null */
    private ?Closure $outletQuery = null;

    /** @var Collection<int, Outlet>|null opsi & deskripsi memakai satu query per render */
    private ?Collection $loaded = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->columns(1)
            ->searchable()
            ->searchPrompt('Cari outlet…')
            ->noSearchResultsMessage('Outlet tidak ditemukan')
            ->allowHtml()
            ->live()
            ->validationAttribute('outlet')
            ->extraAlpineAttributes(['class' => 'gs-pick-list'])
            ->options(fn (): array => $this->outlets()->mapWithKeys(fn (Outlet $outlet): array => [
                $outlet->id => self::optionHtml($outlet->name),
            ])->all())
            ->descriptions(fn (): array => $this->outlets()->pluck('code', 'id')->all());
    }

    /** @param Closure(): Builder<Outlet> $query */
    public function outletQuery(Closure $query): static
    {
        $this->outletQuery = $query;

        return $this;
    }

    /** Label + "(n dipilih)" seperti daftar PIC. */
    public function countedLabel(string $label): static
    {
        return $this->label(fn (Get $get): HtmlString => new HtmlString(
            e($label).' <span class="gs-pick-list-count">('.count((array) $get($this->getName())).' dipilih)</span>',
        ));
    }

    /** @return Collection<int, Outlet> */
    private function outlets(): Collection
    {
        return $this->loaded ??= $this->outletQuery !== null
            ? ($this->outletQuery)()->get(['id', 'name', 'code'])
            : collect();
    }

    private static function optionHtml(string $name): HtmlString
    {
        // Nama outlet = input pengguna → selalu di-escape
        $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));

        return new HtmlString('<span class="gs-pick-avatar" aria-hidden="true">'.e($initial).'</span><span class="gs-pick-name">'.e($name).'</span>');
    }
}
