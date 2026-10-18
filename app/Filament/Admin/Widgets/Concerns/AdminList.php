<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Concerns;

use Filament\Actions\Action;
use Filament\Tables\Table;

/**
 * Tabel ringkas Dasbor /admin: maks. LIMIT baris terbaru/terdesak, tanpa paginasi & pencarian, tautan "Lihat semua"
 * ke halaman lengkap (bila ada). Data kecil (LIMIT) sehingga tidak di-cache: selalu terkini.
 */
trait AdminList
{
    private const LIMIT = 6;

    private static function compact(Table $table, string $heading, ?string $allUrl, string $emptyHeading, string $emptyIcon): Table
    {
        return $table
            ->heading($heading)
            ->paginated(false)
            ->headerActions($allUrl === null ? [] : [
                Action::make('all')->label('Lihat semua')->link()->url($allUrl),
            ])
            ->emptyStateHeading($emptyHeading)
            ->emptyStateIcon($emptyIcon);
    }
}
