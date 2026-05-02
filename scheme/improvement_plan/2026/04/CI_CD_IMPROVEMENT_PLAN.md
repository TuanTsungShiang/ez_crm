# CI/CD 改善計畫（基準文件）

> **撰寫日期：** 2026-04-29  
> **目的：** 把 ez_crm + ez_crm_client 的 CI/CD 從 57/100 補強到 80+/100  
> **使用方式：** 這份文件是「待辦清單 + 驗收標準」，每完成一項打勾，調整後重新評分

---

## 一、現況評分（2026-04-29）

```
                    分數    狀態
──────────────────────────────────────
Continuous Integration  75    ✅ 良好
測試自動化              65    ⚠ 可加強
靜態分析自動化          78    ✅ 良好
部署自動化（CD）        15    ❌ 最大缺口 ★★★
環境管理                62    ⚠ 可加強
容器化 / IaC            20    ❌ 大缺口 ★★
文件自動化              70    ✅ 良好

加權總分：57/100
目標分數：80+/100
```

---

## 二、待辦清單（依優先級排序）

每個項目有：
- **編號**（例如 CI-1）
- **優先級**（★★★ / ★★ / ★）
- **預估工時**
- **影響分數**（完成後預期提升的分數）
- **驗收標準**

---

### CI-1：composer audit 加入 CI ★★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 30 分鐘 |
| 影響分數 | 靜態分析 +5 |
| 風險 | 低 |
| 對應 OWASP | A06 Vulnerable Components |

**現況：** CI 沒有檢查 composer 依賴漏洞。

**目標：** 每次 push 自動跑 `composer audit`，發現 CVE 立即 block。

**驗收標準：**
- [ ] `.github/workflows/phpunit.yml` 加入 `composer audit` step
- [ ] 失敗時 CI 標紅
- [ ] README 說明遇到 audit 失敗時的處理流程

**實作範例：**
```yaml
- name: Composer security audit
  run: composer audit --no-interaction --format=plain
```

---

### CI-2：npm audit 加入前端 CI ★★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 30 分鐘 |
| 影響分數 | 靜態分析 +3 |
| 風險 | 低 |

**現況：** ez_crm_client 的 frontend.yml 沒檢查 npm 漏洞。

**目標：** 每次 push 自動跑 `npm audit --production`。

**驗收標準：**
- [ ] `.github/workflows/frontend.yml` 加入 npm audit step
- [ ] 設定 audit-level 至少 high（避免被 low 警告淹沒）

**實作範例：**
```yaml
- name: NPM security audit
  run: npm audit --omit=dev --audit-level=high
```

---

### CI-3：測試覆蓋率報告（PHPUnit + Codecov）★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 半天 |
| 影響分數 | 測試自動化 +10 |
| 風險 | 低 |

**現況：** PHPUnit 跑了但沒報告覆蓋率，無法追蹤趨勢。

**目標：**
- 每次 CI 執行產生覆蓋率報告
- 上傳到 Codecov（免費）
- README 加上 coverage badge
- PR 自動顯示 coverage diff

**驗收標準：**
- [ ] `phpunit.yml` 加 `--coverage-clover coverage.xml`
- [ ] 加 codecov upload step（用 GitHub Action）
- [ ] README 加上 coverage badge
- [ ] 設定目標覆蓋率（建議 70%+ for new code）

**實作範例：**
```yaml
- name: Run PHPUnit with coverage
  run: vendor/bin/phpunit --coverage-clover coverage.xml

- name: Upload coverage to Codecov
  uses: codecov/codecov-action@v4
  with:
    files: ./coverage.xml
    fail_ci_if_error: false
```

---

### CI-4：Laravel Pint 強制執行 ★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 1 小時 |
| 影響分數 | 靜態分析 +3 |
| 風險 | 低（已有 vendor/bin/pint）|

**現況：** Pint 已裝但 CI 未強制檢查。

**目標：** PR 必須通過 Pint 格式檢查才能合併。

**驗收標準：**
- [ ] `phpunit.yml` 加入 `vendor/bin/pint --test` step
- [ ] CI 失敗時提示開發者跑 `vendor/bin/pint` 修正
- [ ] 設定 pre-commit hook（選做）

**實作範例：**
```yaml
- name: Laravel Pint code style check
  run: vendor/bin/pint --test
```

---

### CI-5：CI 失敗通知（Slack / Discord / Email）★

| 屬性 | 值 |
|------|---|
| 預估工時 | 半天 |
| 影響分數 | CI +5 |
| 風險 | 低 |

**現況：** CI 失敗只能靠 GitHub email 通知，容易漏看。

**目標：** CI 失敗時即時通知到 Slack 或 Discord。

**驗收標準：**
- [ ] 選定通知頻道（Slack / Discord / Telegram）
- [ ] 設定 GitHub Secret 存 webhook URL
- [ ] 失敗時 post 訊息含 commit hash + 連結

**注意：** 單人專案這個優先級較低，但加入 team 後必要。

---

## 三、CD（部署自動化）— 最大缺口 ★★★

### CD-1：建立 Dockerfile（production-grade）★★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 1-2 週（含調校）|
| 影響分數 | CD +15、容器化 +30 |
| 風險 | 中 |

**現況：** 完全沒有 Dockerfile。

**目標：** 寫一個 multi-stage Dockerfile 能跑 production-grade Laravel。

**驗收標準：**
- [ ] `Dockerfile` 包含 builder stage + runtime stage
- [ ] 包含 PHP 8.2-FPM + 必要 extensions
- [ ] 不含開發工具（vendor/dev、phpunit、pint）
- [ ] image 大小 < 250MB
- [ ] 用 non-root user 跑
- [ ] healthcheck 設定
- [ ] `.dockerignore` 完整

**參考：**
- [serversideup/docker-php](https://github.com/serversideup/docker-php) — Laravel production base image
- 參考 ez_crm 的 SENIOR_ROADMAP Phase 5

---

### CD-2：建立 docker-compose（dev / prod）★★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 3-5 天 |
| 影響分數 | 容器化 +20 |
| 風險 | 中 |

**現況：** Sail 已裝但未深度使用，沒有 production-ready 的 compose。

**目標：** 兩份 compose：
1. `docker-compose.dev.yml`：本地開發用
2. `docker-compose.production.yml`：可部署到 VPS

**驗收標準（production 版）：**
- [ ] services: app（PHP-FPM）、nginx、mysql、redis
- [ ] services: queue（supervisor）、scheduler（cron）
- [ ] networks 分離（internal / public）
- [ ] volumes 持久化（mysql、redis、storage）
- [ ] 環境變數從 .env.production 讀取
- [ ] 不暴露 mysql / redis 到外網
- [ ] backup script 整合

---

### CD-3：多環境設定（.env.staging / .env.production）★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 半天 |
| 影響分數 | 環境管理 +15 |
| 風險 | 低 |

**現況：** 只有一個 .env，沒有環境分離。

**目標：** 支援 dev / staging / production 三套配置。

**驗收標準：**
- [ ] `.env.example` 含所有變數說明
- [ ] `.env.staging.example`（公開範本）
- [ ] `.env.production.example`（公開範本）
- [ ] README 說明各環境差異
- [ ] CI 用 `.env.testing`（已有）

---

### CD-4：Auto deploy to staging（GitHub Actions）★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 1 週 |
| 影響分數 | CD +20 |
| 風險 | 中（需要實際 staging 環境）|
| 前置條件 | CD-1、CD-2 完成 |

**現況：** 完全手動部署。

**目標：** push 到 develop → 自動部署到 staging。

**驗收標準：**
- [ ] 有實際 staging server（VPS 或 cloud）
- [ ] `.github/workflows/deploy-staging.yml` 寫好
- [ ] 部署失敗自動 rollback
- [ ] 部署成功 Slack/Discord 通知
- [ ] migration 自動跑
- [ ] cache clear 自動執行

**選擇方案：**
- 方案 A：GitHub Actions + SSH + rsync（簡單）
- 方案 B：GitHub Actions + Docker Hub + watchtower（較現代）
- 方案 C：Laravel Forge + GitHub integration（付費但省事）

---

### CD-5：Auto deploy to production（含手動 approval）★

| 屬性 | 值 |
|------|---|
| 預估工時 | 1 週 |
| 影響分數 | CD +15 |
| 風險 | 高（直接影響使用者）|
| 前置條件 | CD-4 完成且穩定運作 |

**現況：** 無 production 環境（PoC 階段）。

**目標：** 商品化階段時，main 分支 push → 等待手動 approval → 部署到 production。

**驗收標準：**
- [ ] GitHub Actions Environment 設定 production approval
- [ ] 部署前自動跑 backup
- [ ] 藍綠或滾動部署策略
- [ ] 部署後 health check
- [ ] 失敗自動 rollback
- [ ] 部署紀錄寫入 changelog

**注意：** 這個項目要等 ez_crm 真的有 production 用途時才做。

---

### CD-6：GitHub Release page + 自動 changelog ★

| 屬性 | 值 |
|------|---|
| 預估工時 | 半天 |
| 影響分數 | Git 規範 +5、文件 +5 |
| 風險 | 低 |

**現況：** v1.0.0 tag 有了，但沒有 GitHub Release page、沒有 changelog。

**目標：** 每個 tag 自動產生 GitHub Release，含自動產生的 changelog。

**驗收標準：**
- [ ] `.github/workflows/release.yml` 在 tag push 時觸發
- [ ] 從 conventional commits 自動產生 changelog（用 git-cliff 或 release-please）
- [ ] Release page 含完整變更紀錄
- [ ] Release notes 自動分類（feat / fix / docs / refactor）

**工具選擇：**
- `release-please`（Google 維護，適合 conventional commits）
- `git-cliff`（Rust 寫的，速度快）
- `semantic-release`（Node 生態，功能多）

**建議：** `release-please`，因為你已經 81% conventional commits，現成的素材最豐富。

---

## 四、Sentry / 錯誤追蹤（橫跨 CI/CD/觀測）★★

| 屬性 | 值 |
|------|---|
| 預估工時 | 1-2 天 |
| 影響分數 | 觀測性（新維度）|
| 風險 | 低 |

**現況：** 無 production 錯誤追蹤。

**目標：** 整合 Sentry（免費 tier 足夠 PoC 階段）。

**驗收標準：**
- [ ] `composer require sentry/sentry-laravel`
- [ ] `npm install @sentry/vue`
- [ ] DSN 設定在 .env
- [ ] CI 部署時上傳 source map
- [ ] Release tag 同步到 Sentry
- [ ] 設定 alert（連續錯誤、錯誤率上升）

---

## 五、實作順序建議

### 第 1 週（快速擊低果）

```
Day 1：CI-1 composer audit（30 min）
Day 1：CI-2 npm audit（30 min）
Day 2：CI-4 Pint 強制執行（1h）
Day 3：CI-3 Codecov 整合（半天）
Day 4：CD-3 多環境設定（半天）
Day 5：CD-6 GitHub Release + changelog（半天）

第 1 週結束預期分數：57 → 70（+13）
```

### 第 2-3 週（容器化）

```
Week 2：CD-1 Dockerfile multi-stage（1-2 週）
Week 3：CD-2 docker-compose dev/prod（3-5 天）

第 3 週結束預期分數：70 → 80（+10）
```

### 第 4 週（觀測 + 通知）

```
Week 4：Sentry 整合（1-2 天）
Week 4：CI-5 Slack/Discord 通知（半天）

第 4 週結束預期分數：80 → 85（+5）
```

### 之後（依商品化進度）

```
CD-4 Auto deploy to staging（等有 staging 環境）
CD-5 Auto deploy to production（等有 production 用戶）
```

---

## 六、不做的事（避免 over-engineering）

| 項目 | 為什麼不做 |
|------|----------|
| Kubernetes / K8s manifests | docker-compose 已足夠 PoC 階段，K8s 是過度工程 |
| Terraform / Pulumi | 沒有多 region 需求 |
| 多 PHP 版本 build matrix | 鎖定 8.2 即可，不需要 cover 8.0/8.1 |
| 複雜的部署策略（金絲雀 / 藍綠）| 商品化前不需要 |
| ELK stack / Datadog | Sentry + Laravel Log 已足夠 |
| GraphQL CI 工具 | 你的 API 是 RESTful |
| E2E 測試（Playwright / Cypress）| 等前端穩定後再加 |

---

## 七、各項完成後的目標分數推估

```
                        現況   +CI 改善  +CD 改善  +觀測   最終
                                (CI-1~5) (CD-1~6)  (Sentry)
──────────────────────────────────────────────────────────────
Continuous Integration   75      85       85       88      88
測試自動化               65      80       80       80      80
靜態分析自動化           78      88       88       88      88
部署自動化（CD）         15      18       65       65      65
環境管理                 62      62       80       80      80
容器化 / IaC             20      20       65       65      65
文件自動化               70      70       82       82      82

加權總分                 57      66       77       80      80+
```

**80 分以上 = 業界中型團隊水準。** 對 PoC 來說已經 over-spec。

---

## 八、驗證標準

完成後可以這樣驗證：

```bash
# 1. push 到 develop 應該觸發完整 CI
git push origin develop

# 預期 CI 跑：
#   ✓ composer install
#   ✓ composer audit          ← CI-1
#   ✓ Larastan level 5
#   ✓ Pint --test            ← CI-4
#   ✓ Migrate
#   ✓ PHPUnit + Coverage     ← CI-3
#   ✓ Upload to Codecov      ← CI-3
#   ✓ npm audit              ← CI-2
#   ✓ vue-tsc strict
#   ✓ ESLint + Prettier

# 2. tag push 應該自動 release
git tag v1.1.0 && git push origin v1.1.0

# 預期：
#   ✓ 自動產生 GitHub Release  ← CD-6
#   ✓ 自動產生 changelog       ← CD-6
#   ✓ 上傳 docker image        ← CD-1（如果配 Docker Hub）

# 3. 失敗應該即時通知
# 故意推一個壞的 commit

# 預期：
#   ✓ CI 標紅
#   ✓ Slack/Discord 收到通知   ← CI-5
```

---

## 九、與 SENIOR_ROADMAP 的對應

| CI/CD 項目 | SENIOR_ROADMAP Phase |
|-----------|--------------------|
| CI-1 ~ CI-5 | Phase 1-2 同步補（不需獨立階段）|
| CD-1, CD-2（Docker）| Phase 5（DevOps Platform Engineer）|
| CD-3（多環境）| Phase 5 |
| CD-4（auto deploy staging）| Phase 5 |
| CD-5（auto deploy production）| 商品化階段（Phase 7 之後）|
| CD-6（release + changelog）| 隨時可做 |
| Sentry | Phase 5 觀測性 |

---

## 十、追蹤紀錄

每完成一項，在這裡打勾：

### CI 改善
- [ ] CI-1：composer audit
- [ ] CI-2：npm audit
- [ ] CI-3：Codecov 整合
- [ ] CI-4：Pint 強制
- [ ] CI-5：失敗通知

### CD 改善
- [ ] CD-1：Dockerfile
- [ ] CD-2：docker-compose
- [ ] CD-3：多環境設定
- [ ] CD-4：Auto deploy staging
- [ ] CD-5：Auto deploy production
- [ ] CD-6：GitHub Release + changelog

### 觀測性
- [ ] Sentry 整合

---

## 十一、一句話總結

```
這份計畫的目標不是「達到完美」，
是「在 4 週內把 CI/CD 從 57 分推到 80+ 分」，
也就是達到「業界中型團隊水準」。

不需要把 Kubernetes、Terraform、ELK 全部上，
那是 over-engineering。

12 個待辦項目，
其中前 6 個（CI-1~5 + CD-3 + CD-6）總工時 < 3 天，
就能拿到 +10~15 分，
是 ROI 最高的部分。

剩下的 Docker / 自動部署 / Sentry 是中期投資，
與 SENIOR_ROADMAP Phase 5 同步推進即可。
```
