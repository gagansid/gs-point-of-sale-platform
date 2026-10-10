<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

/**
 * Tab cepat halaman daftar ditampilkan di dalam header card tabel (baris kedua, gaya garis bawah),
 * bukan sebagai pill di atas card (table.md). Tab aktif disimpan di session sehingga tetap sama
 * setelah refresh atau berpindah menu.
 *
 * Agar tab tidak ditulis ke URL (?tab=...), halaman pemakai WAJIB mendeklarasikan ulang
 * `public ?string $activeTab = null;` di class-nya sendiri (tanpa #[Url]). Deklarasi di trait
 * tidak cukup: PHP mengabaikan properti trait yang identik dengan properti induk.
 *
 * Markup: resources/views/filament/shared/card-tabs.blade.php, dirender lewat render hook
 * TablesRenderHook::HEADER_AFTER di App\Filament\Shared\Layout.
 */
trait HasCardTabs
{
    public function content(Schema $schema): Schema
    {
        // Sama dengan ListRecords::content() tanpa komponen tab di atas tabel
        return $schema->components([
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $remembered = session()->get($this->getActiveTabSessionKey());

        // Hanya kunci tab yang dikenal; nilai session lain diabaikan
        if (is_string($remembered) && array_key_exists($remembered, $this->getCachedTabs())) {
            return $remembered;
        }

        return array_key_first($this->getCachedTabs());
    }

    public function updatedActiveTab(): void
    {
        if (! array_key_exists((string) $this->activeTab, $this->getCachedTabs())) {
            $this->activeTab = (string) array_key_first($this->getCachedTabs());
        }

        session()->put($this->getActiveTabSessionKey(), $this->activeTab);

        parent::updatedActiveTab();
    }

    /** Dipanggil render hook header tabel. */
    public function renderCardTabs(): string
    {
        return view('filament.shared.card-tabs', [
            'tabs' => $this->getCachedTabs(),
            'activeTab' => $this->activeTab,
        ])->render();
    }

    protected function getActiveTabSessionKey(): string
    {
        return 'gs.list_active_tab.'.md5(static::class);
    }
}
