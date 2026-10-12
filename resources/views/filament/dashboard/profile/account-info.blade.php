{{-- Profil → kartu kiri: nama, role · bisnis, email, lalu info akun (baca saja). --}}
<div class="gs-profile-summary">
    <div class="gs-profile-identity">
        <strong>{{ $name }}</strong>
        <span>{{ $role }}@if (filled($tenant)) · {{ $tenant }}@endif</span>
        @if (filled($email))
            <span>{{ $email }}</span>
        @endif
    </div>

    <h3 class="gs-profile-info-title">Info akun</h3>
    <dl class="gs-profile-info">
        @foreach ($rows as $row)
            <div>
                <dt>{{ $row['label'] }}</dt>
                <dd>{{ $row['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</div>
