# ez_crm Webhook System 實作計畫

> 版本：v0.2 (incorporates code review 2026-04-22)
> 專案：ez_crm (Laravel 10 Member Data Center)
> 分支建議：`feature/webhook-system`
> 目的：為 ez_crm 建立事件驅動的 webhook 派送系統，支援未來接入 game-hub、LINE、金流、行銷工具等下游服務。

**v0.2 changelog**：
- ✅ DispatchWebhook 套 DB transaction + afterCommit（防 orphan delivery）
- ✅ 新增 `sequence_number`（防 out-of-order 問題）
- ✅ Dead Letter 告警 + Circuit Breaker（連續失敗自動停用）
- ✅ Payload size 512KB 硬上限
- ✅ 後台 UI 明確用 Filament Resource
- ✅ Testing 章節從 1 行展開為 6 個具體 test
- ✅ `X-Idempotency-Key` 取代 `X-Webhook-Delivery-Id`（業界慣例）
- ✅ Secret rotation 平滑過渡（雙 secret 並行 24h）

---

## 一、背景與目標

### 為什麼需要 webhook？

目前 ez_crm 只有 REST API，屬於「拉 (pull)」模式——外部系統必須主動輪詢才能知道變化。這在以下場景會出問題：

- **即時性不足**：下游服務必須 polling，有延遲也浪費資源。
- **多系統整合爆炸**：game-hub、LINE、ERP、SendGrid 各自 polling，API 負載大。
- **瞬時事件容易遺漏**：付款成功、表單提交等事件錯過就無法還原。

Webhook 解決的是「事件驅動」與「即時通知」，與 API 互補而非取代。

### 預期成果

- ez_crm 在發生會員相關事件時，能主動推送到任意數量的訂閱端。
- 後台可以新增/停用訂閱、查看派送紀錄、手動重送失敗的事件。
- 具備生產級可靠性：非同步派送、重試機制、HMAC 簽章、稽核日誌。
- 可以作為履歷亮點，展現事件驅動與分散式系統設計能力。

---

## 二、資料表設計

採用三張表的設計，職責分離（Event / Subscription / Delivery 為 1:N:N 關係）。
這是 Stripe、GitHub 等主流服務的標準做法。

### `webhook_subscriptions` — 訂閱端註冊

| 欄位              | 型別            | 說明                                         |
| ----------------- | --------------- | -------------------------------------------- |
| `id`              | bigint PK       |                                              |
| `name`            | varchar(100)    | 給人看的名稱，例：`game-hub 玩家同步`        |
| `url`             | varchar(500)    | 接收端 URL                                   |
| `events`          | json            | 訂閱的事件清單，例：`["member.created"]`     |
| `secret`          | varchar(64)     | HMAC 簽章密鑰（建立時隨機產生）              |
| `previous_secret` | varchar(64) null | Secret rotation 時的舊金鑰（過渡期並行驗證） |
| `previous_secret_expires_at` | datetime null | 舊 secret 何時失效（通常 rotate 後 24 小時） |
| `is_active`       | tinyint(1)      | 是否啟用（人為手動）                         |
| `is_circuit_broken` | tinyint(1)    | 連續失敗觸發自動斷路（circuit breaker）      |
| `consecutive_failure_count` | smallint | 連續失敗次數，成功一次歸零                   |
| `max_retries`     | tinyint         | 最大重試次數，預設 5                         |
| `timeout_seconds` | tinyint         | HTTP timeout，預設 10                        |
| `created_by`      | bigint FK users |                                              |
| `created_at`      | datetime        |                                              |
| `updated_at`      | datetime        |                                              |

> **為什麼 `is_active` 和 `is_circuit_broken` 分開？**
> `is_active` 是人為操作的開關（admin 手動停用）；`is_circuit_broken` 是自動機制觸發（例如連續失敗 20 次）。
> 實務查詢邏輯：`is_active AND NOT is_circuit_broken` 才派送。自動斷路的訂閱需要 admin 手動解除才能繼續。

### `webhook_events` — 事件快照

| 欄位          | 型別         | 說明                         |
| ------------- | ------------ | ---------------------------- |
| `id`          | bigint PK    | 本身即為單調遞增序號（sequence） |
| `event_type`  | varchar(100) | 事件類型，例：`member.created` |
| `payload`     | json         | 事件完整內容（快照，之後不變）|
| `occurred_at` | datetime(6)  | 事件發生時間（含微秒）|
| `created_at`  | datetime     |                              |

Index: `(event_type, occurred_at)`

**為什麼要存快照？** 對方服務掛了要補送、稽核需求、bug 重現，都需要當下的 payload。
而且 member 資料之後會變動，不存快照就還原不了事件發生當下的狀態。

**為什麼 payload 要帶 sequence？** 同一 member 連續發生 `member.updated` → `member.deleted`，兩個 async job 可能在 queue 裡亂序抵達下游。接收端可以記自己處理過的最大 `sequence`，若收到的 sequence < 已處理 → 直接忽略（out-of-order 保護）。`sequence` 就直接用 `webhook_events.id`，不需額外欄位。

**Payload size 硬上限**：`DispatchWebhook` 要在 `WebhookEvent::create()` 前檢查 `strlen(json_encode($payload)) <= 512 * 1024`。超過直接 log error 不派送（強制業務端 payload 瘦身）。

### `webhook_deliveries` — 派送紀錄

| 欄位               | 型別                                              | 說明                         |
| ------------------ | ------------------------------------------------- | ---------------------------- |
| `id`               | bigint PK                                         |                              |
| `webhook_event_id` | bigint FK                                         | 關聯的事件                   |
| `subscription_id`  | bigint FK                                         | 關聯的訂閱                   |
| `status`           | enum('pending','success','failed','retrying')     | 派送狀態                     |
| `attempts`         | tinyint                                           | 已嘗試次數                   |
| `http_status`      | smallint null                                     | 對方回傳的 HTTP status code  |
| `response_body`    | text null                                         | 對方回應內容（截前 1000 字）|
| `error_message`    | text null                                         | 異常訊息                     |
| `next_retry_at`    | datetime null                                     | 下次重試時間                 |
| `delivered_at`     | datetime null                                     | 成功送達時間                 |
| `created_at`       | datetime                                          |                              |

Index: `(status, next_retry_at)` — 方便撈出待重試的紀錄

---

## 三、事件命名規範

採用 `resource.action` 格式，小寫加點號分隔：

| 事件名稱              | 觸發時機                               |
| --------------------- | -------------------------------------- |
| `member.created`      | 會員註冊成功                           |
| `member.updated`      | 會員資料更新（暱稱、頭像、等級等）     |
| `member.deleted`      | 會員刪除/停權                          |
| `member.login`        | 會員登入                               |
| `wallet.changed`      | 錢包/點數變動                          |
| `oauth.bound`         | 綁定第三方登入（Google/GitHub/LINE 等）|
| `oauth.unbound`       | 解除綁定                               |

未來可擴充：`game.score_submitted`、`achievement.unlocked` 等。

---

## 四、Laravel 架構設計

### 流程圖

```
業務邏輯 → fire Event → Listener → dispatch Job (queue) → HTTP POST → 寫 delivery log
                                       ↓ 失敗
                                  排程下次重試（指數退避）
```

### 1. Event 類別（一個事件一個類別）

```php
// app/Events/Webhooks/MemberCreated.php
namespace App\Events\Webhooks;

use App\Models\Member;

class MemberCreated
{
    public function __construct(public Member $member) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'member.created',
            'occurred_at' => now()->toIso8601String(),
            // sequence 由 DispatchWebhook 在存 WebhookEvent 時回填（= events.id）
            'data' => [
                'id' => $this->member->id,
                'email' => $this->member->email,
                'nickname' => $this->member->nickname,
            ],
        ];
    }
}
```

### 2. 共用 Listener

所有 webhook event 共用同一個 Listener，用 `toWebhookPayload()` 介面統一取出資料。

```php
// app/Listeners/DispatchWebhook.php
namespace App\Listeners;

use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use App\Models\WebhookDelivery;
use App\Jobs\SendWebhookJob;

class DispatchWebhook
{
    const PAYLOAD_MAX_BYTES = 512 * 1024; // 512KB

    public function handle($event): void
    {
        if (!method_exists($event, 'toWebhookPayload')) {
            return;
        }

        $payload = $event->toWebhookPayload();

        // Guard: payload 大小硬上限,超過不派送
        if (strlen(json_encode($payload)) > self::PAYLOAD_MAX_BYTES) {
            \Log::error('Webhook payload exceeds 512KB limit', [
                'event' => $payload['event'],
                'size'  => strlen(json_encode($payload)),
            ]);
            return;
        }

        // 所有寫入 + job dispatch 包在 transaction 裡,
        // afterCommit() 確保 transaction 真的 commit 後才推 job,
        // 避免 queue 撿到還不存在的 delivery 紀錄。
        DB::transaction(function () use ($payload) {
            $webhookEvent = WebhookEvent::create([
                'event_type'  => $payload['event'],
                'payload'     => array_merge($payload, [
                    'sequence' => null, // 先佔位,下一行才能拿到 id
                ]),
                'occurred_at' => $payload['occurred_at'],
            ]);

            // 回填 sequence(= event id,單調遞增)
            $webhookEvent->update([
                'payload' => array_merge($payload, [
                    'sequence' => $webhookEvent->id,
                ]),
            ]);

            $subs = WebhookSubscription::where('is_active', true)
                ->where('is_circuit_broken', false)
                ->whereJsonContains('events', $payload['event'])
                ->get();

            foreach ($subs as $sub) {
                $delivery = WebhookDelivery::create([
                    'webhook_event_id' => $webhookEvent->id,
                    'subscription_id'  => $sub->id,
                    'status'           => 'pending',
                ]);

                SendWebhookJob::dispatch($delivery->id)
                    ->onQueue('webhooks')
                    ->afterCommit(); // 確保 transaction commit 後才入 queue
            }
        });
    }
}
```

### 3. EventServiceProvider 註冊

```php
protected $listen = [
    \App\Events\Webhooks\MemberCreated::class => [\App\Listeners\DispatchWebhook::class],
    \App\Events\Webhooks\MemberUpdated::class => [\App\Listeners\DispatchWebhook::class],
    \App\Events\Webhooks\WalletChanged::class => [\App\Listeners\DispatchWebhook::class],
];
```

### 4. 派送 Job（含重試與簽章）

```php
// app/Jobs/SendWebhookJob.php
namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1; // 重試邏輯自己控，不用 Laravel 內建

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with(['webhookEvent', 'subscription'])
            ->find($this->deliveryId);

        if (!$delivery || $delivery->status === 'success') {
            return;
        }

        $sub = $delivery->subscription;
        $payload = $delivery->webhookEvent->payload;
        $body = json_encode($payload);

        // HMAC SHA256 簽章：timestamp + body 一起簽，可防重放
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $sub->secret);

        try {
            $response = Http::timeout($sub->timeout_seconds)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => "v1={$signature}",
                    'X-Webhook-Event' => $payload['event'],
                    'X-Idempotency-Key' => $delivery->id, // 業界慣例,讓對方 dedup
                ])
                ->withBody($body, 'application/json')
                ->post($sub->url);

            $delivery->increment('attempts');
            $delivery->http_status = $response->status();
            $delivery->response_body = substr($response->body(), 0, 1000);

            if ($response->successful()) {
                $delivery->status = 'success';
                $delivery->delivered_at = now();
            } else {
                $this->scheduleRetry($delivery, $sub);
            }
            $delivery->save();

        } catch (\Throwable $e) {
            $delivery->increment('attempts');
            $delivery->error_message = $e->getMessage();
            $this->scheduleRetry($delivery, $sub);
            $delivery->save();
        }
    }

    private function scheduleRetry(WebhookDelivery $d, WebhookSubscription $sub): void
    {
        if ($d->attempts >= $sub->max_retries) {
            $d->status = 'failed';
            $this->tripCircuitBreakerIfNeeded($sub);
            return;
        }

        // 指數退避：1m, 5m, 30m, 2h, 12h
        $delays = [60, 300, 1800, 7200, 43200];
        $delay = $delays[$d->attempts - 1] ?? 43200;

        $d->status = 'retrying';
        $d->next_retry_at = now()->addSeconds($delay);

        SendWebhookJob::dispatch($d->id)
            ->onQueue('webhooks')
            ->delay(now()->addSeconds($delay));
    }

    /**
     * Circuit breaker：連續失敗累積超過門檻 → 自動停用訂閱。
     * 需要 admin 手動到後台重置 is_circuit_broken=false。
     */
    const CIRCUIT_BREAKER_THRESHOLD = 20;

    private function tripCircuitBreakerIfNeeded(WebhookSubscription $sub): void
    {
        $sub->increment('consecutive_failure_count');

        if ($sub->consecutive_failure_count >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $sub->is_circuit_broken = true;
            $sub->save();
            // TODO: 發通知給 admin（email / Slack / Filament 通知中心）
        }
    }

    // 成功派送時記得呼叫 $sub->update(['consecutive_failure_count' => 0]);
    // 放在 handle() 裡 $response->successful() 分支
}
```

### 5. 觸發方式

在業務程式碼裡只需要一行：

```php
$member = Member::create($data);
event(new MemberCreated($member));
```

---

## 五、安全性設計

### HMAC 簽章驗證

派送時帶 `X-Webhook-Signature` header，接收端驗證範例（給 game-hub 開發者參考）：

```php
$timestamp = $request->header('X-Webhook-Timestamp');
$signature = str_replace('v1=', '', $request->header('X-Webhook-Signature'));
$body = $request->getContent();

// 防重放攻擊：只接受 5 分鐘內的請求
if (abs(time() - $timestamp) > 300) {
    abort(401);
}

$expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

// 使用 hash_equals 防 timing attack
if (!hash_equals($expected, $signature)) {
    abort(401);
}

// 驗證通過，處理事件
```

### Secret Rotation 平滑過渡

`secret` 重新產生（rotate）時,若立刻失效舊 secret → 所有下游瞬間 HMAC 驗證失敗。正確做法：

```php
// 後台 rotate secret 時
$sub->update([
    'previous_secret'            => $sub->secret,
    'previous_secret_expires_at' => now()->addHours(24), // 過渡期 24 小時
    'secret'                     => Str::random(64),
]);
```

派送端驗證時簽新 secret；接收端（或我們的驗證邏輯）在過渡期內**新舊 secret 都試**，有一個通過即算驗證成功。24 小時後舊 secret 失效,下游有充裕時間把新 secret 換上。

### 接收端的 Idempotent Handling

我們會在派送時帶 `X-Idempotency-Key`（= delivery id）。**接收端必須用這 key 做 dedup**，因為:
- 網路抖動可能導致同一 delivery 重送多次(我們的重試機制)
- 就算我們 200 OK 後才更新狀態,極端情況仍有重複風險

接收端實作建議:
```php
// 接收端 pseudo-code
$key = $request->header('X-Idempotency-Key');
if (ReceivedWebhook::where('idempotency_key', $key)->exists()) {
    return response()->json(['status' => 'already_processed'], 200);
}
// ... 處理事件
ReceivedWebhook::create(['idempotency_key' => $key, 'processed_at' => now()]);
```

### Out-of-Order 保護(Sequence)

Payload 會帶 `sequence` 欄位（= webhook_events.id,單調遞增）。接收端若對事件順序敏感，建議記錄自己處理過的最大 sequence，收到較小的就忽略：

```php
$sequence = $payload['sequence'] ?? 0;
$lastSeen = MemberSyncState::where('member_id', $payload['data']['id'])
    ->value('last_sequence') ?? 0;

if ($sequence <= $lastSeen) {
    return response()->json(['status' => 'ignored_stale'], 200);
}
// 處理事件 + 更新 last_sequence
```

### 其他安全考量

- `secret` 在後台只顯示一次（建立時），之後只能重新產生（用上面的 rotation 機制）。
- URL 白名單（可選）：限制只能派送到預先核准的 domain。
- Rate limiting：同一訂閱端短時間內過多失敗時自動暫停（已實作 Circuit Breaker,見第二節 schema）。

---

## 六、後台管理介面

**框架選型：Filament Resource**(專案已經使用 Filament 3,直接擴充,不自己刻)

### 三個 Filament Resource

#### 1. `WebhookSubscriptionResource`(可寫)

- 建立 / 編輯 / 停用訂閱
- 欄位：名稱、URL、訂閱事件(multi-select 從事件註冊清單帶入)、timeout、最大重試數
- 建立時自動產生 64-char secret 並用 Filament Notification 跳出**只顯示一次**
- 「Rotate Secret」action:產生新 secret,舊 secret 存 `previous_secret` 過渡 24 小時
- 「Reset Circuit Breaker」action:手動把 `is_circuit_broken=false, consecutive_failure_count=0`
- Badge 顯示:`is_active` 綠 / `is_circuit_broken` 紅

#### 2. `WebhookDeliveryResource`(唯讀 + action)

- 依訂閱、事件類型、status、時間範圍篩選
- Table 欄:delivery id、event type、subscription、attempts、http_status、error 簡述、created_at
- 點進去看完整 payload + response body
- Action:
  - 「Retry」單筆重送(僅 `failed` 狀態可用)
  - Bulk「Retry All Failed」對選取的 failed deliveries 批次重送

#### 3. `WebhookEventResource`(唯讀)

- 所有事件 + 各 delivery 狀態摘要(`has 3 deliveries: 2 success / 1 failed`)
- 「Replay to All Subscribers」action:重新派送這事件到所有現有 subscribers(升級後補送用)

### Dashboard Widget:`WebhookHealthWidget`

首頁卡片顯示 webhook 系統即時健康狀態:

| 指標 | 計算 |
|---|---|
| 最近 24h 派送成功率 | `success / (success + failed)` |
| 最近 24h failed 數 | `count where status=failed and created_at > 24h ago` |
| Circuit broken 訂閱數 | `count where is_circuit_broken=true` |
| Pending + Retrying 數 | 即時 queue 深度 |

失敗率 > 5% 或 circuit broken 數 > 0 時 widget 用紅色強調。

### 建議路由(Filament 自動產)

```
/admin/webhook-subscriptions
/admin/webhook-deliveries
/admin/webhook-events
```

---

## 七、實作步驟（建議順序）

### Phase 1：基礎架構（MVP）
1. 建立 3 張資料表的 migration
2. 建立 Eloquent Models（WebhookSubscription、WebhookEvent、WebhookDelivery）
3. 建立第一個 Event：`MemberCreated`
4. 建立共用 Listener：`DispatchWebhook`
5. 建立 Job：`SendWebhookJob`（含簽章與重試）
6. 設定 queue driver（建議用 database 或 redis）
7. 在 `EventServiceProvider` 註冊

### Phase 2：後台介面
8. 訂閱 CRUD 頁面（含 secret 產生邏輯）
9. 派送紀錄查詢頁面
10. 手動重送功能

### Phase 3：擴充事件
11. 補齊其他事件（`member.updated`、`wallet.changed`、`oauth.bound` 等）
12. 在對應業務邏輯裡加上 `event(...)` 觸發

### Phase 4：生產級強化

13. **完整測試覆蓋**:

    | 層級 | 測試點 |
    |---|---|
    | Unit | `HmacSigner::sign()` / `verify()` 含 `hash_equals` 時間安全比對 |
    | Unit | `SendWebhookJob::computeRetryDelay($attempt)` 回傳 60 / 300 / 1800 / 7200 / 43200 |
    | Unit | `DispatchWebhook` 在 DB transaction rollback 時 **不** dispatch job |
    | Feature | 完整派送 flow(`Http::fake()` 攔下 outbound,驗簽章 header 正確) |
    | Feature | `max_retries` 到達 → status=`failed`,**且 `next_retry_at` 不再更新** |
    | Feature | Circuit breaker 連續 20 次 fail 後 → `is_circuit_broken=true`,後續 dispatch **跳過**此訂閱 |
    | Feature | Deactivated(`is_active=false`)subscription **不**派送 |
    | Feature | 同一訂閱訂多事件 → 每個事件各自產 delivery |
    | Feature | Payload > 512KB → log error,**不**建 WebhookEvent |
    | Feature | Secret rotation 過渡期內,舊 secret 的簽章驗證仍成功 |
    | Feature | `sequence` 單調遞增(連續三個事件 id 遞增) |
    | Integration | 跑 mock receiver(另起小 Laravel app 或 `ngrok`)驗端到端,含 idempotency |

14. **觀測性(Observability)**
    - 派送每次 attempt 寫結構化 log(event / attempt / status / http_code / duration_ms)
    - Laravel Telescope 或 Scout APM 掛勾(Filament 整合)
    - Dashboard Widget(見第六節)即時顯示失敗率

15. **文件**
    - API 文件給下游服務(Header 格式 / HMAC 驗簽範例 / Idempotency 規範 / Sequence 用法)
    - 放 `scheme/api/webhook_consumer_guide.md`

---

## 八、關鍵設計決策（面試會被問的）

| 問題 | 回答 |
| --- | --- |
| 為什麼用 queue 不直接送？ | HTTP 呼叫外部服務可能慢、可能失敗，絕對不能阻塞使用者請求。 |
| 為什麼要簽章？ | 接收端要能驗證請求真的來自 ez_crm、且 payload 沒被竄改。timestamp + body 一起簽可防重放。 |
| 為什麼指數退避？ | 對方服務掛了，一直重試只會雪上加霜。1m → 12h 給對方時間恢復，也避免自己 queue 塞爆。 |
| 為什麼存事件快照？ | 補送、稽核、bug 重現都需要。且 member 資料之後會變，不存快照無法還原當下狀態。 |
| 為什麼 Event / Subscription / Delivery 分開？ | 一個事件發生一次，但可能派送到多個訂閱者、每個訂閱者可能重試多次，是 1:N:N 關係。 |

---

## 九、未來擴充方向

- **Webhook 接收模組**：ez_crm 也要能接收外部 webhook（LINE、SendGrid、金流），可設計一個統一的 `IncomingWebhookController` + handler 分派機制。
- **Event versioning**：payload schema 變更時，在事件名稱加版本，例：`member.created.v2`。
- **Batch delivery**：高頻事件（如遊戲分數）可批次打包派送，降低接收端壓力。
- **Webhook replay**：提供時間區間的重新派送功能，對方升級/遷移後可用。

---

## 附錄：技術棧

- Laravel 10
- MySQL（JSON 欄位需要 5.7+）
- Redis 或 database queue driver
- `hash_hmac` (PHP 內建)
- `Illuminate\Support\Facades\Http` (Guzzle wrapper)
