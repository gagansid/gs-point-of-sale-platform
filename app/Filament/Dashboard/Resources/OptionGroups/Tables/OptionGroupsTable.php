<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Tables;

use App\Actions\Product\DeleteOptionGroup;
use App\Filament\Shared\Actions\BulkDeleteAction;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\OptionGroup;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

final class OptionGroupsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->with('options')->withCount('products'))
            ->columns([
                TextColumn::make('name')->label('Nama grup')->weight('medium')->searchable()->sortable(),
                TextColumn::make('options.name')->label('Opsi')->badge()->color('gray')->limitList(4),
                TextColumn::make('rule')
                    ->label('Aturan')
                    ->state(fn (OptionGroup $record): string => match (true) {
                        $record->min_select === 0 => "Opsional, maks. {$record->max_select}",
                        $record->min_select === $record->max_select => "Wajib pilih {$record->min_select}",
                        default => "Pilih {$record->min_select}–{$record->max_select}",
                    })
                    // Wajib dulu (min_select terbesar), lalu batas pilihan
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('min_select', $direction === 'asc' ? 'desc' : 'asc')
                        ->orderBy('max_select', $direction)),
                TextColumn::make('products_count')->label('Dipakai produk')->numeric(locale: 'id')->alignEnd()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('required')
                    ->label('Aturan')
                    ->trueLabel('Wajib dipilih')
                    ->falseLabel('Opsional')
                    ->queries(
                        true: fn (Builder $query) => $query->where('min_select', '>', 0),
                        false: fn (Builder $query) => $query->where('min_select', 0),
                    ),
                TernaryFilter::make('used')
                    ->label('Pemakaian')
                    ->trueLabel('Dipakai produk')
                    ->falseLabel('Belum dipakai')
                    ->queries(
                        true: fn (Builder $query) => $query->has('products'),
                        false: fn (Builder $query) => $query->doesntHave('products'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Ubah'),
                ActionGroup::make([
                    DeleteAction::make()
                        ->modalHeading('Hapus grup opsi')
                        ->modalDescription(fn (OptionGroup $record): HtmlString => Layout::confirmText(
                            'Yakin ingin menghapus <strong>'.e($record->name).'</strong>?',
                            'Grup ini dilepas dari semua produk. Riwayat transaksi tidak berubah.',
                        ))
                        ->using(function (OptionGroup $record): bool {
                            app(DeleteOptionGroup::class)->handle($record);

                            return true;
                        }),
                ])->tooltip('Aksi lain'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkDeleteAction::make(
                        OptionGroup::class,
                        fn (OptionGroup $group) => app(DeleteOptionGroup::class)->handle($group),
                        'grup opsi',
                        'Grup dilepas dari semua produk. Riwayat transaksi tidak berubah.',
                        'product.manage',
                    ),
                ])->label('Aksi massal'),
            ])
            ->defaultSort('name');

        return TableEmptyState::apply($table, Heroicon::OutlinedAdjustmentsHorizontal, 'grup opsi', 'Buat grup seperti Ukuran atau Gula, lalu pasangkan ke produk');
    }
}
