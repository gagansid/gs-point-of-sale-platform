<?php

declare(strict_types=1);

namespace App\Support\Installer;

use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Tes koneksi database untuk installer (ADR 0016): bisa terhubung, versi didukung (MySQL 8 / MariaDB 10.6+, ADR 0004),
 * dan database kosong (installer tidak pernah menimpa data). Pesan tanpa kata sandi.
 * Tidak final: diganti mock di test.
 */
class DatabaseProbe
{
    public const CONNECTION = 'install_probe';

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: string|null}  $db
     * @return array{ok: bool, message: string}
     */
    public function check(array $db): array
    {
        config(['database.connections.'.self::CONNECTION => self::connectionConfig($db)]);
        DB::purge(self::CONNECTION);

        try {
            $connection = DB::connection(self::CONNECTION);
            $connection->getPdo();

            $version = (string) $connection->selectOne('select version() as v')->v;
            $versionError = self::versionError($version);

            if ($versionError !== null) {
                return ['ok' => false, 'message' => $versionError];
            }

            $tables = count($connection->getSchemaBuilder()->getTables());

            if ($tables > 0) {
                return ['ok' => false, 'message' => "Database tidak kosong ({$tables} tabel). Pakai database baru yang kosong agar data lama tidak tertimpa."];
            }

            return ['ok' => true, 'message' => 'Terhubung ('.self::versionLabel($version).'), database kosong'];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => self::friendly($e)];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Tidak bisa terhubung ke database. Periksa kembali isian Anda.'];
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: string|null}  $db
     * @return array<string, mixed>
     */
    public static function connectionConfig(array $db): array
    {
        return [
            'driver' => 'mysql',
            'host' => $db['host'],
            'port' => (int) $db['port'],
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => (string) ($db['password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
    }

    private static function versionError(string $version): ?string
    {
        $isMaria = stripos($version, 'mariadb') !== false;
        $number = (string) preg_replace('/^(\d+\.\d+(\.\d+)?).*$/', '$1', $version);

        if ($isMaria ? version_compare($number, '10.6', '<') : version_compare($number, '8.0', '<')) {
            return 'Versi database '.self::versionLabel($version).' tidak didukung. Butuh MySQL 8 atau MariaDB 10.6+.';
        }

        return null;
    }

    private static function versionLabel(string $version): string
    {
        return (stripos($version, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ').preg_replace('/^(\d+\.\d+(\.\d+)?).*$/', '$1', $version);
    }

    private static function friendly(PDOException $e): string
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            1045 => 'Akses ditolak: username atau kata sandi database salah.',
            1044 => 'User database tidak punya izin ke database ini. Tambahkan user ke database (cPanel → MySQL Databases).',
            1049 => 'Database tidak ditemukan. Buat dulu di cPanel → MySQL Databases.',
            2002, 2003, 2005 => 'Server database tidak bisa dihubungi. Periksa host & port (cPanel biasanya localhost:3306).',
            default => 'Tidak bisa terhubung ke database (kode '.$code.').',
        };
    }
}
