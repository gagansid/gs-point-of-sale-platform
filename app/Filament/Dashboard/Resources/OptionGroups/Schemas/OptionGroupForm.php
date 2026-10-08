<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\OptionGroups\Schemas;

use App\Filament\Shared\Forms\MoneyInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form grup opsi. Opsi memakai Repeater biasa (bukan relationship) agar penyimpanan tetap
 * lewat Action SaveOptionGroup; id opsi lama dibawa di field tersembunyi.
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
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->label('Nama grup')->placeholder('Ukuran')->required()->maxLength(100)->autofocus(),
                        TextInput::make('min_select')
                            ->label('Minimal pilihan')
                            ->helperText('0 = boleh tidak dipilih, 1 = wajib pilih')
                            ->integer()->minValue(0)->maxValue(20)->default(0)->required(),
                        TextInput::make('max_select')
                            ->label('Maksimal pilihan')
                            ->integer()->minValue(1)->maxValue(20)->default(1)->required()
                            ->gte('min_select'),
                    ]),

                Section::make('Opsi')
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->label('Nama opsi')->placeholder('Large')->required()->maxLength(100)->distinct(),
                                MoneyInput::make('price_delta')->label('Tambahan harga')->default(0),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->maxItems(50)
                            ->defaultItems(1)
                            ->reorderable()
                            ->addActionLabel('Tambah opsi'),
                    ]),
            ]);
    }
}
