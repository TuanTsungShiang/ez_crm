<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class MemberDeleted implements WebhookEvent
{
    use SerializesModels;

    /**
     * Member 已被軟刪除;event payload 送 snapshot 給下游。
     * 下游可能要根據 uuid / email 去清除自家紀錄、寄告別信、停用訂閱等。
     */
    public function __construct(public Member $member) {}

    public function toWebhookPayload(): array
    {
        return [
            'event'       => 'member.deleted',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'uuid'       => $this->member->uuid,
                'email'      => $this->member->email,
                'name'       => $this->member->name,
                'deleted_at' => $this->member->deleted_at?->toIso8601String(),
            ],
        ];
    }
}
