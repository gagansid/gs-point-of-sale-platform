<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Actions\Auth\Data\AuthResult;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respons login: token Bearer + data user, tenant, outlet.
 *
 * @property AuthResult $resource
 */
final class AuthTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource->user;

        return [
            'token' => $this->resource->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => Iso::dateTime($this->resource->token->accessToken->expires_at),
            ...MeResource::make($user)->toArray($request),
        ];
    }
}
