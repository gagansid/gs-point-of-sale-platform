<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Announcement;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
final class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'starts_at' => Iso::dateTime($this->starts_at),
            'ends_at' => Iso::dateTime($this->ends_at),
        ];
    }
}
