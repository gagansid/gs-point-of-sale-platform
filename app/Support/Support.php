<?php

declare(strict_types=1);

namespace App\Support;

/** Kontak bantuan tim gs.POS untuk menu akun dashboard (config pos.support). */
final class Support
{
    /** WhatsApp bila diatur (pesan awal berisi nama bisnis), selain itu email. */
    public static function helpUrl(?string $businessName = null): string
    {
        $wa = preg_replace('/\D/', '', (string) config('pos.support.whatsapp'));

        if ($wa !== null && $wa !== '') {
            return 'https://wa.me/'.$wa.'?text='.rawurlencode('Halo tim gs.POS, saya butuh bantuan'.($businessName !== null ? ' untuk '.$businessName : '').'.');
        }

        return 'mailto:'.config('pos.support.email').'?subject='.rawurlencode('Bantuan gs.POS');
    }
}
