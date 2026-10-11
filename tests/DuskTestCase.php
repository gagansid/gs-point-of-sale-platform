<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\TestCase as BaseTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\BeforeClass;
use Symfony\Component\Process\Process;

/**
 * Uji E2E browser (R9) dengan Laravel Dusk + ChromeDriver headless.
 *
 * Aman untuk data lokal: server uji (php -S, port 8010) dan proses test memakai database terpisah
 * database/dusk.sqlite, mode satu domain (/dashboard, /cashier). JANGAN pakai `php artisan dusk`
 * (menukar file .env). Jalankan: ./vendor/bin/pest tests/Browser
 */
abstract class DuskTestCase extends BaseTestCase
{
    public const PORT = 8010;

    private static ?Process $server = null;

    /** Env server & proses test: DB terpisah, satu domain, tanpa cache laporan. */
    private const ENV = [
        'APP_ENV' => 'local',
        'APP_URL' => 'http://127.0.0.1:8010',
        'DB_CONNECTION' => 'sqlite',
        'POS_EDITION' => 'saas',
        'POS_MAIN_DOMAIN' => '',
        'POS_APP_DOMAIN' => '',
        'POS_ADMIN_DOMAIN' => '',
        'POS_API_DOMAIN' => '',
        'SESSION_DOMAIN' => 'null',
        'SESSION_DRIVER' => 'database',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'POS_REPORT_CACHE_SECONDS' => '0',
        'POS_ADMIN_2FA_REQUIRED' => 'false',
    ];

    public static function databasePath(): string
    {
        return dirname(__DIR__).'/database/dusk.sqlite';
    }

    #[BeforeClass]
    public static function prepare(): void
    {
        static::startChromeDriver(['--port=9515']);
        self::startServer();
    }

    private static function startServer(): void
    {
        if (self::$server?->isRunning()) {
            return;
        }

        touch(self::databasePath());
        $root = dirname(__DIR__);

        self::$server = new Process(
            // server.php memakai direktori kerja sebagai folder public
            ['php', '-S', '127.0.0.1:'.self::PORT, $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
            $root.'/public',
            [...self::ENV, 'DB_DATABASE' => self::databasePath()],
        );
        self::$server->start();
        register_shutdown_function(fn () => self::$server?->stop());

        // Tunggu server siap (maks. 5 detik)
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', self::PORT) === false; $i++) {
            usleep(100_000);
        }
    }

    public function createApplication(): Application
    {
        foreach ([...self::ENV, 'DB_DATABASE' => self::databasePath()] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', self::databasePath());
        DB::purge('sqlite');

        // Pengaman: migrasi ulang (DatabaseMigrations) hanya boleh ke dusk.sqlite, bukan database.sqlite lokal
        if (realpath((string) $app['config']->get('database.connections.sqlite.database')) !== realpath(self::databasePath())) {
            throw new LogicException('Dusk wajib memakai database/dusk.sqlite');
        }

        return $app;
    }

    protected function baseUrl(): string
    {
        return 'http://127.0.0.1:'.self::PORT;
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--window-size=1366,900',
            '--headless=new',
            '--disable-gpu',
            '--disable-search-engine-choice-screen',
        ]);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }
}
