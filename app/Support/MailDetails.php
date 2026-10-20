<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Kotak rincian berlabel untuk email (MailMessage::line menggabungkan baris baru, sehingga rincian dibuat tabel HTML).
 * Semua nilai di-escape; gaya di theme email (`.gs-details`).
 */
final class MailDetails
{
    /** @param  array<string, string|null>  $rows  label → nilai (null dilewati); $total = label baris yang ditebalkan */
    public static function table(array $rows, ?string $total = null): HtmlString
    {
        $html = '';
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $html .= '<tr'.($label === $total ? ' class="is-total"' : '').'><td class="k">'.e($label).'</td><td class="v">'.e($value).'</td></tr>';
        }

        return new HtmlString('<table class="gs-details" role="presentation" width="100%" cellpadding="0" cellspacing="0">'.$html.'</table>');
    }

    /** Kotak catatan menonjol (mis. alasan penolakan). */
    public static function note(string $label, string $text): HtmlString
    {
        return new HtmlString('<div class="gs-note"><strong>'.e($label).'</strong><br>'.nl2br(e($text)).'</div>');
    }
}
