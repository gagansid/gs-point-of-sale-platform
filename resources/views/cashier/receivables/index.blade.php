{{-- /cashier/receivables: member yang punya kasbon & pelunasan (ADR 0023). --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
<x-cashier.layout title="Kasbon" active="receivables">
    <section class="cashier-card">
        <div class="cashier-card-head"><h1>Kasbon member</h1></div>
        @unless ($enabled)
            <p class="cashier-muted">Kasbon belum diaktifkan di Pengaturan → Program member. Pelunasan kasbon lama tetap bisa dicatat.</p>
        @endunless
        <form method="GET" action="{{ route('cashier.receivables') }}" class="site-form cashier-inline-search" novalidate>
            <div class="site-field">
                <label for="q">Cari member</label>
                <input id="q" name="q" type="search" maxlength="50" value="{{ $search }}" placeholder="Nama, nomor HP, atau kode">
            </div>
            <button type="submit" class="site-btn site-btn-outline">Cari</button>
        </form>

        @if ($members->isEmpty())
            <p class="cashier-muted">Tidak ada member dengan kasbon{{ $search !== '' ? ' untuk "'.$search.'"' : '' }}.</p>
        @else
            <table class="cashier-table">
                <thead><tr><th>Member</th><th class="num">Kasbon</th><th>Pelunasan</th></tr></thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td>{{ $member->name }}<br><small class="cashier-muted">{{ $member->code }}</small></td>
                            <td class="num">{{ $money($member->receivable_balance) }}</td>
                            <td>
                                <form method="POST" action="{{ route('cashier.receivables.pay', $member->id) }}" class="cashier-inline-pay" novalidate>
                                    @csrf
                                    <input type="hidden" name="id" value="{{ \Illuminate\Support\Str::uuid7() }}">
                                    <input type="number" name="amount" min="1" step="any" inputmode="decimal" value="{{ (int) $member->receivable_balance }}" class="cashier-qty" aria-label="Nominal pelunasan {{ $member->name }}" required>
                                    <select name="payment_method_id" class="cashier-select" aria-label="Metode bayar">
                                        @foreach ($methods as $method)
                                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="site-btn site-btn-outline" data-confirm-step="Klik lagi">Lunasi</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-cashier.layout>
