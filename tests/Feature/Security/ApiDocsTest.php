<?php

declare(strict_types=1);

it('dokumentasi API bisa dibuka di local', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'gs.POS API v1');
});

it('dokumentasi API tertutup di production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});
