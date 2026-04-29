<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'nickname'      => $this->nickname,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'status'        => $this->status,
            'group'         => $this->whenLoaded('group', fn() => [
                'name' => $this->group?->name,
            ]),
            'tags'          => $this->whenLoaded('tags', fn() =>
                $this->tags->map(fn($tag) => [
                    'name'  => $tag->name,
                    'color' => $tag->color,
                ])
            ),
            'has_sns'       => $this->sns_count > 0,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at'    => $this->created_at->toIso8601String(),
        ];
    }
}
