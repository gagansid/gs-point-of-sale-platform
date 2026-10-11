<?php

declare(strict_types=1);

namespace App\Services\Member;

use App\Actions\Order\Data\ResolvedLine;
use App\Enums\ErrorCode;
use App\Enums\StampEntryType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberStamp;
use App\Models\Order;
use App\Models\Product;
use App\Models\StampProgram;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Kartu stempel member (ADR 0013, Q65). Saldo = Σ `change` mutasi per member & program (append-only); penulisan
 * (redeem/earn/reverse) dipanggil DI DALAM transaksi order dengan baris member terkunci (PointLedger::lock).
 *
 * - Gratis: per program yang dipilih, 1 item termurah yang memenuhi (harga satuan setelah diskon item) per order.
 * - Didapat: saat order selesai, 1 item yang memenuhi = 1 stempel per program; item yang digratiskan tidak dihitung.
 * - Void: stempel didapat ditarik, stempel yang ditukar dikembalikan.
 */
final class StampCards
{
    /** Maks. program yang bisa ditukar dalam satu order. */
    public const MAX_REDEMPTIONS = 5;

    /** @return Collection<int, StampProgram> program aktif tenant, relasi items dimuat */
    public function activePrograms(): Collection
    {
        return StampProgram::query()->with('items')->where('is_active', true)->orderBy('name')->get();
    }

    /** @return array<string, int> stamp_program_id => saldo */
    public function balances(Member $member): array
    {
        return MemberStamp::query()->where('member_id', $member->id)
            ->groupBy('stamp_program_id')->selectRaw('stamp_program_id, SUM(`change`) AS balance')
            ->pluck('balance', 'stamp_program_id')->map(fn (mixed $b): int => (int) $b)->all();
    }

    /**
     * Ringkasan kartu stempel aktif untuk kasir, API, dan halaman member.
     *
     * @return list<array{id: string, name: string, stamps_required: int, balance: int, rewards_available: int, product_ids: list<string>, category_ids: list<string>}>
     */
    public function summary(Member $member): array
    {
        $balances = $this->balances($member);

        return $this->activePrograms()->map(fn (StampProgram $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'stamps_required' => $p->stamps_required,
            'balance' => $balances[$p->id] ?? 0,
            'rewards_available' => intdiv(max(0, $balances[$p->id] ?? 0), max(1, $p->stamps_required)),
            'product_ids' => $p->productIds(),
            'category_ids' => $p->categoryIds(),
        ])->values()->all();
    }

    /**
     * Nominal item gratis untuk program yang dipilih kasir. Satu unit tidak bisa digratiskan dua program.
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<string>  $programIds
     * @return array{amount: string, redemptions: list<array{stamp_program_id: string, name: string, product_id: string, product_name: string, amount: string}>}
     *
     * @throws BusinessException VALIDATION_ERROR (field stamp_program_ids)
     */
    public function freeItems(Member $member, array $lines, array $programIds): array
    {
        $programIds = array_values(array_unique($programIds));
        if ($programIds === []) {
            return ['amount' => '0.00', 'redemptions' => []];
        }

        if (count($programIds) > self::MAX_REDEMPTIONS) {
            self::fail('Maksimal '.self::MAX_REDEMPTIONS.' kartu stempel per transaksi');
        }

        $programs = $this->activePrograms()->keyBy('id');
        $balances = $this->balances($member);
        // Sisa unit per baris yang belum digratiskan
        $remaining = array_map(fn (ResolvedLine $l): int => $l->qty, $lines);
        $amount = '0.00';
        $redemptions = [];

        foreach ($programIds as $programId) {
            $program = $programs->get($programId) ?? self::fail('Kartu stempel tidak ditemukan atau tidak aktif');

            if (($balances[$program->id] ?? 0) < $program->stamps_required) {
                self::fail("Stempel {$program->name} belum cukup ({$program->stamps_required} stempel untuk 1 gratis)");
            }

            $best = null;
            foreach ($lines as $index => $line) {
                if ($remaining[$index] <= 0 || ! $program->covers($line->product->id, $line->product->category_id)) {
                    continue;
                }

                $unit = self::unitPrice($line);
                if ($best === null || Money::compare($unit, $best['amount']) < 0) {
                    $best = ['index' => $index, 'amount' => $unit];
                }
            }

            if ($best === null) {
                self::fail("Tidak ada item {$program->name} di keranjang untuk digratiskan");
            }

            $remaining[$best['index']]--;
            $line = $lines[$best['index']];
            $amount = Money::add($amount, $best['amount']);
            $redemptions[] = [
                'stamp_program_id' => $program->id,
                'name' => $program->name,
                'product_id' => $line->product->id,
                'product_name' => $line->product->name,
                'amount' => $best['amount'],
            ];
        }

        return ['amount' => $amount, 'redemptions' => $redemptions];
    }

    /**
     * Potong stempel untuk gratis yang tercatat di order (saat order selesai). Saldo diperiksa ulang di bawah kunci
     * member: order bersamaan tidak bisa memakai stempel yang sama dua kali.
     *
     * @throws BusinessException
     */
    public function redeem(Member $member, Order $order): void
    {
        if (MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::Redeem)->exists()) {
            return;
        }

        $balances = $this->balances($member);

        foreach ($order->stamp_redemptions ?? [] as $redemption) {
            $program = StampProgram::query()->find($redemption['stamp_program_id'])
                ?? self::fail('Kartu stempel tidak ditemukan');
            $balance = $balances[$program->id] ?? 0;

            if ($balance < $program->stamps_required) {
                self::fail("Stempel {$program->name} belum cukup ({$program->stamps_required} stempel untuk 1 gratis)");
            }

            $balances[$program->id] = $balance - $program->stamps_required;
            $this->write($member, $program->id, StampEntryType::Redeem, -$program->stamps_required, $balances[$program->id], $order, 'Gratis '.$redemption['product_name']);
        }
    }

    /** Stempel didapat dari order selesai; dikembalikan jumlah totalnya. Idempotent per order. */
    public function earn(Member $member, Order $order): int
    {
        if (MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::Earn)->exists()) {
            return 0;
        }

        $items = $order->items()->get(['product_id', 'qty']);
        $categories = Product::query()->withTrashed()->whereIn('id', $items->pluck('product_id'))->pluck('category_id', 'id');
        $freeUnits = collect($order->stamp_redemptions ?? [])->countBy('stamp_program_id');
        $balances = $this->balances($member);
        $total = 0;

        foreach ($this->activePrograms() as $program) {
            $qty = $items->filter(fn ($item): bool => $program->covers($item->product_id, $categories[$item->product_id] ?? null))->sum('qty');
            // Item yang digratiskan program ini tidak menghasilkan stempel
            $earned = max(0, (int) $qty - (int) ($freeUnits[$program->id] ?? 0));

            if ($earned > 0) {
                $balances[$program->id] = ($balances[$program->id] ?? 0) + $earned;
                $this->write($member, $program->id, StampEntryType::Earn, $earned, $balances[$program->id], $order);
                $total += $earned;
            }
        }

        return $total;
    }

    /** Void: stempel didapat ditarik (saldo boleh minus), stempel yang ditukar dikembalikan. Idempotent per order. */
    public function reverse(Member $member, Order $order): void
    {
        if (MemberStamp::query()->where('order_id', $order->id)->where('type', StampEntryType::VoidReversal)->exists()) {
            return;
        }

        $entries = MemberStamp::query()->where('order_id', $order->id)->whereIn('type', [StampEntryType::Earn, StampEntryType::Redeem])->get();
        $balances = $this->balances($member);

        foreach ($entries as $entry) {
            $balances[$entry->stamp_program_id] = ($balances[$entry->stamp_program_id] ?? 0) - $entry->change;
            $this->write($member, $entry->stamp_program_id, StampEntryType::VoidReversal, -$entry->change, $balances[$entry->stamp_program_id], $order, 'Order di-void');
        }
    }

    private function write(Member $member, string $programId, StampEntryType $type, int $change, int $balanceAfter, Order $order, ?string $reason = null): void
    {
        MemberStamp::query()->create([
            'member_id' => $member->id,
            'stamp_program_id' => $programId,
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
            'type' => $type,
            'change' => $change,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
        ]);
    }

    /** Harga satu unit setelah diskon item (diskon baris dibagi rata per unit). */
    private static function unitPrice(ResolvedLine $line): string
    {
        $gross = Money::add($line->product->price, $line->optionsTotal);

        return Money::max(Money::sub($gross, Money::div($line->discount, $line->qty)), '0.00');
    }

    /** @throws BusinessException */
    private static function fail(string $message): never
    {
        throw BusinessException::of(ErrorCode::ValidationError, $message, ['stamp_program_ids' => [$message]]);
    }
}
