<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\Outlet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Nomor dokumen stok per outlet per hari lokal (ADR 0015): {kode outlet}-{SM|SO|TR}-{YYMMDD}-{urut 4 digit},
 * mis. JKT01-SM-261012-0003. Baris document_sequences dikunci seperti nomor order; wajib di dalam transaksi.
 */
final class DocumentNumberGenerator
{
    public const RECEIPT = 'SM';

    public const OPNAME = 'SO';

    public const TRANSFER = 'TR';

    public function next(Outlet $outlet, string $type, ?CarbonInterface $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumberGenerator harus dipanggil di dalam transaksi DB');
        }

        $date = $outlet->localDate($at);
        $key = ['outlet_id' => $outlet->id, 'type' => $type, 'date' => $date];

        DB::table('document_sequences')->insertOrIgnore([...$key, 'last_number' => 0]);
        $next = (int) DB::table('document_sequences')->where($key)->lockForUpdate()->value('last_number') + 1;
        DB::table('document_sequences')->where($key)->update(['last_number' => $next]);

        return sprintf('%s-%s-%s-%s', $outlet->code, $type, str_replace('-', '', substr($date, 2)), str_pad((string) $next, 4, '0', STR_PAD_LEFT));
    }
}
