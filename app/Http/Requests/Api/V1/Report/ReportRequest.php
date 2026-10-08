<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Report;

use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Rentang laporan dalam tanggal lokal outlet (inklusif). Default: hari ini. Maks. 366 hari.
 */
final class ReportRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->can('report.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $days = CarbonImmutable::parse($this->from())->diffInDays(CarbonImmutable::parse($this->to())) + 1;
            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Rentang laporan maksimal '.self::MAX_DAYS.' hari');
            }
        }];
    }

    public function from(): string
    {
        return $this->filled('from') ? $this->string('from')->toString() : CurrentOutlet::today();
    }

    public function to(): string
    {
        return $this->filled('to') ? $this->string('to')->toString() : $this->from();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function utcRange(): array
    {
        return CurrentOutlet::utcRange($this->from(), $this->to());
    }

    /** @return array{from: string, to: string, timezone: string} */
    public function period(): array
    {
        return ['from' => $this->from(), 'to' => $this->to(), 'timezone' => CurrentOutlet::timezone()];
    }
}
