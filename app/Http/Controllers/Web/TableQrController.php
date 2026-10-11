<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\User;
use App\Support\QrSvg;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman cetak QR meja (ADR 0014, Q69; izin table.manage): satu kartu per meja aktif (outlet yang boleh diakses,
 * OutletScope), atau meja tertentu lewat `?ids=`. Dicetak dari browser (seperti cetak struk), tanpa file PDF.
 */
final class TableQrController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('table.manage'), 403);

        $ids = array_filter(explode(',', (string) $request->query('ids', '')));
        $tables = DiningTable::query()->with('outlet:id,name')->where('is_active', true)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('outlet_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('self-order.qr-print', [
            'business' => $user->tenant->name,
            'cards' => $tables->map(fn (DiningTable $t): array => [
                'name' => $t->name,
                'area' => $t->area,
                'outlet' => $t->outlet->name ?? '',
                'url' => $t->orderUrl(),
                'svg' => QrSvg::make($t->orderUrl()),
            ])->all(),
        ]);
    }
}
