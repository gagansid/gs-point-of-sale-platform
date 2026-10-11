<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Export laporan melebihi batas baris (sinkron, tanpa queue — Q52): ditolak dengan pesan jelas,
 * data tidak pernah dipotong diam-diam.
 */
final class ReportTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $rows, public readonly int $limit)
    {
        parent::__construct('Data terlalu besar untuk diekspor. Persempit rentang tanggal.');
    }
}
