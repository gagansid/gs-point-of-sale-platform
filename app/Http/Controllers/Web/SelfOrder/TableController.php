<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SelfOrder;

use App\Http\Controllers\Controller;
use App\Support\SelfOrderContext;
use Illuminate\View\View;

/** Halaman pesan dari meja (ADR 0014, Q70): menu, keranjang, dan status pesanan (komponen Livewire). */
final class TableController extends Controller
{
    public function show(): View
    {
        $context = SelfOrderContext::get();

        return view('self-order.table', [
            'context' => $context,
            'available' => self::availability($context),
        ]);
    }

    /** null = bisa memesan; selain itu alasan untuk ditampilkan. */
    public static function availability(SelfOrderContext $context): ?string
    {
        return match (true) {
            ! $context->setting->enabled => 'Pesan dari meja belum aktif di outlet ini. Silakan pesan di kasir.',
            $context->tenant->isReadOnly() => 'Pesan dari meja sedang tidak tersedia. Silakan pesan di kasir.',
            ! $context->setting->isOpenAt(now()->toImmutable(), $context->outlet->timezone) => 'Di luar jam layanan pesan dari meja ('
                .substr((string) $context->setting->opens_at, 0, 5).'–'.substr((string) $context->setting->closes_at, 0, 5).'). Silakan pesan di kasir.',
            default => null,
        };
    }
}
