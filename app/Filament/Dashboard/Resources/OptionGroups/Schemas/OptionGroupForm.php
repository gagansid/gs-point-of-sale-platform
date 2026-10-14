<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Schemas;

use App\Enums\ProductKind;
use App\Filament\Shared\Forms\MoneyInput;
use App\Filament\Shared\Schemas\AuditInfo;
use App\Models\Product;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Form grup opsi. Opsi memakai Repeater biasa (bukan relationship) agar penyimpanan tetap
 * lewat Action SaveOptionGroup; id opsi lama dibawa di field tersembunyi.
 *
 * Opsi tampil sebagai tabel ringkas satu baris per opsi: ↑↓ · Nama opsi · Tambahan harga · hapus,
 * dengan tombol "Tambah opsi" di kanan atas card (bukan di bawah daftar).
 */
final class OptionGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Grup opsi')
                    ->description('Contoh: Ukuran (wajib pilih 1), Gula (pilih 1), Topping (boleh lebih dari 1)')
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        TextInput::make('name')->label('Nama grup')->placeholder('Ukuran')->required()->maxLength(100)->autofocus()
                            ->columnSpan(['md' => 2]),
                        TextInput::make('min_select')
                            ->label('Minimal pilihan')
                            ->helperText('0 = boleh tidak dipilih, 1 = wajib pilih')
                            ->integer()->minValue(0)->maxValue(20)->default(0)->required(),
                        TextInput::make('max_select')
                            ->label('Maksimal pilihan')
                            ->integer()->minValue(1)->maxValue(20)->default(1)->required()
                            ->gte('min_select'),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Grup nonaktif tidak tampil di aplikasi kasir dan tidak wajib dipilih')
                            ->columnSpanFull(),
                    ]),

                Section::make('Opsi')
                    ->key('optionsSection')
                    ->description('Urutan baris = urutan tampil di aplikasi kasir')
                    ->headerActions([
                        Action::make('addOption')
                            ->label('Tambah opsi')
                            ->icon(Heroicon::OutlinedPlus)
                            ->size('sm')
                            ->disabled(fn (Get $get): bool => count($get('options') ?? []) >= 50)
                            // Tambah baris kosong di akhir; kunci acak sama seperti tombol tambah Repeater
                            ->action(fn (Get $get, Set $set) => $set('options', [
                                ...($get('options') ?? []),
                                (string) Str::uuid() => ['id' => null, 'name' => null, 'price_delta' => '0', 'component_id' => null, 'component_qty' => null],
                            ])),
                    ])
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Nama opsi')->markAsRequired(),
                                TableColumn::make('Tambahan harga')->width('180px'),
                                // ADR 0020: bahan yang dipakai opsi, mis. Extra shot = 18 gram kopi
                                TableColumn::make('Bahan (opsional)')->width('220px'),
                                TableColumn::make('Jumlah')->width('110px'),
                            ])
                            ->compact()
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->hiddenLabel()->placeholder('Large')->required()->maxLength(100)->distinct(),
                                MoneyInput::make('price_delta')->hiddenLabel()->default(0)->rule('max:'.Money::MAX_PRICE),
                                Select::make('component_id')->hiddenLabel()->placeholder('Tanpa bahan')->searchable()
                                    ->options(fn (): array => Product::query()->where('kind', '!=', ProductKind::Bundle->value)->orderBy('name')
                                        ->get(['id', 'name', 'unit'])->mapWithKeys(fn (Product $p): array => [$p->id => $p->name.' ('.$p->unit.')'])->all()),
                                TextInput::make('component_qty')->hiddenLabel()->integer()->minValue(1)->maxValue(1000000)->placeholder('18')
                                    ->requiredWith('component_id'),
                            ])
                            ->minItems(1)
                            ->maxItems(50)
                            ->defaultItems(1)
                            // Hanya tombol ↑↓ (tanpa seret) agar baris tabel tetap ringkas
                            ->reorderableWithButtons()
                            ->reorderableWithDragAndDrop(false)
                            ->addable(false)
                            ->deleteAction(fn (Action $action): Action => $action->tooltip('Hapus opsi')),
                    ]),
                // Tanggal dibuat & diubah (audit)
                AuditInfo::make(),
            ]);
    }
}
