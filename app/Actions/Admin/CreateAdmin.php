<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class CreateAdmin
{
    /**
     * Membuat akun super admin. Tidak ada akun/kata sandi default di sistem.
     *
     * @throws ValidationException
     */
    public function handle(string $name, string $email, string $password): Admin
    {
        $data = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', 'unique:admins,email'],
                // Super admin memegang akses lintas tenant: kata sandi lebih ketat dari default
                'password' => ['required', Password::defaults()->min(12)->mixedCase()->symbols()],
            ],
        )->validate();

        return DB::transaction(fn (): Admin => Admin::query()->create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
        ]));
    }
}
