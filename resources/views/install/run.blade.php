{{-- Langkah 5: ringkasan + proses instal per langkah (AJAX), lanjut setelah refresh. --}}
<x-install.layout title="Instal" :step="5" subtitle="Periksa ringkasan, lalu mulai. Jangan tutup halaman ini sampai selesai.">
    <dl class="install-summary">
        <div><dt>Database</dt><dd>{{ $data->database['username'].'@'.$data->database['host'].':'.$data->database['port'].'/'.$data->database['database'] }}</dd></div>
        <div><dt>URL</dt><dd>{{ $data->appUrl }}</dd></div>
        <div><dt>Edisi</dt><dd>{{ $data->edition === 'self_hosted' ? 'Jual putus (satu bisnis)' : 'SaaS' }}</dd></div>
        <div><dt>Domain</dt><dd>{{ $data->usesSubdomains() ? $data->appDomain.' · '.$data->adminDomain.' · '.$data->apiDomain : 'Satu domain (/dashboard, /admin, /api/v1)' }}</dd></div>
        <div><dt>Email</dt><dd>{{ $data->mailHost ?? 'Belum diatur (dicatat di log)' }}</dd></div>
        <div><dt>Super admin</dt><dd>{{ $data->adminEmail }}</dd></div>
        <div><dt>Bisnis</dt><dd>{{ $data->createsBusiness() ? $data->businessName.' · owner '.$data->ownerEmail : 'Tidak dibuat sekarang' }}</dd></div>
        @if ($data->createsManager())
            <div><dt>Manager</dt><dd>{{ $data->managerEmail }}</dd></div>
        @endif
    </dl>

    <ol class="install-progress" id="install-progress">
        @foreach ($steps as $key => $label)
            <li data-step="{{ $key }}" @class(['is-done' => in_array($key, $completed, true)])>
                <span class="install-progress-icon" aria-hidden="true"></span>
                <span><strong>{{ $label }}</strong><small data-detail></small></span>
            </li>
        @endforeach
    </ol>

    <div id="install-message" class="site-alert site-alert-warning" role="alert" hidden></div>
    <div id="install-warnings" class="site-alert site-alert-warning" role="status" hidden></div>

    <div class="install-actions">
        <a href="{{ route('install.accounts') }}" class="site-btn site-btn-ghost" id="install-back">Kembali</a>
        <button type="button" class="site-btn site-btn-primary" id="install-start">{{ $completed === [] ? 'Mulai instal' : 'Lanjutkan instal' }}</button>
    </div>

    <script>
        (() => {
            const steps = @js(array_keys($steps));
            const url = @js(route('install.run.step', ['step' => '__STEP__']));
            const token = @js(csrf_token());
            const button = document.getElementById('install-start');
            const back = document.getElementById('install-back');
            const message = document.getElementById('install-message');
            const warnings = document.getElementById('install-warnings');
            const item = (step) => document.querySelector(`[data-step="${step}"]`);

            const call = async (step) => {
                const response = await fetch(url.replace('__STEP__', step), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                });
                let body = {};
                try { body = await response.json(); } catch (e) { body = { ok: false, message: 'Server tidak merespons dengan benar (kode ' + response.status + ')' }; }
                return body;
            };

            const addWarning = (text) => {
                if (! text) return;
                warnings.hidden = false;
                warnings.insertAdjacentHTML('beforeend', '<p></p>');
                warnings.lastElementChild.textContent = text;
            };

            const run = async () => {
                button.disabled = true;
                back.hidden = true;
                message.hidden = true;

                for (const step of steps) {
                    const li = item(step);
                    if (li.classList.contains('is-done')) continue;
                    li.classList.add('is-running');

                    let body;
                    do {
                        body = await call(step);
                        if (body.detail) li.querySelector('[data-detail]').textContent = body.detail;
                    } while (body.ok && ! body.done);

                    li.classList.remove('is-running');

                    if (! body.ok) {
                        li.classList.add('is-failed');
                        message.textContent = body.message || 'Langkah gagal';
                        message.hidden = false;
                        button.textContent = 'Coba lagi';
                        button.disabled = false;
                        back.hidden = false;
                        return;
                    }

                    li.classList.remove('is-failed');
                    li.classList.add('is-done');
                    addWarning(body.warning);

                    if (body.redirect) {
                        button.textContent = 'Selesai — membuka halaman login…';
                        setTimeout(() => { window.location.href = body.redirect; }, warnings.hidden ? 1200 : 6000);
                        return;
                    }
                }
            };

            button.addEventListener('click', run);
        })();
    </script>
</x-install.layout>
