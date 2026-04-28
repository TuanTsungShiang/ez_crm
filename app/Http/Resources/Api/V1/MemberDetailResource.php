<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'              => $this->uuid,
            'name'              => $this->name,
            'nickname'          => $this->nickname,
            'email'             => $this->email,
            'phone'             => $this->phone,
            'status'            => $this->status,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'group'             => $this->whenLoaded('group', fn() => [
                'name' => $this->group?->name,
            ]),
            'tags'              => $this->whenLoaded('tags', fn() =>
                $this->tags->map(fn($tag) => [
                    'name'  => $tag->name,
                    'color' => $tag->color,
                ])
            ),
            'profile'           => $this->whenLoaded('profile', fn() => $this->profile ? [
                'avatar'   => $this->profile->avatar,
                'gender'   => $this->profile->gender,
                'birthday' => $this->profile->birthday?->format('Y-m-d'),
                'bio'      => $this->profile->bio,
                'language' => $this->profile->language,
                'timezone' => $this->profile->timezone,
            ] : null),
            'sns'               => $this->whenLoaded('sns', fn() =>
                $this->sns->map(fn($s) => [
                    'provider' => $s->provider,
                ])
            ),
            'has_sns'           => $this->whenCounted('sns', fn() => $this->sns_count > 0),
            'has_local_password' => $this->password_set_at !== null,
            'last_login_at'     => $this->last_login_at?->toIso8601String(),
            'created_at'        => $this->created_at->toIso8601String(),
            'updated_at'        => $this->updated_at->toIso8601String(),
        ];
    }
}
