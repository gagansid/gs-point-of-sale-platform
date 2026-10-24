<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Halaman Profil (dashboard & admin): "Ulangi kata sandi baru" selalu tampil di samping "Kata sandi baru" (bawaan
 * Filament baru muncul setelah kata sandi diketik). Wajib hanya bila kata sandi baru diisi.
 */
trait ShowsPasswordConfirmation
{
    protected function getPasswordConfirmationFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordConfirmationFormComponent();

        return $component->label('Ulangi kata sandi baru')
            ->visible(true)
            ->required(fn (Get $get): bool => filled($get('password')));
    }
}
