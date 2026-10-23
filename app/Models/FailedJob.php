<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris tabel failed_jobs (queue driver database) untuk halaman Sistem → Antrean. Hanya-baca;
 * ulangi/hapus lewat App\Actions\Admin\ManageFailedJobs (perintah artisan bawaan).
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property CarbonImmutable $failed_at
 */
final class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';

    protected function casts(): array
    {
        return ['failed_at' => 'immutable_datetime'];
    }

    /** Nama job dari payload, mis. "App\Notifications\InviteOwner". */
    public function displayName(): string
    {
        $payload = json_decode($this->payload, true);

        return is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : '—';
    }

    /** Baris pertama pesan error (tanpa stack trace). */
    public function errorSummary(): string
    {
        return str($this->exception)->before("\n")->limit(200)->toString();
    }
}
