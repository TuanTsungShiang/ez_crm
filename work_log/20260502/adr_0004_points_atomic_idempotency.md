# ADR-0004: Points 系統原子性、冪等性與並發防護設計

## Status
Accepted (2026-05-02)

## Context

Points（點數）系統涉及金融語意的餘額增減。相比一般 CRUD，它有三個核心挑戰：

1. **并發（Concurrency）**：多個 request 同時扣點可能導致餘額變成負數
2. **冪等性（Idempotency）**：網路重傳、前端重送可能讓同一筆交易被執行多次
3. **稽核（Audit）**：每次餘額變動必須留下不可刪除的 log，且 log 的 `balance_after` 必須連續正確

## Decision

### 1. Pessimistic Lock（悲觀鎖）而非 Optimistic Lock

**選擇**：`Member::lockForUpdate()->findOrFail($id)` 在 DB transaction 內鎖定 row。

**為什麼不用 Optimistic Lock**：
- Optimistic lock 需要 retry loop（version 欄位 CAS）
- Points 改動頻率低（不像庫存秒殺），鎖持有時間極短（幾個 SQL）
- Optimistic 在高並發下 retry 風暴反而更慢
- `lockForUpdate` 語意明確、程式碼簡單

**為什麼不直接 `increment('points', $amount)`**：
- 無法在同一 transaction 內寫 `balance_after` snapshot（需要先知道新餘額）
- 無法在 lock 保護下做「餘額不可為負」的業務檢查

### 2. 雙層冪等防護

**第一層（Fast-fail，transaction 外）**：
```php
if ($existing = PointTransaction::where('idempotency_key', $key)->first()) {
    return $existing;
}
```
- 先在 transaction 外查，避免 replay 請求佔鎖
- 99% 的 replay case 在這裡被擋下，不影響其他真正在寫的 transaction

**第二層（TOCTOU 防線，DB UNIQUE constraint）**：
```php
} catch (QueryException $e) {
    if (str_contains($e->getMessage(), '23000')) { // unique violation
        $tx = PointTransaction::where('idempotency_key', $key)->firstOrFail();
        // treat as replay
    }
}
```
- 兩個并發 request 同時通過 fast-fail（都看到 null），進入 transaction
- 第一個成功 insert，第二個撞上 DB unique constraint
- 「輸家」catching QueryException 並回傳「贏家」的結果
- 語意：replay 永遠回傳 200（不是 409），符合 RFC 7231 冪等語意

**為什麼 replay 回傳 200 而非 409**：
- RFC 7231 §4.2.2：幂等請求的重發不應有副作用，也不應要求 client 處理錯誤
- Stripe / PayPal 的 Idempotency-Key 實作慣例：replay → 200 + 原結果
- client 無需特別 handle replay case，retry 邏輯更簡單

### 3. `balance_after` Snapshot 在 transaction log

每筆 `PointTransaction` 都記錄 `balance_after`（交易完成後的餘額快照）。

**為什麼**：
- 即使 `members.points` 快取欄位因 bug 被錯誤改寫，仍能從 log 還原歷史
- `SUM(amount)` 可以驗算 `balance_after` 的一致性（concurrency test 覆蓋此路徑）
- 稽核時「第 N 筆交易當下的餘額是多少」一眼可見，不需回算

### 4. `members.points` 快取欄位與 Transaction 同 commit

```php
DB::transaction(function () {
    $locked = Member::lockForUpdate()->findOrFail($id);
    $tx = PointTransaction::create([...]);
    $locked->update(['points' => $newBalance]);  // 同 commit
    event(new PointAdjusted($locked, $tx));
});
```

**為什麼快取在 members 表**：
- 多數 API（`/me`、訂單頁）只要當前餘額，每次都 SUM 太貴
- `lockForUpdate` 保證 cache 與 log 同 atomic，不會 dual-write 不一致

**rebuild 安全網**：萬一 cache 壞了，`php artisan points:rebuild {uuid}` 從 SUM(amount) 重算（預留，未實作）。

### 5. `User::getDefaultGuardName()` 固定返回 `'web'`

Spatie Laravel Permission 在解析 `$user->can($permission)` 時，會從當前 auth guard context 推斷要查哪個 guard 的 permissions。當 API route 使用 `auth:sanctum` middleware，Spatie 會嘗試查 guard='sanctum' 的 permissions，但所有 permissions/roles 是以 guard_name='web' 建立的（對應 Filament admin 使用的 web session guard）。

**解法**：在 `User` model 覆寫 `getDefaultGuardName()` 固定返回 `'web'`，讓 Spatie 不管 auth 來源，永遠查 web guard 的 permissions。

**代價**：若未來需要針對 sanctum guard 設定不同 permission，這個覆寫需要一起調整。當前沒有這個需求。

## Consequences

**正面**：
+ 所有路徑（正常、replay、TOCTOU race）都有 test coverage
+ 稽核 log 完整，`balance_after` 連續可驗算
+ `members.points` 快取讓 read-heavy 場景高效
+ 冪等設計讓前端可以安全 retry 而不需要額外邏輯

**負面 / 注意**：
- `lockForUpdate` 在高并發下會產生 lock queue（但 points 操作頻率低，可接受）
- `balance_after` 是 denormalized 資料，與 `members.points` 可能短暫不一致（僅在 bug 情況，concurrency test 保護）
- Idempotency-Key 由 client 負責生成 UUID v4，若 client 沒帶 header → 422 明確拒絕

## Alternatives Considered

| 選項 | 為何不採用 |
|---|---|
| Optimistic lock（version 欄位）| retry 複雜，高并發下 retry storm 更慢 |
| Redis 原子 INCR/DECR | 引入額外基礎設施依賴，且難以保證 DB 與 Redis 雙寫一致 |
| 不記 balance_after | 失去稽核 snapshot，回算需全表 SUM |
| 409 Conflict for replay | 違反 RFC 7231 冪等語意，client 需額外 error handling |
| 分開 sanctum/web permissions | Spatie 需要雙倍 permission rows，Seeder 複雜度倍增 |

## References

- `app/Services/Points/PointService.php` — 實作主體
- `tests/Unit/Services/PointServiceTest.php` — 單元測試（10 cases）
- `tests/Feature/Api/V1/PointsConcurrencyTest.php` — 并發驗證（4 cases）
- `tests/Feature/Api/V1/MemberPointsTest.php` — API feature tests（15 cases）
- RFC 7231 §4.2.2（idempotent methods）
- Stripe Idempotency 文件（replay 回傳 200 的設計參考）
