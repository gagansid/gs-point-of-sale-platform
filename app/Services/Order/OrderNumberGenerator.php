<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Outlet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Nomor order harian: {kode outlet}-{YYMMDD}-{urut 4 digit}, mis. JKT01-261008-0042.
 *
 * Tanggal = tanggal LOKAL outlet (ADR 0001). Baris order_sequences dikunci (SELECT ... FOR UPDATE)
 * sehingga dua kasir tidak pernah mendapat nomor sama. Wajib dipanggil di dalam transaksi
 * (pengecualian standar: Service ini memang menulis DB).
 */
final class OrderNumberGenerator
{
    public function next(Outlet $outlet, ?CarbonInterface $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OrderNumberGenerator harus dipanggil di dalam transaksi DB');
        }

        $date = $outlet->localDate($at);
        $key = ['outlet_id' => $outlet->id, 'date' => $date];

        // Baris hari ini dibuat sekali; insertOrIgnore aman bila dua request membuatnya bersamaan
        DB::table('order_sequences')->insertOrIgnore([...$key, 'last_number' => 0]);

        $current = (int) DB::table('order_sequences')->where($key)->lockForUpdate()->value('last_number');
        $next = $current + 1;
        DB::table('order_sequences')->where($key)->update(['last_number' => $next]);

        return sprintf('%s-%s-%s', $outlet->code, str_replace('-', '', substr($date, 2)), str_pad((string) $next, 4, '0', STR_PAD_LEFT));
    }
}
