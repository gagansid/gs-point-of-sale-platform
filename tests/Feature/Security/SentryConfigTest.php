<?php

declare(strict_types=1);

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use Illuminate\Contracts\Debug\ExceptionHandler;

/** Sentry (SPEC MVP): tanpa data pribadi; error bisnis tidak dilaporkan. */
it('PII tidak dikirim secara bawaan', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse();
});

it('BusinessException tidak dilaporkan (bukan bug)', function () {
    expect(app(ExceptionHandler::class)->shouldReport(BusinessException::of(ErrorCode::Forbidden)))->toBeFalse()
        ->and(app(ExceptionHandler::class)->shouldReport(new RuntimeException('bug')))->toBeTrue();
});
