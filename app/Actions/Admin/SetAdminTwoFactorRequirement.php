<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\Log;

final class SetAdminTwoFactorRequirement
{
    public function __construct(private readonly SystemSettings $settings) {}

    /**
     * Mengaktifkan/menonaktifkan kewajiban 2FA untuk semua super admin.
     * Konfirmasi kata sandi dilakukan di form sebelum Action dipanggil.
     */
    public function handle(Admin $by, bool $required): void
    {
        $this->settings->setAdminTwoFactorRequired($required, $by);

        // Dicatat sebagai warning: perubahan kebijakan keamanan perlu mudah ditelusuri
        Log::warning('Kebijakan wajib 2FA super admin diubah', [
            'required' => $required,
            'admin_id' => $by->id,
        ]);
    }
}
