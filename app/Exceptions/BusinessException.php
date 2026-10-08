<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Error aturan bisnis yang dilempar dari Action dan dipetakan ke envelope gagal
 * di bootstrap/app.php. Bukan bug, sehingga tidak dilaporkan ke log/Sentry.
 */
final class BusinessException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Bentuk yang disarankan: status HTTP diambil dari ErrorCode, pesan default bila kosong.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function of(ErrorCode $code, ?string $message = null, ?array $details = null): self
    {
        return new self($code->value, $message ?? $code->message(), $code->status(), $details);
    }
}
