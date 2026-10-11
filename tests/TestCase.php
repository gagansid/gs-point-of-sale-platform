<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // TrustHosts (aktif bila test mengganti env ke production) menyimpan daftar host secara statis di
        // Symfony Request; tanpa reset, host test berikutnya (mis. api.gspos.localhost) ditolak 400.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }
}
