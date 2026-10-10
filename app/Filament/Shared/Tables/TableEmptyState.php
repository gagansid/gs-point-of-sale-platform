<?php

declare(strict_types=1);

namespace App\Filament\Shared\Tables;

use BackedEnum;
use Filament\Tables\Table;

/**
 * Empty state seragam semua tabel (table.md): tanpa tombol (tombol "+ Tambah" sudah di header card),
 * dan pesan berbeda antara "belum ada data" dan "pencarian/filter/tab tidak cocok".
 */
final class TableEmptyState
{
    public static function apply(Table $table, string|BackedEnum $icon, string $object, string $description): Table
    {
        return $table
            ->emptyStateIcon($icon)
            ->emptyStateHeading(fn (Table $table): string => self::isNarrowed($table)
                ? "Tidak ada {$object} yang cocok"
                : "Belum ada {$object}")
            ->emptyStateDescription(fn (Table $table): string => self::isNarrowed($table)
                ? 'Ubah kata kunci, pilih tab lain, atau atur ulang filter'
                : $description)
            ->emptyStateActions([]);
    }

    /** Daftar sedang dipersempit pencarian, filter, atau tab selain tab pertama. */
    public static function isNarrowed(Table $table): bool
    {
        $page = $table->getLivewire();

        if ($table->isFiltered() || $page->hasTableSearch()) {
            return true;
        }

        if (method_exists($page, 'getCachedTabs') && property_exists($page, 'activeTab')) {
            $tabs = $page->getCachedTabs();

            return $tabs !== [] && filled($page->activeTab) && (string) $page->activeTab !== (string) array_key_first($tabs);
        }

        return false;
    }
}
