<?php

declare(strict_types=1);

namespace App\Support\Installer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Menulis nilai ke file .env dengan aman (ADR 0016): nilai di-quote/escape, baris baru ditolak,
 * ditulis atomik (file sementara + rename) dengan izin 0640.
 */
final class EnvironmentFile
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        foreach (explode("\n", (string) file_get_contents($this->path)) as $line) {
            if (str_starts_with($line, $key.'=')) {
                return self::unquote(substr($line, strlen($key) + 1));
            }
        }

        return null;
    }

    /**
     * Membuat .env dari template (mis. .env.example) bila belum ada.
     */
    public function createFrom(string $template): void
    {
        if ($this->exists()) {
            return;
        }

        $contents = is_file($template) ? (string) file_get_contents($template) : '';
        $this->write($contents);
    }

    /**
     * Menambahkan baris KEY=... dari template yang belum ada di .env (nilai yang sudah ada tidak diubah).
     */
    public function fillMissingFrom(string $template): void
    {
        if (! is_file($template)) {
            return;
        }

        $current = $this->exists() ? (string) file_get_contents($this->path) : '';
        $missing = [];

        foreach (explode("\n", (string) file_get_contents($template)) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $m) === 1 && preg_match('/^'.$m[1].'=/m', $current) !== 1) {
                $missing[] = $line;
            }
        }

        if ($missing !== []) {
            $this->write(rtrim($current, "\n")."\n".implode("\n", $missing)."\n");
        }
    }

    /**
     * Mengganti baris KEY=... yang ada, atau menambahkannya di akhir file.
     *
     * @param  array<string, string|int|bool|null>  $values
     *
     * @throws InvalidArgumentException nilai tidak bisa ditulis aman
     */
    public function set(array $values): void
    {
        $lines = $this->exists() ? explode("\n", rtrim((string) file_get_contents($this->path), "\n")) : [];

        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("Nama variabel .env tidak valid: {$key}");
            }

            $line = $key.'='.self::format($value);
            $found = false;

            foreach ($lines as $i => $existing) {
                if (str_starts_with($existing, $key.'=')) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }

            if (! $found) {
                $lines[] = $line;
            }
        }

        $this->write(implode("\n", $lines)."\n");
    }

    /**
     * Format nilai .env: polos bila aman, kutip tunggal (literal) bila ada karakter khusus,
     * kutip ganda bila mengandung kutip tunggal. Baris baru selalu ditolak.
     *
     * @throws InvalidArgumentException
     */
    public static function format(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Nilai tidak boleh berisi baris baru');
        }

        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+\-,]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        // Kutip ganda: phpdotenv mengenali \" dan \\ ; $ & ` akan diinterpolasi, jadi ditolak
        if (preg_match('/[$`\\\\"]/', $value) === 1) {
            throw new InvalidArgumentException('Nilai berisi kombinasi karakter kutip & simbol yang tidak didukung');
        }

        return '"'.$value.'"';
    }

    private static function unquote(string $raw): string
    {
        $raw = trim($raw);

        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && str_ends_with($raw, $raw[0])) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private function write(string $contents): void
    {
        $tmp = $this->path.'.tmp-'.bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Tidak bisa menulis file .env. Pastikan folder aplikasi bisa ditulis oleh PHP.');
        }

        @chmod($tmp, 0640);

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new RuntimeException('Tidak bisa menyimpan file .env. Pastikan folder aplikasi bisa ditulis oleh PHP.');
        }
    }
}
