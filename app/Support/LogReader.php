<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Membaca log Laravel untuk Sistem → Log (tanpa SSH di shared hosting). Hanya file *.log langsung di
 * storage/logs, dipilih dari daftar (nama file dari pengguna tidak pernah dipakai sebagai path).
 * Hanya bagian akhir file yang dibaca agar log besar tidak menghabiskan memori PHP.
 */
final class LogReader
{
    /** Batas baca dari akhir file */
    public const TAIL_BYTES = 1_048_576;

    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** @return array<string, array{name: string, size: int, modified: int}> nama file => info, terbaru dulu */
    public static function files(): array
    {
        $files = collect(File::files(storage_path('logs')))
            ->filter(fn (\SplFileInfo $f): bool => $f->getExtension() === 'log')
            ->sortByDesc(fn (\SplFileInfo $f): int => $f->getMTime())
            ->mapWithKeys(fn (\SplFileInfo $f): array => [$f->getFilename() => [
                'name' => $f->getFilename(),
                'size' => $f->getSize(),
                'modified' => $f->getMTime(),
            ]]);

        return $files->all();
    }

    /** Path absolut file dari daftar, atau null bila tidak ada (anti path traversal). */
    public static function path(?string $name): ?string
    {
        return $name !== null && array_key_exists($name, self::files()) ? storage_path('logs/'.$name) : null;
    }

    /**
     * Entri terbaru dulu, dari bagian akhir file.
     *
     * @return list<array{time: string, env: string, level: string, message: string, context: string}>
     */
    public static function entries(string $path, ?string $level = null, ?string $search = null, int $limit = 200): array
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        // Potongan awal bisa terpotong di tengah entri: buang sampai entri utuh pertama
        if ($size > self::TAIL_BYTES && ($start = strpos($content, "\n[")) !== false) {
            $content = substr($content, $start + 1);
        }

        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $content) ?: [];
        $needle = $search !== null && trim($search) !== '' ? mb_strtolower(trim($search)) : null;
        $entries = [];

        foreach (array_reverse($parts) as $raw) {
            if (! preg_match('/^\[(?<time>[^\]]+)\] (?<env>[\w-]+)\.(?<level>\w+): (?<rest>.*)$/s', $raw, $m)) {
                continue;
            }

            $entryLevel = strtolower($m['level']);
            if ($level !== null && $level !== '' && $entryLevel !== $level) {
                continue;
            }

            if ($needle !== null && ! str_contains(mb_strtolower($raw), $needle)) {
                continue;
            }

            [$message, $context] = array_pad(explode("\n", trim($m['rest']), 2), 2, '');
            $entries[] = ['time' => $m['time'], 'env' => $m['env'], 'level' => $entryLevel, 'message' => $message, 'context' => $context];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }
}
