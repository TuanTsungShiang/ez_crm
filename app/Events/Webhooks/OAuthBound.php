<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use App\Models\MemberSns;
use Illuminate\Queue\SerializesModels;

class OAuthBound implements WebhookEvent
{
    use SerializesModels;

    public function __construct(
        public Member $member,
        public MemberSns $sns,
        public bool $isNewAccount,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'oauth.bound',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'member_uuid'      => $this->member->uuid,
                'member_email'     => $this->member->email,
                'provider'         => $this->sns->provider,
                'provider_user_id' => $this->sns->provider_user_id,
                'is_new_account'   => $this->isNewAccount,
            ],
        ];
    }
}
