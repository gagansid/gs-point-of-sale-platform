<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Enums\ReceivableEntryType;
use App\Models\Member;
use App\Models\ReceivableEntry;
use App\Support\Money;

/**
 * Ledger kasbon member (ADR 0023): saldo `members.receivable_balance` + baris `receivable_entries`. Pemanggil wajib di
 * dalam transaksi dan memakai lock() agar saldo tidak tertimpa transaksi bersamaan.
 */
final class ReceivableLedger
{
    public function lock(Member $member): Member
    {
        return Member::query()->lockForUpdate()->findOrFail($member->id);
    }

    /**
     * @param  string  $amount  bertanda: + menambah kasbon, − mengurangi
     * @param  array{outlet_id?: string|null, order_id?: string|null, payment_id?: string|null, payment_method_id?: string|null, cash_movement_id?: string|null, user_id?: string|null, note?: string|null}  $attributes
     */
    public function record(Member $member, ReceivableEntryType $type, string $amount, array $attributes = [], ?string $id = null): ReceivableEntry
    {
        $member->forceFill(['receivable_balance' => Money::add($member->receivable_balance, $amount)])->save();

        $entry = new ReceivableEntry([...$attributes, 'member_id' => $member->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $member->receivable_balance]);
        if ($id !== null) {
            $entry->id = $id;
        }
        $entry->save();

        return $entry;
    }
}
