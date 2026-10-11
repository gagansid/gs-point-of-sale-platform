<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\ActivityLog;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Hapus member atas permintaan (UU PDP, ADR 0013): data pribadi dikosongkan & dinonaktifkan; transaksi, snapshot nama
 * di order, dan ledger tetap untuk laporan/audit. Nilai pribadi lama di Riwayat perubahan ikut dihapus.
 */
final class AnonymizeMember
{
    public function handle(Member $member): Member
    {
        if ($member->isAnonymized()) {
            return $member;
        }

        DB::transaction(function () use ($member): void {
            // Tanpa audit log untuk nilai anonim; riwayat lama dibersihkan dari nilai pribadi
            activity()->withoutLogs(fn () => $member->forceFill([
                'name' => 'Member dihapus',
                'phone' => null,
                'email' => null,
                'birth_day' => null,
                'birth_month' => null,
                'notes' => null,
                'password' => null,
                'pin' => null,
                'is_active' => false,
                'anonymized_at' => now(),
            ])->save());

            ActivityLog::query()->where('subject_type', Member::class)->where('subject_id', $member->id)
                ->update(['properties' => json_encode(['anonymized' => true])]);

            activity('Member')->performedOn($member)->event('deleted')->log('anonymized');
        });

        return $member;
    }
}
