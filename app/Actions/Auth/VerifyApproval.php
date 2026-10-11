<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Auth\Data\ApprovalData;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\WebCashier;
use Closure;

/**
 * Jalur PIN approval (docs/standards/api/auth-and-permission.md §4).
 *
 * Mengembalikan ID approver, atau null bila pelaku sendiri sudah punya permission.
 */
final class VerifyApproval
{
    public function __construct(private readonly VerifyPin $verifyPin) {}

    /**
     * @param  string  $outletId  outlet tempat aksi dilakukan; approver wajib ditugaskan di sana (ADR 0010)
     * @param  ErrorCode  $missingCode  kode bila approval tidak dikirim (APPROVAL_REQUIRED / DISCOUNT_OVER_LIMIT)
     * @param  array<string, mixed>|null  $details
     * @param  (Closure(User): bool)|null  $authorizes  wewenang approver; default $approver->can($permission)
     *
     * @throws BusinessException
     */
    public function handle(User $actor, string $outletId, string $permission, ?ApprovalData $approval, ErrorCode $missingCode = ErrorCode::ApprovalRequired, ?array $details = null, ?Closure $authorizes = null): ?string
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

        // Penghitung terpisah dari login PIN, per perangkat pelaku (GSPOS-SEC-002). Kasir web memakai
        // device browser (Q54); tanpa ini semua sesi web berbagi satu penghitung "approval:none".
        // Browser bisa membuat device baru (hapus cookie), jadi penghitung web per pelaku, bukan per device.
        $token = $actor->currentAccessToken();
        $webDevice = $token instanceof PersonalAccessToken ? null : WebCashier::deviceOrNull();
        $deviceId = $token instanceof PersonalAccessToken ? $token->device_id : $webDevice?->id;
        $source = $webDevice !== null ? 'approval:web-user:'.$actor->id : 'approval:'.($deviceId ?? 'none');
        $this->verifyPin->handle($approver, $approval->pin, $source, $deviceId);

        $authorizes ??= fn (User $user): bool => $user->can($permission);

        if (! $authorizes($approver)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Approver tidak memiliki wewenang untuk aksi ini');
        }

        // Supervisor/manager outlet lain tidak boleh meng-approve; owner memegang semua outlet (GSPOS-SEC-003)
        if (! $approver->canAccessOutlet($outletId)) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Approver tidak ditugaskan di outlet ini');
        }

        return $approver->id;
    }
}
