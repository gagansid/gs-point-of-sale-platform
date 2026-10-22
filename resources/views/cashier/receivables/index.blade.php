{{-- /cashier/receivables: member yang punya kasbon & pelunasan (ADR 0023). Gaya halaman kasir Q130. --}}
@php($money = fn (string $v): string => \App\Support\Money::format($v))
<x-cashier.layout title="Kasbon" active="receivables">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <h1>Kasbon member</h1>
                <p>Pelunasan tunai langsung masuk kas shift yang terbuka di browser ini.</p>
            </div>
        </header>

        <div class="pos-stats">
            <div class="pos-stat">
                <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-users')</span>
                <div><small>Member berkasbon</small><strong>{{ number_format($stats['count'], 0, ',', '.') }}</strong></div>
            </div>
            <div class="pos-stat is-danger">
                <span class="pos-stat-icon" aria-hidden="true">@svg('heroicon-o-credit-card')</span>
                <div><small>Total kasbon</small><strong>{{ $money($stats['total']) }}</strong></div>
            </div>
        </div>

        @unless ($enabled)
            <div class="pos-callout" role="note">
                @svg('heroicon-o-information-circle', ['aria-hidden' => 'true'])
                <p>Kasbon belum diaktifkan di Pengaturan → Program member. Pelunasan kasbon lama tetap bisa dicatat.</p>
            </div>
        @endunless
        {{-- Error validasi field (nominal/metode); error bisnis "cashier" sudah tampil sebagai toast --}}
        @if ($errors->any() && ! $errors->has('cashier'))
            <div class="pos-callout is-danger" role="alert">
                @svg('heroicon-o-exclamation-triangle', ['aria-hidden' => 'true'])
                <p>{{ $errors->first() }}</p>
            </div>
        @endif

        <section class="pos-card">
            <div class="pos-card-head">
                <h2>Daftar kasbon</h2>
                <form method="GET" action="{{ route('cashier.receivables') }}" class="pos-search-form" role="search" novalidate>
                    <label class="pos-search-field">
                        @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
                        <input name="q" type="search" maxlength="50" value="{{ $search }}" placeholder="Cari nama, nomor HP, atau kode" aria-label="Cari member">
                    </label>
                    <button type="submit" class="site-btn site-btn-outline">Cari</button>
                </form>
            </div>

            @if ($members->isEmpty())
                <div class="pos-empty">
                    <span class="pos-empty-icon" aria-hidden="true">@svg($search !== '' ? 'heroicon-o-magnifying-glass' : 'heroicon-o-check-badge')</span>
                    <strong>{{ $search !== '' ? 'Member tidak ditemukan' : 'Tidak ada kasbon' }}</strong>
                    <p>{{ $search !== '' ? 'Tidak ada member berkasbon untuk "'.$search.'".' : 'Semua member sudah melunasi kasbonnya.' }}</p>
                    @if ($search !== '')
                        <a href="{{ route('cashier.receivables') }}" class="site-btn site-btn-outline">Tampilkan semua</a>
                    @endif
                </div>
            @else
                <div class="pos-table-wrap">
                    <table class="pos-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th class="is-secondary">Kode</th>
                                <th class="is-secondary">No. HP</th>
                                <th class="num">Kasbon</th>
                                <th class="num">Pelunasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($members as $member)
                                <tr>
                                    <td>{{ $member->name }}</td>
                                    <td class="is-code is-secondary">{{ $member->code }}</td>
                                    <td class="is-secondary">{{ $member->phone ? $member->maskedPhone() : '—' }}</td>
                                    <td class="num is-strong">{{ $money($member->receivable_balance) }}</td>
                                    <td class="is-actions">
                                        {{-- Nominal tampil "213.100", terkirim sebagai angka murni lewat hidden "amount" (data-money) --}}
                                        <form method="POST" action="{{ route('cashier.receivables.pay', $member->id) }}" class="pos-inline-pay" novalidate
                                            data-confirm-title="Catat pelunasan?" data-confirm-ok="Lunasi" data-confirm-icon="cash"
                                            data-confirm-text="Pelunasan kasbon {{ $member->name }} sebesar Rp{amount} akan dicatat.">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ \Illuminate\Support\Str::uuid7() }}">
                                            <span class="pos-money is-compact" data-money>
                                                <span class="pos-money-prefix">Rp</span>
                                                <input type="text" inputmode="numeric" autocomplete="off" placeholder="0" aria-label="Nominal pelunasan {{ $member->name }}">
                                                <input type="hidden" name="amount" value="{{ (int) $member->receivable_balance }}" data-money-raw>
                                            </span>
                                            <button type="button" class="site-btn site-btn-outline pos-btn-sm" data-money-fill="{{ (int) $member->receivable_balance }}" title="Isi nominal sebesar sisa kasbon">Penuh</button>
                                            <select name="payment_method_id" class="pos-select" aria-label="Metode bayar">
                                                @foreach ($methods as $method)
                                                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="site-btn site-btn-primary">Lunasi</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-cashier.layout>
