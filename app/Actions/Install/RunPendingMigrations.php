<?php

declare(strict_types=1);

namespace App\Actions\Install;

use Illuminate\Database\Migrations\Migrator;

/**
 * Langkah instal 2 (ADR 0016): menjalankan migration yang belum jalan, satu per satu, sampai batas waktu
 * per request (shared hosting: max_execution_time 30–60 detik). Dipanggil berulang sampai remaining = 0.
 */
final class RunPendingMigrations
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return array{ran: int, remaining: int, total: int}
     */
    public function handle(float $budgetSeconds = 10.0): array
    {
        $repository = $this->migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
        $pending = array_values(array_diff_key($files, array_flip($repository->getRan())));
        $started = microtime(true);
        $ran = 0;

        foreach ($pending as $file) {
            if ($ran > 0 && microtime(true) - $started >= $budgetSeconds) {
                break;
            }

            $this->migrator->requireFiles([$file]);
            $this->migrator->runPending([$file]);
            $ran++;
        }

        return ['ran' => $ran, 'remaining' => count($pending) - $ran, 'total' => count($files)];
    }
}
