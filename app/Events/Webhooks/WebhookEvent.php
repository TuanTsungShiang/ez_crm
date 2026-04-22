<?php

namespace App\Events\Webhooks;

/**
 * 所有 webhook event 類別都應該實作 toWebhookPayload() 方法。
 * DispatchWebhook listener 靠這個 interface 統一取 payload。
 *
 * 不用強制繼承此 class,也可以自己實作方法即可(duck typing)。
 * 這個 class 存在只是當作文件 / 型別提示用。
 */
interface WebhookEvent
{
    /**
     * 回傳 webhook 要送給下游的 payload 結構:
     *   - event: string (例 "member.created")
     *   - occurred_at: ISO8601 timestamp
     *   - data: array (事件相關的業務資料快照)
     */
    public function toWebhookPayload(): array;
}
