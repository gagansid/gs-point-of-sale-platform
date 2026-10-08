<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Auth\Data\ApprovalData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Jalur PIN approval (docs/standards/api/auth-and-permission.md §4).
 *
 * Mengembalikan ID approver, atau null bila pelaku sendiri sudah punya permission.
 */
final class VerifyApproval
{
    public function __construct(private readonly VerifyPin $verifyPin) {}

    /**
     * @param  ErrorCode  $missingCode  kode bila approval tidak dikirim (APPROVAL_REQUIRED / DISCOUNT_OVER_LIMIT)
     * @param  array<string, mixed>|null  $details
     *
     * @throws BusinessException
     */
    public function handle(User $actor, string $permission, ?ApprovalData $approval, ErrorCode $missingCode = ErrorCode::ApprovalRequired, ?array $details = null): ?string
    {
        if ($actor->can($permission)) {
            return null;
        }

        if ($approval === null) {
            throw BusinessException::of($missingCode, details: $details);
        }

        if ($approval->approverUserId === $actor->id) {
            throw BusinessException::of(ErrorCode::SelfApprovalNotAllowed);
        }

        // Scope tenant aktif: approver tenant lain tidak ditemukan → diperlakukan PIN salah
        $approver = User::query()->where('is_active', true)->find($approval->approverUserId);

        if ($approver === null) {
            throw BusinessException::of(ErrorCode::InvalidPin);
        }

        $this->verifyPin->handle($approver, $approval->pin);

        if (! $approver->can($permission)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Approver tidak memiliki wewenang untuk aksi ini');
        }

        return $approver->id;
    }
}
