{{-- Profil → Perangkat & sesi: sesi dashboard (browser) & perangkat aplikasi (token API). --}}
<div class="pos-dash-table-wrap">
    <table class="pos-dash-table">
        <thead>
            <tr>
                <th>Perangkat</th>
                <th>Jenis</th>
                <th>Terakhir aktif</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sessions as $session)
                <tr>
                    <td>
                        {{ $session['agent'] }}
                        @if ($session['current'])
                            <span class="text-success-600 dark:text-success-400">· browser ini</span>
                        @endif
                        @if ($session['ip'])
                            <span class="text-gray-500">· {{ $session['ip'] }}</span>
                        @endif
                    </td>
                    <td>Dashboard</td>
                    <td class="whitespace-nowrap">{{ $session['last'] }}</td>
                </tr>
            @endforeach
            @foreach ($tokens as $token)
                <tr>
                    <td>{{ $token['name'] }}</td>
                    <td>Aplikasi</td>
                    <td class="whitespace-nowrap">{{ $token['last'] }}</td>
                </tr>
            @endforeach
            @if ($sessions === [] && $tokens === [])
                <tr><td colspan="3" class="text-center text-gray-500">Tidak ada sesi aktif lain</td></tr>
            @endif
        </tbody>
    </table>
</div>
