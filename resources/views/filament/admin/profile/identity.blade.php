{{-- Profil super admin → kartu kiri: inisial, nama, peran, email, status 2FA & info akun (baca saja). --}}
<div class="gs-profile-summary">
    <div class="gs-profile-identity">
        <span class="gs-profile-initial" aria-hidden="true">{{ $initial }}</span>
        <strong>{{ $name }}</strong>
        <span>Super admin</span>
        <span>{{ $email }}</span>
    </div>

    <h3 class="gs-profile-info-title">Info akun</h3>
    <dl class="gs-profile-info">
        <div>
            <dt>Status 2FA</dt>
            <dd>
                <x-filament::badge :color="$twoFactor ? 'success' : 'warning'" size="sm">{{ $twoFactor ? 'Aktif' : 'Belum aktif' }}</x-filament::badge>
            </dd>
        </div>
        @foreach ($rows as $row)
            <div>
                <dt>{{ $row['label'] }}</dt>
                <dd>{{ $row['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</div>
