<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class MemberUpdated implements WebhookEvent
{
    use SerializesModels;

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     *         例:['nickname' => ['from' => null, 'to' => '小明']]
     */
    public function __construct(
        public Member $member,
        public array $changes,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'member.updated',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'uuid'    => $this->member->uuid,
                'email'   => $this->member->email,
                'changes' => $this->changes,
            ],
        ];
    }
}
