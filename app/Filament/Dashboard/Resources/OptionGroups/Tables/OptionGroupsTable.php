<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Tables;

use App\Actions\Product\DeleteOptionGroup;
use App\Filament\Shared\Layout;
use App\Models\OptionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

final class OptionGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('options')->withCount('products'))
            ->columns([
                TextColumn::make('name')->label('Nama grup')->weight('medium')->searchable(),
                TextColumn::make('options.name')->label('Opsi')->badge()->color('gray')->limitList(4),
                TextColumn::make('rule')
                    ->label('Aturan')
                    ->state(fn (OptionGroup $record): string => match (true) {
                        $record->min_select === 0 => "Opsional, maks. {$record->max_select}",
                        $record->min_select === $record->max_select => "Wajib pilih {$record->min_select}",
                        default => "Pilih {$record->min_select}–{$record->max_select}",
                    }),
                TextColumn::make('products_count')->label('Dipakai produk')->numeric(locale: 'id')->alignEnd(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah'),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Hapus')
                    ->modalHeading('Hapus grup opsi')
                    ->modalDescription(fn (OptionGroup $record): HtmlString => Layout::confirmText(
                        'Yakin ingin menghapus <strong>'.e($record->name).'</strong>?',
                        'Grup ini dilepas dari semua produk. Riwayat transaksi tidak berubah.',
                    ))
                    ->using(function (OptionGroup $record): bool {
                        app(DeleteOptionGroup::class)->handle($record);

                        return true;
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->emptyStateHeading('Belum ada grup opsi')
            ->emptyStateDescription('Buat grup seperti Ukuran atau Gula, lalu pasangkan ke produk');
    }
}
