<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Install;

use App\Actions\Install\CreateInitialAccounts;
use App\Actions\Install\Data\InstallData;
use App\Actions\Install\FinishInstallation;
use App\Actions\Install\RunPendingMigrations;
use App\Actions\Install\WriteInstallEnvironment;
use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Support\Edition;
use App\Support\Installer\DatabaseProbe;
use App\Support\Installer\EnvironmentFile;
use App\Support\Installer\Installation;
use App\Support\Installer\Requirements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Installer web /install (ADR 0016). Hanya bisa dibuka sebelum terinstal (middleware EnsureInstalled).
 * Isian disimpan di session terenkripsi (driver file selama instal); proses instal per langkah lewat AJAX.
 *
 *   token → requirements → database → application → accounts → run (environment, migrate, accounts, finish)
 */
final class InstallController extends Controller
{
    /** Langkah proses instal, berurutan. */
    public const RUN_STEPS = [
        'environment' => 'Menulis konfigurasi (.env)',
        'migrate' => 'Membuat tabel database',
        'accounts' => 'Membuat akun & data awal',
        'finish' => 'Menyiapkan file publik & menyelesaikan',
    ];

    /** Zona waktu outlet yang umum di Indonesia. */
    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB — Asia/Jakarta',
        'Asia/Makassar' => 'WITA — Asia/Makassar',
        'Asia/Jayapura' => 'WIT — Asia/Jayapura',
    ];

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($this->tokenRequired($request) ? 'install.token' : 'install.requirements');
    }

    public function token(): View
    {
        return view('install.token', [
            'fromEnvironment' => Installation::tokenFromEnvironment(),
            'codePath' => Installation::codeDisplayPath(),
        ]);
    }

    public function verifyToken(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:200']], [], ['token' => 'kode instal']);

        if (! Installation::tokenMatches((string) $request->input('token'))) {
            throw ValidationException::withMessages(['token' => 'Kode instal salah']);
        }

        // ID session baru setelah lolos kode: cegah session fixation
        $request->session()->regenerate();
        $request->session()->put('install.token_ok', true);

        return redirect()->route('install.requirements');
    }

    public function requirements(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'requirements')) {
            return $redirect;
        }

        return view('install.requirements', ['items' => Requirements::check(), 'passes' => Requirements::passes()]);
    }

    public function acceptRequirements(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 'requirements')) {
            return $redirect;
        }

        if (! Requirements::passes()) {
            return back()->withErrors(['requirements' => 'Masih ada syarat wajib yang belum terpenuhi']);
        }

        $request->session()->put('install.requirements_ok', true);

        return redirect()->route('install.database');
    }

    public function database(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'database')) {
            return $redirect;
        }

        return view('install.database', ['saved' => $this->sessionArray($request, 'install.database')]);
    }

    public function saveDatabase(Request $request, DatabaseProbe $probe): RedirectResponse
    {
        if ($redirect = $this->guard($request, 'database')) {
            return $redirect;
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-_:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-$]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [], ['host' => 'host', 'port' => 'port', 'database' => 'nama database', 'username' => 'user database', 'password' => 'kata sandi database']);

        // Kosong = pakai kata sandi yang sudah tersimpan (tidak pernah ditampilkan ulang)
        $saved = $this->sessionArray($request, 'install.database');
        $data['password'] = filled($data['password'] ?? null) ? (string) $data['password'] : ($saved['password'] ?? '');

        try {
            EnvironmentFile::format($data['password']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['password' => 'Kata sandi berisi kombinasi karakter yang tidak bisa disimpan di .env. Ganti kata sandi database (hindari kutip tunggal bersama $, `, \\ atau ").']);
        }

        $result = $probe->check($data);

        if (! $result['ok']) {
            return back()->withInput($request->except('password'))->withErrors(['connection' => $result['message']]);
        }

        $request->session()->put('install.database', $data);

        return $request->input('action') === 'test'
            ? back()->with('install.status', $result['message'])
            : redirect()->route('install.application');
    }

    public function application(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'application')) {
            return $redirect;
        }

        $saved = $this->sessionArray($request, 'install.application');
        $host = $request->getHost();

        return view('install.application', [
            'saved' => $saved + [
                'app_name' => 'gs.POS',
                'app_url' => $request->getSchemeAndHttpHost(),
                'edition' => Edition::current(),
                'timezone' => (string) config('pos.default_timezone'),
                'app_domain' => "app.{$host}",
                'admin_domain' => "admin.{$host}",
                'api_domain' => "api.{$host}",
                'mail_port' => 587,
                'mail_encryption' => 'tls',
            ],
            'timezones' => self::TIMEZONES,
        ]);
    }

    public function saveApplication(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 'application')) {
            return $redirect;
        }

        $hostname = 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:50'],
            'app_url' => ['required', 'url:http,https', 'max:255'],
            'edition' => ['required', Rule::in([Edition::SAAS, Edition::SELF_HOSTED])],
            'timezone' => ['required', Rule::in(array_keys(self::TIMEZONES))],
            'use_subdomains' => ['boolean'],
            'app_domain' => ['exclude_unless:use_subdomains,1', 'required', 'string', $hostname, 'different:admin_domain', 'different:api_domain'],
            'admin_domain' => ['exclude_unless:use_subdomains,1', 'required', 'string', $hostname, 'different:api_domain'],
            'api_domain' => ['exclude_unless:use_subdomains,1', 'required', 'string', $hostname],
            'use_mail' => ['boolean'],
            'mail_host' => ['exclude_unless:use_mail,1', 'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'mail_port' => ['exclude_unless:use_mail,1', 'required', 'integer', 'between:1,65535'],
            'mail_username' => ['exclude_unless:use_mail,1', 'nullable', 'string', 'max:255'],
            'mail_password' => ['exclude_unless:use_mail,1', 'nullable', 'string', 'max:255'],
            'mail_encryption' => ['exclude_unless:use_mail,1', 'nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['exclude_unless:use_mail,1', 'required', 'email', 'max:255'],
        ], [], [
            'app_name' => 'nama aplikasi', 'app_url' => 'URL aplikasi', 'edition' => 'edisi', 'timezone' => 'zona waktu',
            'app_domain' => 'domain dashboard', 'admin_domain' => 'domain admin', 'api_domain' => 'domain API',
            'mail_host' => 'host SMTP', 'mail_port' => 'port SMTP', 'mail_from_address' => 'email pengirim',
        ]);

        $saved = $this->sessionArray($request, 'install.application');
        $data['use_subdomains'] = $request->boolean('use_subdomains');

        // SMTP dilewati: kolom email diabaikan (email dicatat di log sampai diisi di .env)
        if (! $request->boolean('use_mail')) {
            $data = array_merge($data, ['mail_host' => null, 'mail_port' => null, 'mail_username' => null, 'mail_password' => null, 'mail_from_address' => null]);
            $saved['mail_password'] = null;
        }

        $data['mail_password'] = filled($data['mail_password'] ?? null) ? (string) $data['mail_password'] : ($saved['mail_password'] ?? null);

        $request->session()->put('install.application', $data);

        return redirect()->route('install.accounts');
    }

    public function accounts(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'accounts')) {
            return $redirect;
        }

        $application = $this->sessionArray($request, 'install.application');

        return view('install.accounts', [
            'saved' => $this->sessionArray($request, 'install.accounts'),
            'selfHosted' => ($application['edition'] ?? null) === Edition::SELF_HOSTED,
            'businessTypes' => BusinessType::cases(),
        ]);
    }

    public function saveAccounts(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 'accounts')) {
            return $redirect;
        }

        $selfHosted = ($this->sessionArray($request, 'install.application')['edition'] ?? null) === Edition::SELF_HOSTED;

        if ($selfHosted) {
            // Jual putus: tepat satu bisnis, wajib dibuat saat instal (ADR 0009)
            $request->merge(['create_business' => '1']);
        }

        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Sama dengan CreateAdmin: super admin memegang akses lintas bisnis
            'admin_password' => ['required', 'confirmed', Password::defaults()->min(12)->mixedCase()->symbols()],
            'create_business' => ['boolean'],
            'business_name' => ['exclude_unless:create_business,1', 'required', 'string', 'max:100'],
            'business_type' => ['exclude_unless:create_business,1', 'required', Rule::enum(BusinessType::class)],
            'owner_name' => ['exclude_unless:create_business,1', 'required', 'string', 'max:100'],
            'owner_email' => ['exclude_unless:create_business,1', 'required', 'email', 'max:150'],
            'owner_password' => ['exclude_unless:create_business,1', 'required', 'confirmed', Password::defaults()],
            'create_manager' => ['boolean'],
            'manager_name' => ['exclude_unless:create_business,1', 'exclude_unless:create_manager,1', 'required', 'string', 'max:100'],
            'manager_email' => ['exclude_unless:create_business,1', 'exclude_unless:create_manager,1', 'required', 'email', 'max:150', 'different:owner_email'],
            'manager_password' => ['exclude_unless:create_business,1', 'exclude_unless:create_manager,1', 'required', 'confirmed', Password::defaults()],
        ], [], [
            'admin_name' => 'nama super admin', 'admin_email' => 'email super admin', 'admin_password' => 'kata sandi super admin',
            'business_name' => 'nama bisnis', 'business_type' => 'jenis usaha', 'owner_name' => 'nama owner',
            'owner_email' => 'email owner', 'owner_password' => 'kata sandi owner', 'manager_name' => 'nama manager',
            'manager_email' => 'email manager', 'manager_password' => 'kata sandi manager',
        ]);

        $data['create_business'] = $selfHosted || $request->boolean('create_business');
        $data['create_manager'] = $data['create_business'] && $request->boolean('create_manager');
        unset($data['admin_password_confirmation'], $data['owner_password_confirmation'], $data['manager_password_confirmation']);

        $request->session()->put('install.accounts', $data);

        return redirect()->route('install.run');
    }

    public function run(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'run')) {
            return $redirect;
        }

        $data = $this->installData($request);

        return view('install.run', [
            'data' => $data,
            'steps' => self::RUN_STEPS,
            'completed' => Installation::state()['completed'],
        ]);
    }

    public function runStep(Request $request, string $step): JsonResponse
    {
        abort_unless(array_key_exists($step, self::RUN_STEPS), 404);

        if ($this->guard($request, 'run') !== null) {
            return response()->json(['ok' => false, 'message' => 'Sesi instal berakhir. Muat ulang halaman dan isi kembali form.'], 409);
        }

        $completed = Installation::state()['completed'];

        if (in_array($step, $completed, true) && $step !== 'finish') {
            return response()->json(['ok' => true, 'done' => true]);
        }

        // Langkah sebelumnya wajib selesai (urutan tidak bisa dilompati)
        foreach (array_keys(self::RUN_STEPS) as $previous) {
            if ($previous === $step) {
                break;
            }

            if (! in_array($previous, $completed, true)) {
                return response()->json(['ok' => false, 'message' => 'Langkah sebelumnya belum selesai'], 409);
            }
        }

        try {
            return response()->json(['ok' => true, ...$this->execute($request, $step)]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Langkah gagal. Detail tercatat di storage/logs/laravel.log. Perbaiki lalu klik Coba lagi.',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(Request $request, string $step): array
    {
        $data = $this->installData($request);

        switch ($step) {
            case 'environment':
                app(WriteInstallEnvironment::class)->handle($data);
                Installation::completeStep($step);

                return ['done' => true];

            case 'migrate':
                $result = app(RunPendingMigrations::class)->handle();

                if ($result['remaining'] === 0) {
                    Installation::completeStep($step);
                }

                return [
                    'done' => $result['remaining'] === 0,
                    'detail' => ($result['total'] - $result['remaining']).' / '.$result['total'].' tabel & perubahan',
                ];

            case 'accounts':
                $result = app(CreateInitialAccounts::class)->handle($data);
                Installation::completeStep($step);

                return ['done' => true, 'warning' => $result['warning']];

            default: // finish
                $result = app(FinishInstallation::class)->handle($data->createsBusiness());
                $request->session()->forget('install');

                return ['done' => true, 'warning' => $result['warning'], 'redirect' => $result['redirect']];
        }
    }

    private function installData(Request $request): InstallData
    {
        return InstallData::fromSession(
            $this->sessionArray($request, 'install.database'),
            $this->sessionArray($request, 'install.application'),
            $this->sessionArray($request, 'install.accounts'),
        );
    }

    /**
     * Langkah form hanya bisa dibuka bila langkah sebelumnya sudah diisi.
     */
    private function guard(Request $request, string $page): ?RedirectResponse
    {
        if ($this->tokenRequired($request)) {
            return redirect()->route('install.token');
        }

        $needs = [
            'requirements' => null,
            'database' => 'install.requirements_ok',
            'application' => 'install.database',
            'accounts' => 'install.application',
            'run' => 'install.accounts',
        ];
        $order = ['install.requirements_ok' => 'install.requirements', 'install.database' => 'install.database',
            'install.application' => 'install.application', 'install.accounts' => 'install.accounts'];

        foreach (array_keys($needs) as $current) {
            $key = $needs[$current];

            if ($key !== null && ! $request->session()->has($key)) {
                return redirect()->route($order[$key]);
            }

            if ($current === $page) {
                return null;
            }
        }

        return null;
    }

    /** Kode instal selalu wajib (GSPOS-SEC-026); lolos sekali per session installer. */
    private function tokenRequired(Request $request): bool
    {
        return ! $request->session()->get('install.token_ok', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionArray(Request $request, string $key): array
    {
        $value = $request->session()->get($key);

        return is_array($value) ? $value : [];
    }
}
