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
核心路徑（必做）
─────────────────
Week 1-3    Phase 1  Members CRUD + RBAC
Week 4-7    Phase 2  Points + Coupon + Order（業務複雜度）
Week 8-10   Phase 3  Docker + Redis + Queue + Rate Limit
Week 11-12  Phase 4  50 萬筆壓測 + 效能報告

            ┃ 可開始投履歷（鎖定 Senior PHP / Laravel Backend 職位）
            ▼
Week 12+    面試準備：整理技術故事 + 練口說

延伸路徑（依職涯方向選做）
──────────────────────────
Week 13-16  Phase 5  DevOps / Platform Engineer 證明（選做）
                     → 多打開一條職涯路：Platform Engineer / Full-Stack
Week 17-20  Phase 6  前端能力 showcase（選做）
                     → 多打開一條職涯路：Full-Stack Engineer
```

---

## 五、Phase 5：DevOps / Platform Engineer 證明（選做）

### 為什麼這 Phase 對你特別有價值

你已經在公司做了業界少見的事情：

| 現有實戰經驗（git 沒有，但你做過） | 證據 |
|-----------------------------------|------|
| VM 設定 | 自己處理過 |
| Plesk 控制台設定 | 自己摸出來的 |
| Cloudflare DNS / WAF / 邊緣設定 | 部分自學 |
| Google Ads API 整合 | linky-360-tools/googleadapi/ 用自己 Google 帳號試 |
| Google Tag Manager 測試頁建構 | linky-360-tools/UTM_GtmTestPage.php |
| Facebook Open Graph 除錯 | island_tales 解過手機 FB 分享 deep link 劫持 |

**業界實際情況：** 一般 PHP Senior 工程師不會 infra，一般 DevOps 不會深度 PHP。**你剛好兩邊都會。** 這在台灣市場是稀缺組合。

### 5.1 把實戰經驗轉成可展示資產

**問題：** 你做過這些事，但 git 上沒有證據。面試官無法驗證。

**解法：** 把它們從「腦袋裡的經驗」變成「ez_crm 上的文件 + 配置檔」。

#### 任務 1：在 ez_crm 寫一份完整的部署文件

```
ez_crm/scheme/deployment/
├── 01_vm_provisioning.md        VM 從 0 到能跑 PHP 的步驟
├── 02_plesk_setup.md            Plesk 上部署 Laravel 的眉角
├── 03_cloudflare_config.md      DNS、Cache Rule、Page Rule、WAF 設定
├── 04_google_oauth_setup.md     從申請 Google Cloud 專案到拿到 client_secret
├── 05_google_ads_api_integration.md  Google Ads API 對接流程
├── 06_meta_tags_og_debug.md     OG / Twitter Card 除錯流程（FB Sharing Debugger 用法）
└── 07_disaster_recovery.md      備份與災難復原 SOP
```

每份文件包含：
- 操作步驟
- 踩過的坑與解法
- 截圖（隱去敏感資訊）
- 與 Laravel/PHP 的整合點

**時間：** 1 週（每天寫 1 份）

#### 任務 2：建立完整的 docker-compose 生產級堆疊

不只是 Phase 3 的 dev 環境，要做 production-grade：

```yaml
# docker-compose.production.yml 概念
services:
  app:        # PHP 8.2-FPM + Laravel
  nginx:      # 含 Cloudflare real-ip 設定
  mysql:      # 含 backup script
  redis:      # cache + queue + session
  queue:      # supervisor 跑 queue worker
  scheduler:  # cron 跑 schedule:run
  
networks:
  internal:   # 只有 app/db/redis 在這
  public:     # nginx 在這
  
volumes:
  mysql_data:
  redis_data:
```

**包含：**
- Multi-stage Dockerfile（builder / production 兩階段）
- Nginx config 含 Cloudflare 真實 IP 處理
- Supervisor 配置（queue worker + scheduler）
- 環境變數分離（.env.production / .env.staging）
- Backup script + 還原 script

**時間：** 1 週

#### 任務 3：寫一篇技術 blog 講「我如何在 Plesk + Cloudflare 環境下部署 Laravel」

這是 Senior Platform Engineer 必殺技——**用「真實環境踩坑經驗」打敗其他「只在 docker 上跑過」的競爭者**。

**內容大綱：**
- Plesk 與 Laravel 的相容性陷阱
- Cloudflare 後 IP 處理（trustedproxies）
- Plesk Cron 與 Laravel Scheduler 整合
- Cloudflare Page Rules 與 Laravel 路由衝突
- 為什麼你選擇 Plesk 而不是純 Docker（業務考量）

**時間：** 3 天

#### 任務 4：補一個面試故事

```
「我曾經在沒有 mentor 的情況下，
 自己摸索出 VM + Plesk + Cloudflare + Laravel 的部署架構。
 過程中踩過 7 個坑（列出來），
 最後寫成 SOP 文件給後續維護的人。
 
 我相信 DevOps 不是『另一個專業』，
 是 backend engineer 應該具備的『環境理解能力』。」
```

### 5.2 Phase 5 交付物

```
ez_crm/
├── scheme/deployment/                  7 份部署文件
├── docker/
│   ├── Dockerfile                      multi-stage
│   ├── docker-compose.dev.yml
│   ├── docker-compose.production.yml
│   ├── nginx/cloudflare-realip.conf
│   └── supervisor/laravel-worker.conf
└── scheme/blog_drafts/
    └── plesk_laravel_cloudflare_deployment.md
```

### 5.3 Phase 5 多打開的職涯路

| 職位 | 薪資（台灣）| 為什麼你是好人選 |
|------|-----------|---------------|
| **Platform Engineer (PHP/Laravel)** | 95-130K | 後端 + DevOps 雙棲，業界稀缺 |
| **DevOps Engineer (with PHP background)** | 90-120K | 懂 PHP 應用層的痛點，能精準調效能 |
| **SRE for PHP Stack** | 100-140K | 同上，更偏可靠性工程 |

---

## 六、Phase 6：前端能力 showcase（選做）

### 為什麼這 Phase 對你也有價值

你在 island_tales 證明了你能寫前端：

| 你的前端實戰證據 |
|---------------|
| 269 commits / 156K 行程式碼 |
| HTML / CSS / JS / Bootstrap / Swiper / jQuery |
| **解決過手機 FB 分享 deep link 劫持閃退**（業界惡名昭彰的 bug）|
| **修正 OG image 在 FB / Twitter 分享無縮圖** |
| `navigator.share()` 跨平台處理 |
| Facebook Sharing Debugger 排查經驗 |
| 響應式 RWD（jianhow 282 commits 是搭檔，所以你不是一個人扛但有實戰經驗）|

**業界實際情況：** 純後端工程師很多，純前端工程師很多，但「會解 FB Open Graph deep link 劫持」這種跨領域 debug 能力的人很少。

### 6.1 把實戰經驗轉成展示資產

#### 任務 1：在 ez_crm 加一個簡易 admin SPA

不需要 React/Vue（學習成本高）。**用 Alpine.js + Tailwind + Blade**——這是 Laravel 生態最簡單的前端組合：

```
ez_crm/resources/views/admin/
├── members/
│   ├── index.blade.php      列表（含 Alpine.js 排序、篩選）
│   ├── show.blade.php       單筆檢視
│   └── form.blade.php       新增/編輯
├── points/
│   ├── adjust.blade.php     點數調整介面（含 modal）
└── orders/
    └── list.blade.php       訂單列表（含 Alpine.js 即時搜尋）
```

**為什麼用 Alpine + Tailwind：**
- Alpine.js 是 Laravel 創辦人 Caleb 寫的，跟 Laravel 完美整合
- 學習曲線比 React/Vue 平緩 80%
- 看起來像現代 SPA，但實作上是 Blade + 一點點 JS
- 面試官會說「你會 Alpine + Tailwind 嗎？」（會的人不多）

**時間：** 2 週

#### 任務 2：寫一篇技術 blog 講「手機 FB 分享 deep link 閃退」

這是你已經解過的真實問題，把它寫出來：

```markdown
# 為什麼手機 Chrome 上 FB 分享按鈕會閃退？
## 問題現象
## 為什麼會發生（FB App deep link 劫持機制）
## 我試過的解法
  - 解法 1：window.open（失敗，原因 X）
  - 解法 2：a target=_blank（失敗，原因 Y）
  - 解法 3：navigator.share() ★ 可行
## 跨平台處理（iOS / Android / Desktop）
## 完整可用的程式碼
```

這篇文章如果寫好，會被 SEO 搜到。**這就是技術 blog 的最高境界——「在 Google 搜尋『手機 FB 分享閃退』時你的文章是第一個」。**

**時間：** 3 天

#### 任務 3：補一個面試故事

```
「我在一個旅遊內容網站解過手機 FB 分享 deep link 劫持的問題。
 那個 bug 在 Stack Overflow 上有十幾個 thread 都沒完美解法。
 我用 navigator.share() + iOS/Android 不同 fallback 解決了，
 還寫成 blog 給其他人參考。
 
 這個故事能證明：
   - 我會前端也會後端
   - 我會在文件不足的情況下解陌生領域的 bug
   - 我會把解法文件化幫助別人」
```

### 6.2 Phase 6 交付物

```
ez_crm/
├── resources/views/admin/    Alpine.js + Tailwind admin UI
├── public/build/             Vite 編譯後的 frontend assets  
└── scheme/blog_drafts/
    └── fb_share_mobile_deeplink_fix.md   手機 FB 分享閃退完整修復方案
```

### 6.3 Phase 6 多打開的職涯路

| 職位 | 薪資（台灣）| 為什麼你是好人選 |
|------|-----------|---------------|
| **Full-Stack Engineer (PHP + Frontend)** | 80-110K | 後端強 + 能寫前端，team 不需要找兩個人 |
| **Laravel Full-Stack** | 85-115K | Laravel 生態本來就 Blade-first，不需要 React/Vue |
| **Technical Lead（小團隊）** | 100-140K | 能 cover 全 stack 是 small team lead 的硬條件 |

---

## 七、Phase 7：Game-test 整合 = 多前台 PoC（衝刺商品化的關鍵）

### 為什麼這個 Phase 是「商品化衝刺」的關鍵

之前的 Phase 1-6 證明的是「ez_crm 內部能跑」。  
**Phase 7 證明的是「ez_crm 能被外部前台使用」**——這才是真正的 API-first 設計驗證。

業界對「商品化」的定義不是「功能多」，是「能被多個 client 共用」。Phase 7 用 Game-test 驗證這件事。

### 7.1 為什麼 Game-test 是完美的測試前台

**Game-test 現況**（位於 `c:/xampp/htdocs/Game-test/`）：

```
5 個獨立純前端小遊戲：
  ├── type_master         打字訓練（已有 css/js/data 三層結構化拆分）
  ├── dino_run_v2         恐龍跑酷
  ├── doodle_jump_v3      塗鴉跳跳
  ├── tetris_v1           俄羅斯方塊
  └── mbti-test-full      MBTI 測驗

技術特徵：
  ✓ vanilla HTML/CSS/JS（無框架負擔）
  ✓ WebAudio API 音效（進階 JS 證據）
  ✓ 完全沒有後端 / 會員 / 認證邏輯
  ✓ 100% kevin 自寫
```

**為什麼這是完美測試標的：**

1. **5 個遊戲 = 5 種完全不同的分數結構**
   - type_master → WPM、accuracy、duration
   - dino_run → distance、duration
   - doodle_jump → height、duration
   - tetris → score、lines、level
   - mbti → result type（不是分數，是 enum）
   
   **這 5 種結構會強迫 ez_crm 的 schema 必須夠 generic 才能 cover**——這正是真實 SaaS 平台會遇到的設計題。

2. **5 個獨立遊戲 = 5 個獨立的會員中心 use case**
   - 登入 → 拿 token
   - 玩遊戲 → 寫紀錄
   - 看歷史
   - 看排行榜
   - 跨遊戲累積會員資料

3. **純前端 = 最容易接 RESTful API**（不用學 React/Vue）

### 7.2 整合架構（會員中心 + 多前台入口）

```
                    ez_crm（會員中心 + API）
                  ┌──────────────────────┐
                  │ POST /api/v1/auth/login    │
                  │ GET  /api/v1/me            │
                  │ POST /api/v1/scores         │
                  │ GET  /api/v1/scores/history │
                  │ GET  /api/v1/scores/leaders │
                  │ GET  /api/v1/games          │
                  └──────────────────────┘
                          ▲
        ┌─────────────────┼─────────────────┐
        │                 │                 │
        ▼                 ▼                 ▼
   Game-test        Game-test-v2       未來前台 N
   (5 個遊戲)       (改外觀/branding)  (LIFF/商城/etc)
   
   每個遊戲都會：
     1. 用 token 驗證身份
     2. 完成後寫分數
     3. 顯示個人歷史
     4. 看排行榜
```

### 7.3 整合路線（6-8 週）

#### Phase A: 建立共用 shell（1-2 週）

```
ez_crm 端：
  ✓ POST /api/v1/auth/login（已有 Sanctum）
  ✓ GET  /api/v1/me
  ✓ 設定 CORS 允許 Game-test 域名

Game-test 端：
  ├── shell/login.html         登入頁
  ├── shell/game-hub.html      遊戲列表 + 顯示會員資料 + nav
  ├── js/auth.js               token 管理 + fetch wrapper
  └── css/shell.css            共用外殼樣式
```

**關鍵設計決策：**

> **A 路 vs B 路**
>
> A 路：把 Game-test 整個吃進 ez_crm（變成 ez_crm 的 public/ 子目錄）  
>   → 簡單，不用處理 CORS  
>   → 但**違背「會員中心 + 多前台」的本意**
>
> B 路：Game-test 保持獨立 repo，跨域呼叫 ez_crm API  
>   → 真實的多前台架構  
>   → 要處理 CORS、token、CSRF
>
> **選 B 路。** 你要驗證的就是「多前台入口」，A 路會讓你騙過自己。
> CORS / token 這些坑就是你要踩的，踩過就會了。

#### Phase B: type_master 第一個 end-to-end 整合（1 週）

選 type_master 做第一個，因為它已經有 `css/js/data` 三層結構化拆分，最容易接管。

```
任務：
  1. 加入登入要求（未登入 → redirect to shell/login.html）
  2. 開始遊戲時記錄 session_id
  3. 結束時 POST /api/v1/scores
     payload: {
       game: "type_master",
       data: { wpm, accuracy, duration, chars_typed }
     }
  4. 顯示個人歷史（GET /api/v1/scores/history?game=type_master）
  5. 顯示排行榜（GET /api/v1/scores/leaders?game=type_master）

驗證點：
  ✓ token 跨域傳遞正常
  ✓ Sanctum stateful 認證在跨域下能用
  ✓ ez_crm 的 score schema 能存 type_master 的數據
```

#### Phase C: 其他 4 個遊戲套用同樣 pattern（2-3 週）

每個遊戲 2-3 天：

| 遊戲 | 分數結構 | 設計挑戰 |
|------|---------|---------|
| dino_run | `{distance, duration}` | 距離型分數 |
| doodle_jump | `{height, duration}` | 高度型分數 |
| tetris | `{score, lines, level}` | 多維度分數 |
| mbti-test | `{result_type, answers}` | 不是分數，是 enum 結果 |

**這 4 個遊戲跑通後，你的 `scores` table 就證明能 cover：**
- 數值型（distance, height, score, wpm）
- 計數型（lines, chars_typed）
- 分級型（level, accuracy）
- 列舉型（mbti result）
- 時間型（duration）

**這個 schema 設計能力是 Senior 級的真實證明。**

#### Phase D: 第二前台 PoC（1 週）

```
任務：
  1. 複製 Game-test → Game-test-v2
  2. 換 branding（不同 logo / 配色 / 名稱）
  3. 用「同一個 ez_crm 帳號」在兩個前台登入
  4. 證明：
     - Session 跨前台共用
     - 分數累積到同一個 member
     - 排行榜跨前台合併
     
驗證：「會員中心 + 多前台」架構真的成立
```

### 7.4 schema 設計範例

這是你做 Phase 7 時必須先想清楚的核心 schema：

```sql
-- games 表（遊戲註冊表）
CREATE TABLE games (
  id           BIGINT PK,
  slug         VARCHAR(50) UNIQUE,    -- 'type_master', 'dino_run' ...
  name         VARCHAR(100),
  schema_def   JSON,                   -- 該遊戲的分數欄位定義
  created_at, updated_at
);

-- scores 表（用 JSON 欄位 cover 任意分數結構）
CREATE TABLE scores (
  id           BIGINT PK,
  member_id    BIGINT FK,
  game_id      BIGINT FK,
  score_data   JSON,                   -- {wpm: 80, accuracy: 95.5, ...}
  primary_score INT,                   -- 用於排行榜的單一數值（從 score_data 抽出來）
  played_at    TIMESTAMP,
  
  INDEX (game_id, primary_score DESC),  -- 排行榜
  INDEX (member_id, played_at DESC)     -- 個人歷史
);
```

**為什麼這個設計是 Senior 級：**

- 用 JSON 欄位處理「不知道未來會有什麼遊戲」的彈性
- 用 `primary_score` 整數欄位處理「排行榜需要快速排序」的效能
- 用 `schema_def` 在 games 表記錄「該遊戲的分數欄位有哪些」，支援 generic UI 渲染
- 對 mbti 這種「沒有 score 只有 result」的特例：`primary_score = NULL`，靠 `score_data.result_type` 走

### 7.5 Phase 7 的面試武器

**故事：「我如何用 Game-test 驗證 ez_crm 的多前台架構」**

> 「我做 ez_crm 的時候不滿足於『內部能跑』，我想驗證『能被外部前台使用』。
> 
> 我有一個獨立的 repo 叫 Game-test，裡面有 5 個我自己寫的純前端小遊戲。
> 我用這 5 個遊戲當測試前台，把 ez_crm 改成 API-first 設計。
> 
> 5 個遊戲的分數結構完全不同：
>   - type_master 是 WPM + accuracy
>   - dino_run 是 distance
>   - tetris 是 score + lines + level
>   - mbti-test 根本不是分數，是 enum result
> 
> 這逼我把 ez_crm 的 scores schema 設計成：
>   - JSON 欄位處理任意結構
>   - primary_score 整數欄位處理排行榜效能
>   - schema_def 註冊表處理 generic UI 渲染
> 
> 然後我做了第二個前台（Game-test-v2）驗證：
>   - 同一個會員可以在兩個前台共用 session
>   - 分數可以跨前台累積
>   - 排行榜可以跨前台合併
> 
> 整套架構從架構設計到實作交付到測試驗證，
> 是我在沒有 PM 沒有設計師沒有同事 review 的情況下，
> 一個人從零到一完成的 multi-tenant SaaS PoC。」

**這個故事的殺傷力：**
- 證明你會 API-first 設計（不是 monolith 思維）
- 證明你會 generic schema 設計（JSON + primary key 混合）
- 證明你會 CORS / token / cross-origin 認證
- 證明你會「為了驗證設計而額外做測試前台」的工程紀律
- 最重要：**這是 multi-tenant SaaS 的核心模式**

### 7.6 Phase 7 對「商品化」的意義

```
之前的 Phase 1-6 給你：
  ✓ Senior PHP Backend 的能力證明
  ✓ DevOps + 前端 showcase 加分
  
Phase 7 給你：
  ✓ 「我能 design 一個 SaaS 級的會員中心」的證明
  ✓ 商品化的真實雛形（不是 demo, 是 PoC）
  ✓ 一個獨特的面試故事（多數人沒做過這個練習）
```

**戰略意義：** Phase 7 完成後，你不只能投 Senior Backend，還能投 **「Lead Engineer for SaaS Platform」** 級別的職位（120-150K）。

### 7.7 注意事項

- **不要在 Phase 1-2 還沒做完前急著做 Phase 7**——schema 設計需要先有 Members CRUD 的基礎
- **Game-test repo 保持獨立**——不要 merge 進 ez_crm
- **用 ez_crm 的 develop 分支做 CORS 測試**——不要動到 main
- **Phase D 的「第二前台」可以很簡單**——換個 logo 換個底色就夠證明架構成立

### 7.8 Phase 7 時程在整體中的位置

```
建議插入位置：Phase 4 完成後、Phase 5 之前

Week 1-3    Phase 1  Members CRUD + RBAC
Week 4-7    Phase 2  Points + Coupon + Order
Week 8-10   Phase 3  Docker + Redis + Queue
Week 11-12  Phase 4  50 萬筆壓測
Week 13-20  Phase 7  ★ Game-test 整合（6-8 週）
              ┃
              ┃ 完成後可以開始投履歷
              ┃ 鎖定「Senior + SaaS 經驗」的職位
              ▼
Week 21-24  Phase 5  DevOps（選做）
Week 25-28  Phase 6  前端 showcase（選做）
```

**為什麼把 Phase 7 放在 5/6 之前？** 因為它對「商品化」目標最直接，也最能在面試時當主力故事。Phase 5/6 是加分項，Phase 7 是新的主場。

---

## 八、職涯路線總覽（含 Phase 5/6/7）

完成不同 Phase 對應不同職涯路線：

```
完成度              可投職位                          薪資帶
─────────────────────────────────────────────────────────
Phase 1-4           Senior PHP/Laravel Backend       85-110K
   + Phase 7 ★      Senior + SaaS Platform Eng       100-130K  ★★ 商品化路徑
   + Phase 5        Platform Engineer / DevOps      95-130K
   + Phase 6        Full-Stack Engineer              90-120K
   + Phase 5+6+7    Tech Lead / Principal Engineer  120-160K  ★★★ 最強組合
```

**戰略建議：**

| 你的偏好 | 建議路線 |
|---------|---------|
| 想商品化、想做 SaaS 產品 | Phase 1-4 + 7 ★ 商品化路徑（你的核心目標）|
| 純架構設計、深度而非廣度 | Phase 1-4 + 跳 Senior Backend |
| 喜歡碰 infra | Phase 1-5 + 7 + 跳 Platform Engineer |
| 想當 small team 的 one-man-army | Phase 1-7 全做 + 跳 Tech Lead ⭐⭐ |

**修正後的個人建議：** **Phase 7 必做（你的核心目標就在這），Phase 5 必做（已有實戰經驗低成本高回報），Phase 6 選做。**

理由：
- Phase 7 是你「異想天開」目標的具體實現路徑
- Phase 7 帶來的薪資跳幅可能比 Phase 5/6 加起來都大（+15-30K）
- 因為「會 design SaaS multi-tenant 架構」在台灣市場稀缺
- 完成 Phase 7 後你的 profile 從「Senior Backend」升級到「SaaS Architect」

---

## 八、不需要做的事（修正版）

| 不需要 | 原因 |
|--------|------|
| 搬 linky360 全部 76 支 API | 數量不代表能力，10 支做到深就夠了 |
| BigQuery 整合 | 太貴且面試不會問，改用 MySQL + Redis 證明效能即可 |
| ~~前端 SPA~~ → 改為「Phase 6 選做」 | island_tales 證明你能做，可以變成額外籌碼 |
| React / Vue 框架 | Alpine.js 已足夠展示前端能力，學習成本低 80% |
| 微服務拆分 | 單體做好比硬拆微服務有說服力 |
| Kubernetes | Docker Compose 已足夠，K8s 是純 DevOps 的活 |
| AWS / GCP 證照 | 你的實戰經驗比證照更值錢，但可以考一張當履歷加分 |

---

## 九、參考資源

### 核心 Phase 1-4
| 主題 | 資源 |
|------|------|
| Laravel 進階 | [Laravel Beyond CRUD](https://laravel-beyond-crud.com/) — Service Layer、Action、DTO |
| 系統設計面試 | [System Design Primer](https://github.com/donnemartin/system-design-primer) |
| 壓測工具 | [k6 官方文件](https://k6.io/docs/) |
| Docker 最佳實踐 | [Docker PHP Best Practices](https://github.com/serversideup/docker-php) |
| RBAC | [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission) |
| 狀態機 | [Spatie Laravel Model States](https://spatie.be/docs/laravel-model-states) |

### Phase 5: DevOps / Platform
| 主題 | 資源 |
|------|------|
| Cloudflare for Devs | [Cloudflare Developers Docs](https://developers.cloudflare.com/) |
| Plesk + Laravel | [Plesk Laravel Extension Docs](https://docs.plesk.com/) |
| Nginx + Laravel | [Laravel Forge Best Practices](https://forge.laravel.com/docs/) |
| Google Ads API | [Google Ads API PHP Client](https://github.com/googleads/google-ads-php) |
| Supervisor for Queue | [Laravel Queue Workers in Production](https://laravel.com/docs/queues#supervisor-configuration) |

### Phase 6: 前端 showcase
| 主題 | 資源 |
|------|------|
| Alpine.js | [Alpine.js 官方](https://alpinejs.dev/) |
| Tailwind CSS | [Tailwind 官方](https://tailwindcss.com/) |
| Laravel Blade | [Laravel Blade Components](https://laravel.com/docs/blade) |
| OG / Meta Tags | [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/) |
| navigator.share() | [MDN Web Share API](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/share) |
