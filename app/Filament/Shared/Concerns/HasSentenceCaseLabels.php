<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Illuminate\Support\Str;

/**
 * Label resource berkata ganda ditulis seperti menu (huruf besar hanya di awal): "Daftar Calon pelanggan" →
 * judul/breadcrumb "Calon pelanggan", bukan "Calon Pelanggan" (bawaan Filament: Title Case tiap kata).
 */
trait HasSentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
