{{-- Langkah 2: database (MySQL 8 / MariaDB 10.6+, wajib kosong) --}}
<x-install.layout title="Database" :step="2" subtitle="Buat database & user di cPanel → MySQL Databases, lalu isi di sini. Database harus kosong.">
    @error('connection')
        <div class="site-alert site-alert-warning" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('install.database.save') }}" class="site-form" novalidate>
        @csrf
        <div class="site-form-grid install-grid-host">
            <x-site.field name="host" label="Host" required :value="old('host', $saved['host'] ?? 'localhost')" />
            <x-site.field name="port" label="Port" type="number" required :value="old('port', $saved['port'] ?? 3306)" />
        </div>
        <x-site.field name="database" label="Nama database" required placeholder="user_gspos" :value="old('database', $saved['database'] ?? '')" />
        <div class="site-form-grid">
            <x-site.field name="username" label="User database" required autocomplete="off" placeholder="user_gspos" :value="old('username', $saved['username'] ?? '')" />
            <x-site.field name="password" label="Kata sandi database" type="password" autocomplete="new-password"
                :placeholder="isset($saved['password']) ? '•••••• tersimpan' : ''" />
        </div>

        <div class="install-actions">
            <a href="{{ route('install.requirements') }}" class="site-btn site-btn-ghost">Kembali</a>
            <span class="install-actions-end">
                <button type="submit" name="action" value="test" class="site-btn site-btn-ghost">Tes koneksi</button>
                <button type="submit" name="action" value="next" class="site-btn site-btn-primary">Lanjut</button>
            </span>
        </div>
    </form>
</x-install.layout>
