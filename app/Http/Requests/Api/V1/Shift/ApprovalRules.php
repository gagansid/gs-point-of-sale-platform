<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

/** Field PIN approver opsional (sama dengan void), dipakai request shift & kas (ADR 0017). */
final class ApprovalRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'string', 'digits:6', 'required_with:approver_user_id'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['approver_user_id' => 'approver', 'approver_pin' => 'PIN approver'];
    }
}
