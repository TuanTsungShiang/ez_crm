<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class MemberCreated implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Member $member) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'member.created',
            'occurred_at' => now()->toIso8601String(),
            // sequence 由 DispatchWebhook 在 WebhookEvent::create 後回填
            'data' => [
                'uuid'     => $this->member->uuid,
                'email'    => $this->member->email,
                'name'     => $this->member->name,
                'nickname' => $this->member->nickname,
            ],
        ];
    }
}
