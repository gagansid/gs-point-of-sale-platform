<?php

declare(strict_types=1);

namespace App\Actions\Auth\Data;

use SensitiveParameter;

/**
 * PIN approver untuk aksi 🔑 (void, diskon di atas batas).
 */
final readonly class ApprovalData
{
    public function __construct(
        public string $approverUserId,
        #[SensitiveParameter] public string $pin,
    ) {}
}
