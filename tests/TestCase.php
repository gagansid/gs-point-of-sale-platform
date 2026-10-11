<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disk public palsu untuk semua test: seeder gambar demo & unggahan tidak boleh menulis ke
        // storage/app/public lokal yang asli (pernah terjadi lewat DatabaseSeeder di SeederTest)
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        // TrustHosts (aktif bila test mengganti env ke production) menyimpan daftar host secara statis di
        // Symfony Request; tanpa reset, host test berikutnya (mis. api.gspos.localhost) ditolak 400.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }
}
