<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fitur baru yang bisa dibatasi paket (ADR 0025 A2). Sebelumnya fitur ini tersedia untuk semua paket, jadi paket yang
 * sudah ada diberi fitur tersebut agar tidak ada tenant yang tiba-tiba kehilangan akses; admin melepas centang per
 * paket. Pembayaran online dulu bagian dari self-order: hanya paket ber-self_order yang mendapat online_payment.
 */
return new class extends Migration
{
    private const KEYS = ['advanced_reports', 'report_export', 'web_cashier', 'online_payment'];

    public function up(): void
    {
        $now = now();
        $rows = [
            ['advanced_reports', 'Laporan lanjutan', 'Laporan laba kotor, opsi, karyawan, waktu (jam & heatmap), dan perbandingan outlet', 70],
            ['report_export', 'Export laporan', 'Unduh laporan dan daftar member ke Excel/PDF (PDF rekap shift selalu tersedia)', 80],
            ['web_cashier', 'Kasir web', 'Kasir lewat browser di app.gspos.id/cashier (aplikasi kasir Android selalu tersedia)', 90],
            ['online_payment', 'Pembayaran online', 'Pelanggan membayar pesanan meja lewat Midtrans dari HP', 100],
        ];

        DB::table('features')->insertOrIgnore(array_map(fn (array $r): array => [
            'id' => (string) Str::uuid7(), 'key' => $r[0], 'name' => $r[1], 'description' => $r[2],
            'sort_order' => $r[3], 'created_at' => $now, 'updated_at' => $now,
        ], $rows));

        DB::table('plans')->orderBy('id')->each(function (object $plan): void {
            /** @var list<string> $features */
            $features = json_decode((string) $plan->features, true) ?: [];
            $added = ['advanced_reports', 'report_export', 'web_cashier'];
            if (in_array('self_order', $features, true)) {
                $added[] = 'online_payment';
            }

            DB::table('plans')->where('id', $plan->id)
                ->update(['features' => json_encode(array_values(array_unique([...$features, ...$added])))]);
        });
    }

    public function down(): void
    {
        DB::table('plans')->orderBy('id')->each(function (object $plan): void {
            $features = json_decode((string) $plan->features, true) ?: [];
            DB::table('plans')->where('id', $plan->id)
                ->update(['features' => json_encode(array_values(array_diff($features, self::KEYS)))]);
        });

        DB::table('features')->whereIn('key', self::KEYS)->delete();
    }
};
