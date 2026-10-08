<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\CreateAdmin;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class CreateAdminCommand extends Command
{
    protected $signature = 'pos:create-admin {--name= : Nama admin} {--email= : Email login}';

    protected $description = 'Membuat akun super admin panel /admin (kata sandi diminta tanpa ditampilkan)';

    public function handle(CreateAdmin $createAdmin): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nama'));
        $email = (string) ($this->option('email') ?: $this->ask('Email'));
        $password = (string) $this->secret('Kata sandi (min. 12 karakter, huruf besar & kecil, angka, simbol)');

        if ($password !== (string) $this->secret('Ulangi kata sandi')) {
            $this->error('Kata sandi tidak sama');

            return self::FAILURE;
        }

        try {
            $admin = $createAdmin->handle($name, $email, $password);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error($messages[0]);
            }

            return self::FAILURE;
        }

        $this->info("Super admin {$admin->email} berhasil dibuat");

        return self::SUCCESS;
    }
}
