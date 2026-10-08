<?php

declare(strict_types=1);

it('memakai zona waktu UTC di server', function () {
    expect(config('app.timezone'))->toBe('UTC');
});
