<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class MemberVerifiedEmail implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Member $member) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'member.email_verified',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'uuid'              => $this->member->uuid,
                'email'             => $this->member->email,
                'email_verified_at' => $this->member->email_verified_at?->toIso8601String(),
            ],
        ];
    }
}
