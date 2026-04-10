# ez_crm — Senior Backend Engineer 養成路線圖

> 撰寫日期：2026-04-09  
> 目標：以 ez_crm 為載體，在 8-12 週內建構出符合 Senior Backend Engineer 面試標準的完整作品集  
> 策略：不做 50 支 CRUD 搬家，做 8-10 支精選 API，每支做到 Senior 水準

---

## 一、現狀評估

### 已驗證的能力（來自 linky_360 + ez_crm 雙專案證據）

| 能力 | 等級 | 證據 |
|------|------|------|
| PHP 核心 | 高 | vanilla PHP 底層 + Laravel 框架雙棲 |
| 架構設計 | 高 | Proxy Pattern、Service Layer、Strangler Fig 遷移策略 |
| 安全意識 | 高 | PdoGuardProxy 四層防禦（底層）+ Sanctum（框架）|
| 資料庫 | 高 | 手寫 SQL 防護 + Eloquent ORM + Migration + Seeder |
| 測試 | 中高 | attack_harness（手寫）+ PHPUnit Feature/Unit 雙層 |
| API 設計 | 中高 | RESTful + 版本控制 + Swagger + 正確 HTTP status code |
| DevOps | 中 | GitHub Actions CI + Git Flow |
| 文件能力 | 中高 | schema doc + API plan + improvement plan + README |

### 缺口分析

| 能力 | 現狀 | Senior 要求 |
|------|------|------------|
| 複雜業務邏輯 | ez_crm 目前只有 1 支 search API | 需展示 transaction、狀態機、冪等性、併發防護 |
| Docker 生產環境 | Sail 已裝但未深度使用 | 需要 production-grade 的 docker-compose |
| 快取策略 | 無 | Redis cache + cache invalidation |
| Queue / 非同步 | 無 | Laravel Queue + 失敗重試 + Dead Letter |
| 監控/可觀測性 | 無 | Health check + Telescope + 結構化日誌 |
| Rate Limiting | 未啟用 | 分層限流策略 |
| 效能調校 | 無 | 大量資料壓測 + Slow query 分析 + Index 優化 |
| RBAC 權限控制 | 僅 `auth:sanctum` | 角色 + 權限 + 資源層級控制 |

---

## 二、四階段實施計畫

### Phase 1：核心 CRUD + 安全（2-3 週）

**目標：** 完成 Members 完整 CRUD + RBAC 權限控制

#### 1.1 Members CRUD

| Endpoint | Method | 說明 | Senior 考點 |
|----------|--------|------|------------|
| `/api/v1/members/search` | GET | ✅ 已完成 | — |
| `/api/v1/members` | POST | 建立會員 | FormRequest 驗證、UUID 自動產生、Email/Phone 唯一性 |
| `/api/v1/members/{uuid}` | GET | 取得單一會員 | Route Model Binding by UUID（不暴露 auto-increment ID）|
| `/api/v1/members/{uuid}` | PUT | 更新會員 | Partial update（PATCH 語意）、Optimistic Lock（optional）|
| `/api/v1/members/{uuid}` | DELETE | 軟刪除會員 | Soft Delete + 確認是否有關聯資料（點數、訂單）|

#### 1.2 RBAC 權限控制

- 安裝 `spatie/laravel-permission`
- 設計角色：`super-admin`、`admin`、`operator`、`viewer`
- 設計權限：`member.create`、`member.update`、`member.delete`、`member.view`、`points.manage`、`coupon.manage`
- 在 Controller 中用 `$this->authorize()` 或 Middleware 做權限檢查
- 撰寫測試：驗證不同角色的存取權限

#### 1.3 測試要求

- 每支 endpoint 至少 5 個 test case（happy path + 驗證失敗 + 未授權 + 權限不足 + edge case）
- 維持 PHPUnit Feature + Unit 雙層結構

#### 1.4 交付物

```
app/Http/Controllers/Api/V1/MemberController.php  （完整 CRUD）
app/Http/Requests/Api/V1/Member*Request.php        （每個 action 獨立 FormRequest）
app/Policies/MemberPolicy.php                      （授權策略）
database/seeders/RolePermissionSeeder.php           （角色權限 seed）
tests/Feature/Api/V1/Member*.php                   （CRUD + 權限測試）
Swagger 文件更新
```

---

### Phase 2：業務複雜度（3-4 週）

**目標：** 實作點數、優惠券、訂單三大模組，展示「不是 CRUD」的能力

#### 2.1 Points 點數系統

**核心概念：** 點數增減必須是 atomic 操作，不能出現扣到負數或併發重複加點

| Endpoint | Method | 說明 |
|----------|--------|------|
| `/api/v1/members/{uuid}/points` | GET | 取得會員總點數 + 明細 |
| `/api/v1/members/{uuid}/points/adjust` | POST | 增減點數（管理端）|

**Senior 考點：**

```php
// Transaction + Pessimistic Lock 範例架構
DB::transaction(function () use ($memberId, $points, $reason) {
    $member = Member::lockForUpdate()->findOrFail($memberId);
    
    if ($member->points + $points < 0) {
        throw new InsufficientPointsException();
    }
    
    $member->increment('points', $points);
    
    PointLog::create([
        'member_id' => $memberId,
        'points'    => $points,
        'balance'   => $member->fresh()->points,
        'reason'    => $reason,
        'idempotency_key' => $idempotencyKey,  // 冪等性
    ]);
});
```

**測試重點：**
- 併發測試：兩個 request 同時扣點，最終餘額正確
- 冪等性測試：同一個 `idempotency_key` 重複送不會重複扣點
- 邊界測試：扣到剛好 0、扣到負數被擋

#### 2.2 Coupon 優惠券系統

**核心概念：** 優惠券有明確的狀態機，且同一張券不能被重複核銷

| Endpoint | Method | 說明 |
|----------|--------|------|
| `/api/v1/coupons` | POST | 建立優惠券批次 |
| `/api/v1/coupons/{code}/verify` | POST | 驗證優惠券有效性 |
| `/api/v1/coupons/{code}/redeem` | POST | 核銷優惠券 |
| `/api/v1/coupons/{code}/cancel` | POST | 取消核銷 |

**狀態機設計：**

```
           ┌─── verify ───┐
           │               │
  [created] ──── redeem ──── [redeemed] ──── cancel ──── [cancelled]
       │                                                       │
       │                         redeem                        │
       └──────────────────── [expired] ←── (排程自動過期) ──────┘
                                  ✗ 不可核銷
```

**Senior 考點：**
- 狀態轉換驗證（expired 的券不能 redeem、已 redeemed 的不能再 redeem）
- Race condition：兩個 request 同時核銷同一張券 → 只有一個成功
- 使用 `lockForUpdate()` 或 unique constraint 防止重複核銷

**測試重點：**
- 每個狀態轉換的合法/非法路徑
- 過期券的自動處理
- 併發核銷防護

#### 2.3 Order 訂單系統

**核心概念：** 訂單建立後觸發一連串非同步事件（點數計算、通知發送）

| Endpoint | Method | 說明 |
|----------|--------|------|
| `/api/v1/orders` | POST | 建立訂單 |
| `/api/v1/orders/{uuid}` | GET | 查詢訂單 |
| `/api/v1/orders/{uuid}/cancel` | POST | 取消訂單 |

**Event-Driven 架構：**

```
OrderController::store()
    └── OrderCreated Event
            ├── CalculatePointsListener  → 加點（走 Queue）
            ├── SendNotificationListener → 發通知（走 Queue）
            └── UpdateInventoryListener  → 更新庫存（走 Queue）
```

**Senior 考點：**
- 使用 Laravel Event / Listener 解耦業務邏輯
- Listener 走 `ShouldQueue`，實現非同步處理
- Queue 失敗重試機制 + `failed()` method 錯誤處理
- 訂單取消時的反向操作（扣回點數、釋放庫存）

**測試重點：**
- Event 有被正確 dispatch
- Queue Job 失敗後重試行為
- 訂單取消的反向邏輯一致性

#### 2.4 交付物

```
app/Services/PointService.php                   （點數服務 + transaction + lock）
app/Services/CouponService.php                  （優惠券狀態機）
app/Services/OrderService.php                   （訂單服務）
app/Events/OrderCreated.php                     （訂單事件）
app/Listeners/CalculatePointsListener.php       （點數計算 listener）
app/Listeners/SendNotificationListener.php      （通知 listener）
app/Exceptions/InsufficientPointsException.php  （業務例外）
app/Exceptions/InvalidCouponStateException.php  （狀態機例外）
database/migrations/                            （points, coupons, orders 相關 table）
tests/Feature/Api/V1/Points*.php
tests/Feature/Api/V1/Coupon*.php
tests/Feature/Api/V1/Order*.php
tests/Unit/Services/CouponStateMachineTest.php
Swagger 文件更新
```

---

### Phase 3：基礎設施（2-3 週）

**目標：** 讓專案具備生產環境的基本要求

#### 3.1 Docker 完整環境

建立 `docker-compose.yml`：

```yaml
# 目標架構
services:
  app:        # PHP-FPM 8.2 + Laravel
  nginx:      # Reverse proxy
  mysql:      # 資料庫
  redis:      # Cache + Queue driver
  mailhog:    # 開發用 email 測試
```

**交付物：**
- `Dockerfile`（production-grade，multi-stage build）
- `docker-compose.yml`
- `docker-compose.dev.yml`（開發環境覆蓋）
- `.dockerignore`
- `README.md` 加入 Docker 啟動說明

#### 3.2 Redis Cache

| 場景 | 快取策略 | TTL |
|------|---------|-----|
| Member search（熱門查詢）| Cache-aside | 5 分鐘 |
| Member detail | Cache-aside | 10 分鐘 |
| Coupon verify | Cache-aside | 30 秒 |
| 點數餘額 | Write-through（點數變動時更新 cache）| 1 小時 |

**Cache Invalidation 策略：**
- Member 更新/刪除時清除相關 cache key
- 使用 Cache Tags 做批次清除
- 使用 Model Observer 自動觸發 cache 清除

```php
// 範例：MemberObserver
class MemberObserver
{
    public function updated(Member $member): void
    {
        Cache::tags(['members'])->forget("member:{$member->uuid}");
    }
}
```

#### 3.3 Laravel Queue

- 設定 Redis 為 Queue driver
- 建立 `failed_jobs` table
- 為 Phase 2 的 Event Listener 加上 `ShouldQueue`
- 設定 retry 策略：`$tries = 3`、`$backoff = [10, 60, 300]`
- 建立 `php artisan queue:work` 的 Supervisor 配置

#### 3.4 Rate Limiting

```php
// RouteServiceProvider 或 bootstrap/app.php
RateLimiter::for('api', function (Request $request) {
    return [
        Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        Limit::perDay(10000)->by($request->user()?->id ?: $request->ip()),
    ];
});

// 特定高風險 endpoint
RateLimiter::for('points-adjust', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()->id);
});
```

#### 3.5 Health Check + 基本監控

```php
// GET /api/health
Route::get('/health', function () {
    return response()->json([
        'status'   => 'ok',
        'db'       => DB::connection()->getPdo() ? 'connected' : 'failed',
        'redis'    => Cache::store('redis')->get('health') !== false || Cache::store('redis')->put('health', true, 10),
        'queue'    => Queue::size('default'),
        'version'  => config('app.version'),
        'time'     => now()->toIso8601String(),
    ]);
});
```

- 安裝 Laravel Telescope（開發環境用）
- 結構化日誌：`Log::channel('daily')->info('order.created', ['order_id' => $id])`

#### 3.6 交付物

```
docker-compose.yml
docker-compose.dev.yml
Dockerfile
.dockerignore
docker/nginx/default.conf
docker/supervisor/laravel-worker.conf
app/Observers/MemberObserver.php
app/Console/Commands/HealthCheckCommand.php
config/cache.php（Redis 設定）
config/queue.php（Redis 設定）
```

---

### Phase 4：證明能扛規模（1-2 週）

**目標：** 用數據證明系統效能，這是 Senior 面試的殺手鐧

#### 4.1 資料填充

```bash
# 建立大量假資料 Seeder
php artisan make:seeder MassDataSeeder
```

- 50 萬筆 Members
- 100 萬筆 Point Logs
- 20 萬筆 Coupons
- 10 萬筆 Orders
- 使用 `chunk` + `insert`（非 `create`）避免 OOM

#### 4.2 壓力測試

使用 k6 或 Apache Bench：

```bash
# k6 範例
k6 run --vus 50 --duration 60s load-test.js
```

測試目標：

| Endpoint | 目標 RPS | 目標 P99 |
|----------|---------|---------|
| GET /api/v1/members/search | 500+ | < 200ms |
| GET /api/v1/members/{uuid} | 1000+ | < 50ms |
| POST /api/v1/members/{uuid}/points/adjust | 100+ | < 500ms |

#### 4.3 效能調校

- `EXPLAIN ANALYZE` 檢查 slow query
- 加上缺失的 composite index
- 對比：無 cache vs 有 Redis cache 的 latency 差異
- 對比：N+1 query vs eager loading 的差異

#### 4.4 效能報告

在 `scheme/performance/` 下撰寫報告：

```markdown
# 效能調校報告

## 環境
- Docker: PHP 8.2 + MySQL 8.0 + Redis 7
- 資料量: Members 50 萬 / Point Logs 100 萬

## 測試結果

### Member Search API
| 版本 | RPS | P50 | P95 | P99 |
|------|-----|-----|-----|-----|
| v1（無 cache、無 index 優化）| 120 | 89ms | 340ms | 890ms |
| v2（加 composite index）| 380 | 32ms | 85ms | 210ms |
| v3（加 Redis cache）| 850 | 8ms | 22ms | 48ms |

### 調校措施
1. 加上 (status, member_group_id, created_at) composite index
2. Member search 熱門查詢加 Redis cache (TTL 5min)
3. Eager loading 消除 N+1 (with(['group', 'tags', 'profile']))
```

#### 4.5 交付物

```
database/seeders/MassDataSeeder.php
tests/Performance/load-test.js              （k6 腳本）
scheme/performance/PERFORMANCE_REPORT.md    （效能調校報告）
scheme/performance/screenshots/             （壓測截圖）
```

---

## 三、面試武器清單

完成四個 Phase 後，你在面試時可以展示：

### 技術故事

| 問題 | 你的回答 |
|------|---------|
| 「介紹一下你的架構經驗？」 | linky_360 Strangler Fig 故事 + ez_crm Service Layer + Event-Driven |
| 「你怎麼處理併發？」 | 點數系統 `lockForUpdate()` + 冪等性 key + 單元測試驗證 |
| 「你怎麼做快取？」 | Redis cache-aside + Model Observer 自動 invalidation + TTL 策略 |
| 「你的測試策略？」 | PHPUnit Feature（API 層）+ Unit（Service 層）+ 壓測（k6）三層 |
| 「你怎麼部署？」 | Docker multi-stage build + docker-compose + Supervisor queue worker |
| 「你怎麼處理效能問題？」 | 50 萬筆壓測 → EXPLAIN ANALYZE → composite index → Redis cache → P99 < 200ms |
| 「你怎麼處理 legacy 系統？」 | Strangler Fig：先嵌入 → 測試 → 抽離 → 82 檔零破壞，9 天完成 |

### 數字

```
「我的 CRM API 在 50 萬會員資料下：
  - Search API: 850 rps, P99 < 50ms（有 Redis cache）
  - Points adjust: 100 rps, 零 race condition（pessimistic lock + 冪等性）
  - 測試覆蓋: 80%+ (Feature + Unit + 壓測三層)
  - Docker 一鍵啟動: docker-compose up -d」
```

---

## 四、時程總覽

```
Week 1-3    Phase 1  Members CRUD + RBAC
Week 4-7    Phase 2  Points + Coupon + Order（業務複雜度）
Week 8-10   Phase 3  Docker + Redis + Queue + Rate Limit
Week 11-12  Phase 4  50 萬筆壓測 + 效能報告

            ┃ 可開始投履歷
            ▼
Week 12+    面試準備：整理技術故事 + 練口說
```

---

## 五、不需要做的事

| 不需要 | 原因 |
|--------|------|
| 搬 linky360 全部 76 支 API | 數量不代表能力，10 支做到深就夠了 |
| BigQuery 整合 | 太貴且面試不會問，改用 MySQL + Redis 證明效能即可 |
| 前端 SPA | 你定位後端，Swagger UI 就是最好的「前端」|
| 微服務拆分 | 單體做好比硬拆微服務有說服力 |
| Kubernetes | Docker Compose 已足夠，K8s 是 DevOps/SRE 的活 |

---

## 六、參考資源

| 主題 | 資源 |
|------|------|
| Laravel 進階 | [Laravel Beyond CRUD](https://laravel-beyond-crud.com/) — Service Layer、Action、DTO |
| 系統設計面試 | [System Design Primer](https://github.com/donnemartin/system-design-primer) |
| 壓測工具 | [k6 官方文件](https://k6.io/docs/) |
| Docker 最佳實踐 | [Docker PHP Best Practices](https://github.com/serversideup/docker-php) |
| RBAC | [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission) |
| 狀態機 | [Spatie Laravel Model States](https://spatie.be/docs/laravel-model-states) |
