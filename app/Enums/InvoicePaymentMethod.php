<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Metode bayar tagihan langganan pilihan owner (Q123). QRIS & VA lewat Snap Midtrans vendor (terverifikasi otomatis
 * lewat webhook); transfer manual ke rekening gs.POS + unggah bukti, diverifikasi admin.
 */
enum InvoicePaymentMethod: string implements HasDescription, HasIcon, HasLabel
{
    case Qris = 'qris';
    case VirtualAccount = 'va';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Qris => 'QRIS',
            self::VirtualAccount => 'Transfer bank (VA)',
            self::Manual => 'Transfer manual',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Qris => 'Scan dengan aplikasi bank atau e-wallet. Lunas otomatis',
            self::VirtualAccount => 'Nomor virtual account BCA, BNI, BRI, Mandiri, Permata. Lunas otomatis',
            self::Manual => 'Transfer ke rekening gs.POS lalu unggah bukti. Diverifikasi tim kami maks. 1×24 jam',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Qris => Heroicon::OutlinedQrCode,
            self::VirtualAccount => Heroicon::OutlinedBuildingLibrary,
            self::Manual => Heroicon::OutlinedArrowUpTray,
        };
    }

    /** Lewat Snap Midtrans (butuh key vendor). */
    public function isOnline(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Kanal Snap yang ditampilkan untuk metode ini.
     *
     * @return list<string>
     */
    public function snapChannels(): array
    {
        return match ($this) {
            self::Qris => ['other_qris', 'gopay', 'shopeepay'],
            self::VirtualAccount => ['bca_va', 'bni_va', 'bri_va', 'echannel', 'permata_va', 'cimb_va', 'other_va'],
            self::Manual => [],
        };
    }
}
