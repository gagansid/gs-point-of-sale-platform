<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Tables;

use App\Actions\Product\DeleteOptionGroup;
use App\Actions\Product\SetOptionAvailability;
use App\Actions\Product\SetOptionGroupActive;
use App\Filament\Shared\Actions\ActiveStatusActions;
use App\Filament\Shared\Actions\BulkDeleteAction;
use App\Filament\Shared\Layout;
use App\Filament\Shared\Tables\TableEmptyState;
use App\Models\OptionGroup;
use App\Models\OutletOption;
use App\Support\CurrentOutlet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

final class OptionGroupsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'options',
                'options.outletStates' => fn ($state) => $state->where('outlet_id', CurrentOutlet::getOrFail()->id),
            ])->withCount('products'))
            ->columns([
                TextColumn::make('name')->label('Nama grup')->weight('medium')->searchable()->sortable(),
                // Merah = habis di outlet aktif (pemilih sidebar) (ADR 0011 / Q43)
                TextColumn::make('options.name')->label('Opsi')->badge()->limitList(4)
                    ->color(fn (string $state, OptionGroup $record): string => $record->options->firstWhere('name', $state)
                        ?->outletStates->contains('is_available', false) ? 'danger' : 'gray')
                    ->tooltip(fn (OptionGroup $record): ?string => $record->options->contains(fn ($option) => $option->outletStates->contains('is_available', false))
                        ? 'Merah = habis di outlet '.CurrentOutlet::getOrFail()->name
                        : null),
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
                IconColumn::make('is_active')->label('Aktif')->boolean()->alignCenter()->sortable(),
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
                    self::soldOutOptions(),
                    ...ActiveStatusActions::make(
                        OptionGroup::class,
                        fn (OptionGroup $group, bool $active) => app(SetOptionGroupActive::class)->handle($group, $active),
                        'grup opsi',
                        'Grup tidak tampil di produk mana pun di aplikasi kasir dan tidak lagi wajib dipilih.',
                        'product.manage',
                    ),
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

    /**
     * Opsi habis di outlet aktif (pemilih sidebar) (ADR 0011 / Q43), mis. topping habis hari ini. Permission sama dengan
     * tanda habis produk; outlet lain tidak terpengaruh.
     */
    private static function soldOutOptions(): Action
    {
        return Action::make('soldOutOptions')
            ->label('Opsi habis')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->visible(fn (): bool => auth()->user()?->can('product.toggle_available') ?? false)
            ->modalHeading(fn (OptionGroup $record): string => 'Opsi habis · '.$record->name)
            ->modalDescription(fn (): string => 'Opsi yang dicentang tidak bisa dipilih di aplikasi kasir outlet '.CurrentOutlet::getOrFail()->name.' sampai centangnya dilepas.')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Simpan')
            ->fillForm(fn (OptionGroup $record): array => [
                'unavailable' => array_values(array_intersect(
                    OutletOption::unavailableIds(CurrentOutlet::getOrFail()->id),
                    $record->options->pluck('id')->all(),
                )),
            ])
            ->schema([
                CheckboxList::make('unavailable')->hiddenLabel()->validationAttribute('opsi')
                    ->options(fn (OptionGroup $record): array => $record->options->pluck('name', 'id')->all()),
            ])
            ->action(function (OptionGroup $record, array $data): void {
                $outlet = CurrentOutlet::getOrFail();
                $unavailable = array_map('strval', $data['unavailable'] ?? []);

                DB::transaction(function () use ($record, $outlet, $unavailable): void {
                    foreach ($record->options as $option) {
                        app(SetOptionAvailability::class)->handle($option, $outlet, ! in_array($option->id, $unavailable, true));
                    }
                });

                Notification::make()->success()->title('Ketersediaan opsi disimpan')->send();
            });
    }
}
