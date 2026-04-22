# ez_crm × ez_crm_client — 工程基礎建設學習路線圖

> 撰寫日期：2026-04-22
> 撰寫動機：體檢結果顯示 API 設計、DB、Auth 已達 senior，真正缺口是「工程基礎建設」——在公司沒機會接觸，必須靠個人專案補齊
> 目標：8 週內把後端 ez_crm 與前端 ez_crm_client 的 DevOps / 品質工具 / 可觀測性補到 senior 門檻
> 驗收原則：每一項都要有 **commit 紀錄 + 可 demo 的成果 + 能在面試 60 秒內講清楚**

---

## 為什麼這塊最重要

Senior 與 mid-level 的差異，往往不在「能不能寫出功能」，而在：

1. **別人 clone 你的 repo 能不能 10 分鐘跑起來**（Docker / README / .env.example）
2. **PR 合併前有沒有自動檢查**（CI / lint / test / static analysis）
3. **線上壞了有沒有辦法知道**（logging / monitoring / health check）
4. **程式碼有沒有團隊協作的統一規則**（pre-commit / conventional commit / code style）

在公司用公司既有的 CI/CD、既有的 monitoring 是「使用者」；自己從零搭起來才是「擁有者」。面試時被追問「你 repo 怎麼跑測試？」「怎麼部署？」「線上壞了怎麼辦？」，只有擁有者答得出來。

---

## 六大主題總覽

| # | 主題 | 後端 ez_crm | 前端 ez_crm_client | 優先度 | 預估工時 |
|---|------|-------------|---------------------|--------|----------|
| 1 | CI / 自動化測試 | GitHub Actions + PHPUnit | GitHub Actions + Vitest | 🔴 高 | 4h + 4h |
| 2 | Static Analysis | PHPStan (Larastan) Level 6+ | ESLint + Prettier + strict TS | 🔴 高 | 4h + 4h |
| 3 | Pre-commit 品質閘門 | Pint + pre-commit hook | husky + lint-staged + commitlint | 🟡 中 | 2h + 2h |
| 4 | 容器化 | Dockerfile + docker-compose (PHP+MySQL+Redis) | Dockerfile (multi-stage) + Nginx | 🔴 高 | 8h + 4h |
| 5 | 可觀測性 | Sentry + 結構化 Log + Health check | Sentry + Error Boundary | 🟡 中 | 6h + 4h |
| 6 | 文件化 | ADR + C4 架構圖 + CONTRIBUTING | README 補齊 + deployment 章節 | 🟢 低 | 4h + 2h |

**合計約 48 小時**，每週投入 6 小時，8 週完成。

---

## 主題 1：CI / 自動化測試

### 是什麼

在 GitHub Actions 上，每次 push/PR 自動跑測試、lint、static analysis。不通過不能合併。

### 為什麼 senior 需要

- 面試官 clone repo 第一眼就會看 `.github/workflows/`，沒有就是 solo hobby project 的訊號
- 你目前**已經有 18 個 PHPUnit 測試，但沒人自動跑**，白寫
- 前端 0 測試但有 CI 骨架就能從 1 個測試開始長

### 後端最小可行實作

```yaml
# .github/workflows/test.yml
name: PHPUnit
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env: { MYSQL_ROOT_PASSWORD: root, MYSQL_DATABASE: ez_crm_testing }
        ports: ['3306:3306']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', extensions: pdo_mysql }
      - run: composer install --no-progress
      - run: cp .env.testing.example .env.testing
      - run: php artisan key:generate --env=testing
      - run: php artisan migrate --env=testing
      - run: vendor/bin/phpunit
```

### 前端最小可行實作

```yaml
# .github/workflows/test.yml
name: Test
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      - run: npm run type-check
      - run: npm run lint
      - run: npm run test
```

### 學習關鍵字

`GitHub Actions`、`matrix build`、`service containers`、`actions/cache`、`codecov`

### 驗收標準

- [ ] README 頂端有綠色的 CI badge
- [ ] 故意 push 一個壞掉的 commit，CI 會紅燈
- [ ] PR 介面能看到「Checks」欄位
- [ ] 能講出 `push` 跟 `pull_request` 觸發時機的差別

---

## 主題 2：Static Analysis

### 是什麼

在不執行程式的前提下，靜態分析程式碼的型別錯誤、未定義變數、潛在 null 問題。比跑測試還早攔下 bug。

### 為什麼 senior 需要

- PHP 沒強型別系統，PHPStan 是補這個洞的業界標準
- 面試談「你怎麼保證 PHP 專案的型別安全？」——沒跑 PHPStan 就是答不出來
- 前端 TypeScript `strict: true` 是 senior 的基本線

### 後端實作

```bash
composer require --dev larastan/larastan
# 建立 phpstan.neon，level 從 5 起跳，逐步往 8 推
```

```neon
# phpstan.neon
includes:
  - vendor/larastan/larastan/extension.neon
parameters:
  paths: [app, database/migrations]
  level: 6
```

加到 CI：`vendor/bin/phpstan analyse`

### 前端實作

```bash
npm i -D eslint @typescript-eslint/eslint-plugin prettier eslint-plugin-vue
npx eslint --init
```

關鍵：`tsconfig.app.json` 加 `"strict": true`、`"noImplicitAny": true`。

### 學習關鍵字

`PHPStan level`、`baseline`、`generics in PHPDoc`、`strict null checks`、`eslint flat config`

### 驗收標準

- [ ] 後端 PHPStan level 至少 6，無 error（或有 baseline 但逐步消化）
- [ ] 前端 ESLint + Prettier 無 warning，TS strict mode 開啟
- [ ] CI 會因為 lint/型別錯誤擋下 PR

---

## 主題 3：Pre-commit 品質閘門

### 是什麼

在 commit 送出**之前**自動跑 lint / format / 部分 test，壞的 code 根本進不了 git history。

### 為什麼 senior 需要

- 「防呆」思維：不要依賴人類記得跑 lint
- 團隊協作的最基礎信號——沒這個的 repo 通常 git history 一團亂

### 後端實作

用 `brianium/paratest` 或單純 Pint：

```bash
# .git/hooks/pre-commit (改用 captainhook 或 husky + lint-staged 的 PHP 版)
composer require --dev captainhookphp/captainhook
vendor/bin/captainhook install
```

或更簡單：用 `.githooks/` 目錄 + `git config core.hooksPath .githooks`。

### 前端實作

```bash
npm i -D husky lint-staged @commitlint/cli @commitlint/config-conventional
npx husky init
```

```json
// package.json
"lint-staged": {
  "*.{ts,vue}": ["eslint --fix", "prettier --write"]
}
```

### 學習關鍵字

`husky`、`lint-staged`、`conventional commits`、`commitlint`、`git hooks`

### 驗收標準

- [ ] 故意留 unused import，commit 會被擋下或自動修掉
- [ ] 故意寫 `git commit -m "fixbug"` 沒有規範前綴會被擋
- [ ] `.husky/` 或 `.githooks/` 目錄有進版控

---

## 主題 4：容器化（重點中的重點）

### 是什麼

用 Docker 把 PHP + MySQL + Redis + Node 封裝成「任何電腦都能 `docker compose up` 跑起來」。

### 為什麼 senior 需要

- **目前你還在 XAMPP** —— 面試官看到會直接質疑你沒碰過生產環境
- 容器化是 2020 年後後端工程師的基本盤，不會約等於斷一條腿
- 面試必考：multi-stage build、image size 優化、健康檢查、network

### 後端實作（最完整的一項，值得花時間）

```
ez_crm/
├── docker/
│   ├── php/Dockerfile          # PHP 8.2-fpm + extensions
│   ├── nginx/default.conf
│   └── mysql/init.sql
├── docker-compose.yml           # 開發用：php + mysql + redis + mailhog
├── docker-compose.prod.yml      # 生產用：php + nginx + mysql(外接)
└── .dockerignore
```

關鍵學習點：
- **multi-stage build**：builder stage 裝 composer，final stage 只留 runtime
- **非 root user**：`USER www-data` 不要用 root
- **healthcheck**：`HEALTHCHECK CMD curl -f http://localhost/api/health`
- **volume 策略**：code 用 bind mount（開發），storage 用 named volume

### 前端實作

```dockerfile
# multi-stage：Node build → Nginx serve
FROM node:20-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
```

### 學習關鍵字

`multi-stage build`、`BuildKit`、`docker layer caching`、`.dockerignore`、`compose profiles`、`depends_on condition`、`docker network`、`image size optimization`

### 驗收標準

- [ ] 全新電腦 clone 後，只要有 Docker，`docker compose up` 能跑起完整 stack
- [ ] `docker images` 看 PHP image 小於 300MB（優化過）
- [ ] README 有「Docker 啟動」章節
- [ ] 能解釋為什麼用 multi-stage、為什麼不要用 root

---

## 主題 5：可觀測性

### 是什麼

讓線上系統「會說話」——壞了會告訴你、慢了能查、誰打了 API 能追。

### 為什麼 senior 需要

- Junior：「我的 code 跑得起來就好」
- Senior：「我的 code 在凌晨 3 點壞掉時，我要能從床上爬起來 5 分鐘內知道問題在哪」
- 這是帶線上系統的人才會有的思維

### 後端實作

```bash
composer require sentry/sentry-laravel
composer require --dev laravel/telescope
```

結構化 log 改造：

```php
// 舊：Log::info("User $id logged in")
// 新：
Log::channel('stack')->info('user.login', [
    'user_id' => $user->id,
    'ip' => $request->ip(),
    'trace_id' => request()->header('X-Trace-Id'),
]);
```

加 health check endpoint：

```php
Route::get('/api/health', function () {
    return [
        'status' => 'ok',
        'db' => DB::connection()->getPdo() ? 'ok' : 'fail',
        'redis' => Redis::ping() ? 'ok' : 'fail',
        'timestamp' => now()->toIso8601String(),
    ];
});
```

### 前端實作

```bash
npm i @sentry/vue
```

加 Error Boundary（Vue 用 `errorCaptured` hook）、API 失敗的統一上報。

### 學習關鍵字

`structured logging`、`correlation id / trace id`、`Sentry release tracking`、`source maps upload`、`Laravel Telescope`、`log level 分級`、`PII scrubbing`

### 驗收標準

- [ ] 故意 throw Exception，Sentry dashboard 收得到
- [ ] `/api/health` 回傳 JSON，DB/Redis 狀態都有
- [ ] log 是 JSON 格式，每筆都有 `trace_id`
- [ ] 能講出「為什麼不該在 log 裡印 password」（PII）

---

## 主題 6：文件化

### 是什麼

README、ADR（Architecture Decision Record）、架構圖、CONTRIBUTING。

### 為什麼 senior 需要

- 面試官打開 repo 10 秒內要能搞懂這系統在幹嘛
- ADR 是 senior 最明顯的訊號——「我做決策時想過 A/B/C 三個方案，選了 B 因為 X」
- 你 work_log 已經寫很多了，差的是**結構化**

### 實作

後端加入：

```
docs/
├── adr/
│   ├── 0001-use-uuid-for-public-id.md
│   ├── 0002-webhook-retry-strategy.md
│   └── template.md
├── architecture/
│   ├── c4-context.png       # 用 structurizr 或 draw.io
│   └── c4-container.png
└── CONTRIBUTING.md
```

ADR 範本（每份不超過一頁）：

```markdown
# ADR-0002: Webhook 重試採用指數退避 + circuit breaker

## Status
Accepted (2026-04-22)

## Context
Webhook 遞送會失敗。需要在「盡力重送」跟「保護自己系統」之間平衡。

## Decision
- 重試間隔：60s → 5m → 30m → 2h → 12h（指數退避）
- Circuit breaker：連續 20 次失敗自動斷開該訂閱
- 最多重試 5 次後進 dead letter

## Consequences
+ 避免打爆對方 endpoint
+ 避免 retry storm 拖垮自己的 queue
- 使用者需要自己檢查 dead letter
- 需要額外的監控 dashboard
```

### 學習關鍵字

`C4 Model`、`Mermaid diagram`、`ADR (Michael Nygard)`、`structurizr`、`README-driven development`

### 驗收標準

- [ ] 至少 5 份 ADR，涵蓋重要決策（Webhook、UUID、OAuth、Sanctum、DB schema）
- [ ] README 開頭有一張架構圖
- [ ] CONTRIBUTING.md 寫清楚 branch strategy、commit 規範
- [ ] 新人能照 README 在 30 分鐘內把專案跑起來

---

## 8 週執行計畫

| 週次 | 主題 | 後端任務 | 前端任務 | 產出 |
|------|------|----------|----------|------|
| W1 | CI 骨架 | PHPUnit workflow | Vitest 骨架 + 第一個 component test | 2 份綠燈 badge |
| W2 | Static analysis | Larastan level 5 → 6 | ESLint + Prettier + TS strict | 2 份乾淨的 lint report |
| W3 | Pre-commit | captainhook / git hooks | husky + lint-staged + commitlint | 品質閘門 |
| W4-5 | Docker | Dockerfile + compose（PHP+MySQL+Redis） | multi-stage Dockerfile + Nginx | `docker compose up` 一鍵啟動 |
| W6 | 可觀測性 | Sentry + 結構化 log + health check | Sentry + Error Boundary | 一次故意錯誤 → Sentry 截圖 |
| W7 | 文件化 | 5 份 ADR + C4 圖 + CONTRIBUTING | README 補 deployment 章節 | docs/ 目錄完整 |
| W8 | 總驗收 | 用另一台電腦從 0 部署 | 面試敘述演練 | 錄一段 5 分鐘的系統介紹 |

---

## 學習資源（精選，不是清單）

- **書**：
  - 《The DevOps Handbook》—— 不看實作，看 why
  - 《Accelerate》—— DORA 四大指標，面試能講
- **GitHub 範本**（讀現成的好專案最快）：
  - [laravel/laravel 本身的 CI 設定](https://github.com/laravel/laravel/tree/master/.github)
  - [spatie 任一專案](https://github.com/spatie)——package 等級的工程品質
- **文章關鍵字**：
  - "twelve-factor app"
  - "production-ready Laravel Docker"
  - "Vue 3 Vite production optimization"

---

## 面試時怎麼用這份成果

準備一個 **90 秒的「工程成熟度」自我介紹**：

> 「我這個專案除了功能之外，也把工程基礎建設補齊了：
> 1. GitHub Actions 每次 PR 跑 PHPUnit + Larastan level 6
> 2. 全 Docker 化，`docker compose up` 一鍵啟動 PHP + MySQL + Redis
> 3. Sentry 收集線上錯誤，health check endpoint 給 k8s probe
> 4. 關鍵決策都寫成 ADR，像 Webhook 重試策略為什麼選指數退避
>
> 我知道這些在大公司通常是平台組做的，但我想證明我理解底層，能獨立把一個系統從 0 推到生產。」

這段話能讓面試官知道：**你不只會寫 CRUD，你懂一個線上系統該長什麼樣。**

---

## 備註

- 這份是「補短板」，不是「從零學」。你的程式碼能力已夠，差的是**工程化習慣**
- 遇到卡關請直接問，不要自己挖兩天——這些工具的「正確姿勢」很難從文件看出來
- 完成一項就在本檔案 checkbox 打勾，累積看得到進度的感覺很重要
