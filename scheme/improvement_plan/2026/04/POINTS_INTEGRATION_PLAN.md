# Points 點數系統 整合計畫(Phase 2.1)

> 版本:v0.1 (DRAFT)
> 建立日期:2026-04-29
> 狀態:📋 Draft — 待 Kevin review 後升 v1.0
> 對應 Roadmap:SENIOR_ROADMAP Phase 2.1
> 預估實作:**5–7 個工作天**(Phase 2.1 範圍,不含 Coupon / Order)
> 預定起跑日:2026-05-06(W2 完成 + 緩衝後)
> 前置依賴:Phase 1 完成(Member CRUD + RBAC)✅ / W2 Static Analysis ✅

---

## 一、為什麼做(Context)

SENIOR_ROADMAP Phase 2 三大模組(Points / Coupon / Order)是「**展示『不是 CRUD』能力**」的 showcase 區。其中 Points 是最基礎、最容易暴露 senior 等級判斷的:

- ❌ Junior 寫法:`$member->points += 100; $member->save();` — 沒 transaction、沒 lock、沒 log
- ✅ Senior 寫法:Transaction + Pessimistic Lock + 冪等性 + 不允許負餘額 + audit log

Phase 2 順序:Points 先(獨立、簡單)→ Coupon(用 Points)→ Order(觸發 Points)。

---

## 二、範圍切割

### In scope(Phase 2.1)
- ✅ `members` 表加 `points` 欄位(快取當前餘額)
- ✅ 新增 `point_transactions` 表(audit log + 冪等控制)
- ✅ `PointService::adjust()` 處理 atomic 增減
- ✅ 2 個 admin endpoint(`GET /members/{uuid}/points` / `POST /.../points/adjust`)
- ✅ Idempotency key(client 帶 `Idempotency-Key` header)
- ✅ Pessimistic lock(`lockForUpdate`)
- ✅ 不允許扣到負數(自訂 `InsufficientPointsException`)
- ✅ Webhook event(`points.adjusted`,給下游 marketing 自動化用)
- ✅ Filament UI(read-only 顯示會員 points 跟交易紀錄)
- ✅ Feature tests:concurrent / over-spending / idempotency / negative amount edge

### Out of scope(留 Phase 2.5 / Phase 2.6)
- ❌ 點數過期機制(FIFO 扣 / TTL 清掃) — 業務複雜,獨立 phase
- ❌ Order 完成自動加點 — 等 Order Phase 才有觸發來源
- ❌ Coupon 用 Points 兌換 — 等 Coupon Phase
- ❌ 會員自己「spend points」前台 endpoint — 沒 Order 之前沒消費場景
- ❌ Bulk operations(批次加點數)— 行銷場景才需要
- ❌ 點數轉贈 / 退款補償 — 業務複雜度太高

---

## 三、Schema 設計

### 3.1 `members` 表加 `points`(快取欄位)

```php
Schema::table('members', function (Blueprint $table) {
    $table->bigInteger('points')->default(0)->after('status')
        ->comment('會員當前點數餘額(快取自 point_transactions 累計)');
    $table->index('points');  // 用於排行榜 / 查詢「點數 > N」
});
```

**為什麼快取在 members 表**:
- 多數查詢只要當前餘額(`/me` 顯示、訂單頁顯示可用點數)
- 算 `SUM(point_transactions)` 每次都掃 log 太貴
- 快取的代價是「**必須跟 transaction 同 atomic**」— 我們有 lockForUpdate 保證

### 3.2 `point_transactions` 表(主菜)

```php
Schema::create('point_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('member_id')->constrained()->cascadeOnDelete();

    $table->bigInteger('amount')
        ->comment('正:加點 / 負:扣點。單筆 transaction 不可為 0');
    $table->bigInteger('balance_after')
        ->comment('交易完成時的餘額快照,稽核用');

    $table->enum('type', ['earn', 'spend', 'adjust', 'expire', 'refund']);
    $table->string('reason', 200)
        ->comment('人類可讀說明,例:訂單 #123 完成 / 客服補償');

    // Idempotency: client 帶或系統生,確保同一邏輯操作不重複
    $table->string('idempotency_key', 64)->unique();

    // Audit:誰執行的(admin user id)
    $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('actor_type', 32)->default('user')
        ->comment('user / system / order');

    // Polymorphic 來源(Order / Coupon / Manual)
    $table->nullableMorphs('source');

    $table->json('meta')->nullable()
        ->comment('額外 context,例:order snapshot / 過期日');

    $table->timestamps();

    $table->index(['member_id', 'created_at']);
    $table->index(['type', 'created_at']);
});
```

**設計關鍵**:
1. **`amount` signed bigint** — 一筆 row 可能正可能負,不分兩個欄位
2. **`balance_after` snapshot** — 即使 `members.points` 被改也能還原歷史
3. **`idempotency_key` unique index** — DB 層保證冪等,不靠 application logic
4. **`type` enum 5 種** — 涵蓋所有可能來源,日後分析報表好分群
5. **`nullableMorphs('source')`** — Order / Coupon / Manual 都能掛,polymorphic
6. **`actor_id` + `actor_type`** — 區分人類 admin / 系統(cron / order) / 跨服務

---

## 四、API 規格

### 4.1 `GET /api/v1/members/{uuid}/points`(admin 用)

**Auth**:`auth:sanctum`(後台 admin)
**Permission**(spatie):`member.view`

**Response 200**:
```json
{
  "success": true,
  "code": "S200",
  "data": {
    "member_uuid": "8da9df...",
    "balance": 1250,
    "transactions": {
      "data": [
        {
          "id": 142,
          "amount": 100,
          "balance_after": 1250,
          "type": "earn",
          "reason": "訂單 #1024 完成",
          "actor_type": "order",
          "source_type": "App\\Models\\Order",
          "source_id": 1024,
          "created_at": "2026-04-29T11:00:00+08:00"
        }
      ],
      "current_page": 1, "last_page": 5, "total": 92
    }
  }
}
```

### 4.2 `POST /api/v1/members/{uuid}/points/adjust`(admin 用)

**Auth**:`auth:sanctum`
**Permission**:新增 `points.manage`(spatie permission baseline 加 → super_admin / admin)

**Request Header**:`Idempotency-Key: <uuid v4>` (required)

**Request Body**:
```json
{
  "amount": -50,
  "reason": "客服補償會員投訴 #ZD-9182",
  "type": "adjust"
}
```

**Validation**:
- `amount`: required, integer, not 0, abs <= 1_000_000(防呆,單筆超過要走另一個 endpoint 帶確認碼)
- `reason`: required, string, max 200
- `type`: in [earn, spend, adjust, refund](`expire` 由系統 cron 寫,不開 API)

**Response 200**:
```json
{
  "success": true,
  "code": "S200",
  "data": {
    "transaction_id": 142,
    "balance_before": 1300,
    "balance_after": 1250,
    "amount": -50
  }
}
```

**Response 422 + ApiCode**:
- `V003 OUT_OF_RANGE` — amount 超過單筆上限
- 新增 `B001 INSUFFICIENT_POINTS` — 扣點會變負數
- 新增 `B002 IDEMPOTENCY_REPLAY` — 同 key 已執行,回傳原 transaction 結果(**200 而非 4xx**,符合冪等語意)

### 4.3 `GET /api/v1/me/points`(前台會員自己看)

**Auth**:`auth:member`(member token)

範圍跟 4.1 一樣,但只能看自己的(controller 強制 `request->user()->uuid` 對應)。

---

## 五、關鍵設計決策

### 5.1 Atomic transaction(SENIOR_ROADMAP 已示範)

```php
class PointService
{
    public function adjust(Member $member, int $amount, string $reason, string $type, string $idempotencyKey, ?Model $source = null): PointTransaction
    {
        // 先檢查冪等(在 transaction 外快速失敗,不擋鎖)
        if ($existing = PointTransaction::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;  // 回傳原結果,不重做
        }

        return DB::transaction(function () use ($member, $amount, $reason, $type, $idempotencyKey, $source) {
            // 重新從 DB 抓 + 鎖定
            $locked = Member::lockForUpdate()->findOrFail($member->id);

            $newBalance = $locked->points + $amount;
            if ($newBalance < 0) {
                throw new InsufficientPointsException("Member {$locked->id} has {$locked->points}, cannot deduct {$amount}");
            }

            $tx = PointTransaction::create([
                'member_id'       => $locked->id,
                'amount'          => $amount,
                'balance_after'   => $newBalance,
                'type'            => $type,
                'reason'          => $reason,
                'idempotency_key' => $idempotencyKey,
                'actor_id'        => auth()->id(),
                'actor_type'      => 'user',  // TODO: detect system context
                'source_type'     => $source ? get_class($source) : null,
                'source_id'       => $source?->id,
            ]);

            $locked->update(['points' => $newBalance]);

            event(new PointAdjusted($locked, $tx));

            return $tx;
        });
    }
}
```

### 5.2 Idempotency 為什麼這樣設計

**選項 A**:client 帶 `Idempotency-Key` header(✅ 採用)
**選項 B**:server 用 (member_id, source_type, source_id, type) 自動生 — 不採用,因為 manual adjust 沒 source

**Replay 行為**:同 key 第二次 → 回 200 + 原 transaction(**不是 409 不是 422**)。RFC 7231 / Stripe 語意:冪等 = 重發無副作用,不需要 client 處理錯誤。

### 5.3 Pessimistic Lock vs Optimistic Lock

選 **Pessimistic**(`lockForUpdate`):
- Points 改動頻率低(不像庫存秒殺)
- 鎖時間極短(transaction 內幾個 SQL)
- Optimistic 需要 retry loop,程式碼複雜度高,且高並發下 retry 風暴更慢

### 5.4 為什麼 `members.points` 同 transaction 寫

**避免 dual-write 不一致**:transaction log + balance cache 必須同 commit/rollback,不能分開。`lockForUpdate` 把 row lock 住,寫完才釋放。

**rebuild 機制**:萬一 cache 壞了(理論上不該),`php artisan points:rebuild {member_uuid}` 從 transaction sum 重算 — 留作 cron / debug 工具。

### 5.5 Webhook event

`points.adjusted` event(對齊既有 webhook 體系):
```php
event(new PointAdjusted($member, $transaction));
```

payload 結構:
```json
{
  "event": "points.adjusted",
  "occurred_at": "2026-04-29T11:00:00+08:00",
  "data": {
    "member_uuid": "8da9df...",
    "amount": 100,
    "balance_after": 1350,
    "type": "earn",
    "reason": "訂單 #1024 完成"
  }
}
```

下游用途:行銷自動化(達 1000 點寄通知)/ 客服 dashboard / BI。

---

## 六、Test 策略

### 6.1 Unit / Feature Tests(必)
- `PointService::adjust` 正常路徑(加 / 減 / 0 拒絕)
- 餘額不足拋 `InsufficientPointsException`
- Idempotency key 重發回原 transaction
- API endpoint 422 / 403 / 404 各自 happy + edge case
- Permission 檢查(`points.manage`)沒授權 → 403

### 6.2 Concurrency Test(senior 訊號 — 必做)

使用 `pcntl_fork` 或 Laravel test helper 模擬同時 5 個 request 對同一個 member 扣點,驗證:
- 餘額永遠 >= 0
- transaction 數正確(不多不少)
- balance_after 連續正確(沒有兩筆 row 看到同一個 balance)

```php
public function test_concurrent_adjust_does_not_oversell()
{
    $member = Member::factory()->create(['points' => 100]);

    $promises = collect(range(1, 10))->map(fn ($i) =>
        Http::async()->post("/members/{$member->uuid}/points/adjust", [
            'amount' => -20,
            'reason' => "concurrent test {$i}",
            'type'   => 'spend',
        ], ['Idempotency-Key' => Str::uuid()])
    );

    Http::pool($promises);

    $member->refresh();
    $this->assertGreaterThanOrEqual(0, $member->points);
    // 5 個 -20 應該成功,5 個 InsufficientPointsException
    $this->assertEquals(5, PointTransaction::where('member_id', $member->id)->count());
}
```

> **CI 限制**:GitHub Actions 上 MySQL service 真的能模擬 lockForUpdate 競爭。Laravel `RefreshDatabase` 用 transaction wrap 整個 test 會破壞 lock 語意,**這個 test 要用 `DatabaseMigrations` trait 而非 `RefreshDatabase`**。

### 6.3 Idempotency replay 測試

```php
public function test_same_idempotency_key_returns_same_transaction()
{
    $key = Str::uuid();
    $r1 = $this->postJson(...,['Idempotency-Key' => $key]);
    $r2 = $this->postJson(...,['Idempotency-Key' => $key]);

    $this->assertEquals($r1['data']['transaction_id'], $r2['data']['transaction_id']);
    $this->assertEquals(1, PointTransaction::count());  // 只 1 row
}
```

---

## 七、實作順序與估時

| 階段 | 任務 | 估時 |
|---|---|---|
| **Day 1** | Schema migration + Model + Factory | 2-3h |
| **Day 1** | `PointService::adjust` + `InsufficientPointsException` | 2-3h |
| **Day 2** | API endpoints(2 個 admin + 1 個 me)+ FormRequest | 3-4h |
| **Day 2** | Idempotency middleware / helper | 1-2h |
| **Day 3** | Webhook event + DispatchWebhook listener | 1h |
| **Day 3** | Filament UI(read-only Resource)| 2-3h |
| **Day 3** | spatie permission `points.manage` + Policy | 1h |
| **Day 4** | Feature tests:正常 / edge / 403 / 422(~10 cases)| 3-4h |
| **Day 4** | Concurrency test(2-3 cases)| 2h |
| **Day 5** | Bug fix + ApiCode 補(B001 / B002)+ doc | 2-3h |
| **Day 5** | ADR-0004 寫 atomic / idempotency 決策 | 1h |
| **Day 5** | session log + commit + push | 1h |
| **buffer** | edge case 處理 + review | 4-6h |
| **合計** | | **~5-7 個工作天** |

---

## 八、潛在風險與踩坑預想

| 風險 | 預防 |
|---|---|
| Concurrency test 在 SQLite-memory 下不會真的 lock | 強制用 MySQL(local + CI 都是)|
| Idempotency key 太短被破解 | 強制 client 帶 UUID v4(64 char unique)|
| `auth()->id()` 在 system context(cron / order)是 null | `actor_type` enum + null 允許 → 還原時不混亂 |
| 過期機制 future 加進來時改 schema | 先預留 `meta` JSON 存 expires_at,future 加 `expired_at` 欄位 |
| `PointTransaction` 數據增長失控 | 加 `->index(['member_id', 'created_at'])`;Phase 3 上 partition by month |
| Filament Resource 改 admin point 不走 Service | Resource 的 save 方法 override 改 call PointService,不直接 update model |

---

## 九、跟 Phase 2 後續模組的接點

### Coupon(Phase 2.2 預留)
- Coupon 兌換消耗點數 → 走 `PointService::adjust(amount: -X, type: 'spend', source: $coupon)`
- 退款 → `type: 'refund'` 反向 transaction(不是改原 row)

### Order(Phase 2.3 預留)
- Order 完成 → `PointService::adjust(amount: floor(total / 100), type: 'earn', source: $order)`
- Order 取消 → `type: 'refund'` + 原 transaction 的反向

### Outbox / 跨服務(Phase 3+)
- `point_transactions` 已是 audit log,可以是事件 outbox 的 source
- Phase 3 Queue 後做 async webhook 不卡 transaction commit

---

## 十、驗收條件

- [ ] Schema migration 跑得起來(local + CI)
- [ ] `PointService::adjust` 8+ unit tests 全綠
- [ ] 2 admin + 1 me endpoint feature tests 全綠
- [ ] Concurrency test:10 並發,5 成 5 敗,餘額永遠 >= 0
- [ ] Idempotency replay 測試:同 key 兩次回同 transaction
- [ ] PHPStan 不破 baseline(level 5 不增加 ignore)
- [ ] ESLint(前端)新增 Points 顯示 UI 不破 lint
- [ ] Filament UI 顯示交易紀錄、過濾器、排序
- [ ] webhook subscriber 能收到 `points.adjusted` event
- [ ] Permission:`customer_support` 不能 adjust(沒 `points.manage`)
- [ ] ADR-0004 寫完歸檔 work_log/

---

## 十一、不在 v0.1 範圍但**待 review 對齊**

請 Kevin 在這幾個決策上 thumbs up / 修正:

1. **`amount` 用 `bigInteger` 不用 `decimal`** — 點數是「整數」單位(沒 0.5 點),節省 storage + 索引。OK?
2. **預設 1_000_000 單筆上限** — 防呆,但 marketing 大方送 100 萬點 / 客戶會員 case 怎麼辦?(走另一個確認 endpoint?)
3. **`points.manage` permission** 給 super_admin + admin,**不給 customer_support / marketing** — 因為這是「動錢/類錢」操作,需要 audit 等級高的角色。OK?
4. **不開「會員自己 spend」的 me endpoint** — 等 Order/Coupon 才有業務場景。OK?
5. **`PointAdjusted` event 不帶 actor 個資**(只 member_uuid + balance + reason)— 隱私考量。OK?
6. **過期機制完全不做** — 業務上你的 ez_crm 客戶會員需要嗎?如要,馬上加 schema 的 `expires_at` 欄位,但 logic 留 Phase 2.5。

回答這 6 個 → v0.1 升 v1.0 進實作。

---

## References

- SENIOR_ROADMAP.md Phase 2.1 段(line 85-115)
- ADR-0001(RBAC baseline)
- ADR-0002(後端 PHPStan baseline)
- 既有 webhook 體系(WebhookSubscription / Event / Delivery)
- spatie/laravel-permission v6
- Stripe Idempotency 文件(設計參考):https://stripe.com/docs/api/idempotent_requests
- RFC 7231 §4.2.2(idempotent methods 定義)
