<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Member;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export daftar member (ADR 0013, M7; izin member.manage). Dibaca per potongan (FromQuery); member yang dihapus
 * (anonim) tidak ikut. Teks dari pengguna lewat SafeCell (formula injection).
 *
 * @implements WithMapping<Member>
 */
final class MembersExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly string $timezone) {}

    /** @return Builder<Member> */
    public function query(): Builder
    {
        return Member::query()->whereNull('anonymized_at')->with(['tier:id,name', 'joinedOutlet:id,name'])->orderBy('created_at');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Kode', 'Nama', 'Nomor HP', 'Email', 'Tanggal lahir', 'Bulan lahir', 'Tier', 'Poin', 'Kunjungan',
            'Belanja 12 bulan', 'Total belanja', 'Terakhir datang', 'Daftar di', 'Bergabung', 'Status', 'Catatan'];
    }

    /**
     * @param  Member  $member
     * @return list<string|int|float|null>
     */
    public function map($member): array
    {
        $date = fn ($value): ?string => $value?->setTimezone($this->timezone)->format('Y-m-d H:i');

        return [
            $member->code,
            SafeCell::text($member->name),
            Phone::display($member->phone),
            SafeCell::text($member->email),
            $member->birth_day,
            $member->birth_month,
            $member->tier?->name,
            $member->points_balance,
            $member->visit_count,
            SafeCell::money(Money::fromDb($member->spend_12m)),
            SafeCell::money(Money::fromDb($member->total_spent)),
            $date($member->last_visit_at),
            $member->joinedOutlet->name ?? 'Dashboard',
            $date($member->created_at),
            $member->is_active ? 'Aktif' : 'Nonaktif',
            SafeCell::text($member->notes),
        ];
    }

    public function title(): string
    {
        return 'Member';
    }
}
