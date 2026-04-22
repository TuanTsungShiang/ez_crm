<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class MemberLoggedIn implements WebhookEvent
{
    use SerializesModels;

    /**
     * @param  string  $method  登入方式(email / google / github / line / discord)
     */
    public function __construct(
        public Member $member,
        public string $method,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'member.logged_in',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'uuid'   => $this->member->uuid,
                'email'  => $this->member->email,
                'method' => $this->method,
            ],
        ];
    }
}
