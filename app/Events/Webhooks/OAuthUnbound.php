<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class OAuthUnbound implements WebhookEvent
{
    use SerializesModels;

    /**
     * Fired after a MemberSns row is deleted.
     * Payload includes the provider metadata snapshot so downstream
     * services can revoke their own references before the row is gone.
     */
    public function __construct(
        public Member $member,
        public string $provider,
        public string $providerUserId,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'oauth.unbound',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'member_uuid'      => $this->member->uuid,
                'member_email'     => $this->member->email,
                'provider'         => $this->provider,
                'provider_user_id' => $this->providerUserId,
            ],
        ];
    }
}
