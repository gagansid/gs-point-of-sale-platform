<?php

declare(strict_types=1);

it('menjalankan aplikasi', function () {
    $this->get('/up')->assertOk();
});
