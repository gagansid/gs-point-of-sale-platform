{{--
    Banner status langganan di panel pelanggan (ADR 0009):
    - hanya-baca (trial/langganan habis): peringatan, perubahan data dinonaktifkan
    - trial berjalan: sisa hari
--}}
@props(['tenant'])

@php
    use App\Models\Tenant;

    /** @var Tenant|null $tenant */
    $daysLeft = $tenant?->trialDaysLeft();
@endphp

@if ($tenant?->isReadOnly())
    <div class="gs-banner gs-banner-warning" role="alert">
        <x-filament::icon icon="heroicon-o-lock-closed" class="gs-banner-icon" />
        <div>
            <strong>Masa {{ $tenant->status === \App\Enums\TenantStatus::Trial ? 'trial' : 'langganan' }} berakhir {{ $tenant->subscription_ends_at?->translatedFormat('j F Y') }}.</strong>
            Data tetap aman dan bisa dilihat, tetapi transaksi & perubahan data dinonaktifkan sampai berlangganan.
        </div>
        <a class="gs-banner-action" href="{{ route('landing') }}#kontak" target="_blank" rel="noopener">Hubungi tim gs.POS</a>
    </div>
@elseif ($daysLeft !== null)
    <div class="gs-banner gs-banner-info" role="status">
        <x-filament::icon icon="heroicon-o-clock" class="gs-banner-icon" />
        <div>
            <strong>Trial tersisa {{ $daysLeft }} hari</strong> (sampai {{ $tenant->subscription_ends_at?->translatedFormat('j F Y') }}).
            Setelah itu data hanya bisa dilihat sampai Anda berlangganan.
        </div>
        <a class="gs-banner-action" href="{{ route('landing') }}#kontak" target="_blank" rel="noopener">Berlangganan</a>
    </div>
@endif
