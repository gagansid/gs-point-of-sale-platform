<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tahap pengantaran pesanan meja yang sudah lunas (ADR 0027 §4). Hanya maju; Diantar & Selesai otomatis = akhir.
 */
enum FulfillmentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case AutoCompleted = 'auto_completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Antre',
            self::Preparing => 'Disiapkan',
            self::Ready => 'Siap diantar',
            self::Served => 'Diantar',
            self::AutoCompleted => 'Selesai otomatis',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Preparing, self::Ready => 'info',
            self::Served => 'success',
            self::AutoCompleted => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-inbox-arrow-down',
            self::Preparing => 'heroicon-o-fire',
            self::Ready => 'heroicon-o-bell-alert',
            self::Served => 'heroicon-o-check-circle',
            self::AutoCompleted => 'heroicon-o-clock',
        };
    }

    /** Urutan tahap; Selesai otomatis setara Diantar (sama-sama akhir). */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Preparing => 1,
            self::Ready => 2,
            self::Served, self::AutoCompleted => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() === 3;
    }

    /** Langkah berikutnya untuk tombol staf (null = sudah akhir). */
    public function next(): ?self
    {
        return match ($this) {
            self::Queued => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Served,
            self::Served, self::AutoCompleted => null,
        };
    }

    /** Label tombol menuju tahap ini. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Preparing => 'Siapkan',
            self::Ready => 'Siap',
            self::Served => 'Diantar',
            default => $this->getLabel(),
        };
    }

    /**
     * Tahap yang masih berjalan (belum diantar).
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Queued, self::Preparing, self::Ready];
    }
}
