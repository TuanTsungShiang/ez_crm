# ez_crm 工作日誌 — 2026-04-29 (Wed) 上午

> 分支狀態(到 11:30):
> - ez_crm `develop` = `6f187e0`(Points plan v0.1 draft)
> - ez_crm_client `develop` = `59a7598`(W2 前端 prettier mass apply)
> 協作:Kevin + Claude Code
> 前情:4/28 連戰 8.5 小時收完 Phase 1 RBAC + 4 連發小尾巴。今天從**對錶**開始,確認桌機 B 那 1 小時的工作放棄,乾淨地走今天的 plan。

---

## 🎯 今早主戰場:ENGINEERING W2 一氣呵成 + 啟動 Phase 2 plan

從 09:30 對錶到 11:30 — 2 小時做完原 plan 估的 1 整工作天 W2(後端 PHPStan + 前端 ESLint/Prettier/TS strict),並且額外寫了一份 432 行的 Phase 2 Points integration plan 草稿。

---

## 📋 完成項目

### 1. 對錶 + 桌機 B 取捨(09:30–10:00)

Kevin 早上想起昨晚在家裡桌機 B 動了一點東西,但 fetch 兩邊 repo 都 up-to-date,reflog 顯示 17:30 後沒任何活動。

**決策**:**完全放棄桌機 B 的 ~1 小時工作**,讓今天的 work 覆蓋過去。配套:Kevin 晚上回桌機 B 跑 `git fetch origin && git reset --hard origin/develop` 對齊。

代價:1 小時 work 沒了。收益:今天動 code 沒有 conflict 顧慮,可以**自由衝**。

### 2. STATIC_ANALYSIS_PLAN(10:00–10:30)

對齊 ENGINEERING_INFRASTRUCTURE_ROADMAP W2(deadline 2026-05-05),寫一份**完整可執行的 spec**。

[STATIC_ANALYSIS_PLAN.md](STATIC_ANALYSIS_PLAN.md) 涵蓋 10 個章節:後端 Larastan / 前端 ESLint+Prettier / TS strict / CI / level 升級節奏 / 預期踩坑 / Stretch goals / 跟 Phase 2 的時序(W2 完成前不動 Phase 2 大規模 code,避免 baseline 凍結 Points 的 error)。

Commit:`4f840cc docs(plan): add Static Analysis (PHPStan + ESLint) plan for ENGINEERING W2`

### 3. W2 後端 — Larastan + baseline + CI(10:30–11:00)

照 plan 走:
1. `composer require --dev larastan/larastan:^2.11`(對應 PHP 8.2,v3 要 PHP 8.3+)
2. `phpstan.neon` level 5,paths = `app/` + `database/seeders/`
3. **第一次裸跑:63 errors**(預期 100-200,實際偏低 — Larastan 處理了大量 magic)
4. `--generate-baseline phpstan-baseline.neon` 凍結
5. `phpstan analyse` ✅ green
6. CI:phpunit.yml 加一個 PHPStan step **在 PHPUnit 之前**(fast-fail 原則,~30s vs ~4min)
7. ADR-0002 紀錄 baseline 策略 + level 升級節奏(5/19 → L6,6/30 → L7)

**baseline 63 條主要分類**:
- ~25 條 Eloquent relation method 沒寫 return type hint(下次 W2 baseline 消化首選)
- ~25 條 API Resource `toArray()` 用 `$this->` 動態存取(需要 `@mixin` PHPDoc)
- 4-5 條 Sanctum / Socialite interface 限制
- 2 條 Match expression 不完整
- 其他

Commits:
- `175e9d9 feat(ci): introduce Larastan level 5 with baseline (ENGINEERING W2 backend)`
- `ec57d85 docs(adr): record ADR-0003 — frontend ESLint + Prettier + TS strict (W2 frontend)`(下午跑前合併寫)

CI 跑:**1m 15s 雙 job(PHPStan ~30s + PHPUnit 全套)** 全綠。

### 4. W2 前端 — ESLint + Prettier + TS strict + CI(11:00–11:25)

切到 `ez_crm_client`:
1. `npm i -D` 全套(eslint 9 / typescript-eslint / vue plugin / prettier / globals)
2. `eslint.config.js`(flat config)+ `prettier.config.js` 對齊既有 src/ 風格(no semi / single quote / trailing all / 100 col)
3. **House rule**:`vue/no-restricted-syntax` 禁 static `style="..."` attribute,允許 `:style="{}"` 動態綁定 — **對 Linky360 3,554 處 inline style 反面教材的具體預防,寫在 ADR-0003**
4. `npm run lint` 第一次裸跑:**2,027 problem**(2,026 error + 1 warning)
5. `npm run lint:fix` + `npm run format`:剩 13 error
6. 13 個全是 browser globals 沒被認識 → 加 `globals.browser` 進 eslint config:剩 1 error
7. 最後 1 個 `src/stores/auth.ts` unused `ref` import → 手動修
8. **lint 0 error / 0 warning** ✅
9. `tsconfig.app.json` 加 `strict: true` + `noUncheckedIndexedAccess: true` → `npm run type-check` **0 error**(既有 code 寫得很乾淨,@vue/tsconfig 應該本來就 strict-ready)
10. `.github/workflows/frontend.yml` 第一個前端 CI(npm ci + type-check + lint)

Commits(分 2 個 commit,prettier mass diff 隔離):
- `9f6587a feat(tooling): introduce ESLint + Prettier + TS strict + frontend CI (W2)`
- `59a7598 style: apply prettier --write across src/ (W2 ESLint setup follow-up)`

CI:**第一次 `Frontend` workflow run** 50 秒內綠燈(npm ci ~30s + type-check ~5s + lint ~5s)。

### 5. ADR-0003 + ADR-0002(寫進 ez_crm work_log)

兩份 ADR 寫進 `work_log/20260429/`:
- `adr_0002_phpstan_baseline.md` — 後端 Larastan baseline 策略 + level 升級節奏 + alternatives(Psalm / 直接 level 8 / 散落 ignore 都被否決)
- `adr_0003_eslint_prettier_ts_strict.md` — 前端 ESLint flat config + TS strict + 禁 inline style rule + alternatives(Biome / 舊 .eslintrc / 不開 strict 都被否決)

兩份 ADR 並列回答「為什麼後端 63 baseline 但前端 0 baseline」:
- **後端**:Eloquent magic property + API Resource `$this->` 動態存取是結構性問題
- **前端**:TypeScript explicit + Vue 3 `<script setup>` + `defineProps<{}>` 強制 type → 寫法本身就 type-safe

### 6. POINTS_INTEGRATION_PLAN.md v0.1 draft(11:30 起,寫到 ~12:00)

對齊 SENIOR_ROADMAP Phase 2.1。完整 432 行,涵蓋 11 個章節:
- Schema(`members.points` 快取 + `point_transactions` audit log + polymorphic `source`)
- API 規格(2 admin + 1 me endpoint)
- **4 個 senior 訊號**:DB::transaction + lockForUpdate / Idempotency-Key DB unique / balance_after snapshot / Concurrency test 用 pcntl_fork 證明不超賣
- Test 策略(含 RefreshDatabase vs DatabaseMigrations 在 lockForUpdate 場景的 trap)
- 估時 5-7 個工作天
- Phase 2.2 (Coupon) / 2.3 (Order) 的接點預留(polymorphic source 直接掛)
- 6 個待 review 決策(留 v0.1 → v1.0 升版條件)

Commit:`6f187e0 docs(plan): add Points integration plan v0.1 draft (SENIOR Phase 2.1)`

---

## 📊 W2 戰報

| 階段 | 估時(plan)| 實際 | 倍速 |
|---|---|---|---|
| W2 plan 撰寫(10 章節) | — | 30 min | — |
| 後端 Larastan + baseline + CI | 4h | ~30 min | **8x** |
| 前端 ESLint + Prettier + TS strict + CI | 4h | ~25 min | **9.6x** |
| ADR-0002 + ADR-0003 | — | ~30 min | — |
| Phase 2 Points plan v0.1 | 2-3h | ~30 min | **4-6x** |
| **整個上午** | — | **~2.5 小時** | — |

---

## 📊 累計戰績(4/28 + 4/29 連兩天)

| 日期 | 主題 | 估時 | 實際 |
|---|---|---|---|
| 4/28 早 | Phase 1 RBAC + Filament UI | 3h | ~1h |
| 4/28 晚 | 小尾巴 4 連發(T5.1 + Filament Nav + NotificationDelivery + PR Review)| 4-5h | ~1.3h |
| 4/29 早 | W2 Static Analysis 後+前 + Phase 2 plan | 1.5 工作天(~12h) | ~2.5h |
| **3 個 burst 累計** | — | **~25h** | **~5h** |

平均 **~5x 倍速**。SENIOR Phase 1 + ENGINEERING W1+W2 全部完成,Phase 2 plan 就位。

---

## 📊 Commit 序列(今早)

### ez_crm(後端)
```
6f187e0  docs(plan): add Points integration plan v0.1 draft (SENIOR Phase 2.1)
ec57d85  docs(adr): record ADR-0003 — frontend ESLint + Prettier + TS strict (W2 frontend)
175e9d9  feat(ci): introduce Larastan level 5 with baseline (ENGINEERING W2 backend)
4f840cc  docs(plan): add Static Analysis (PHPStan + ESLint) plan for ENGINEERING W2
```

### ez_crm_client(前端)
```
59a7598  style: apply prettier --write across src/ (W2 ESLint setup follow-up)
9f6587a  feat(tooling): introduce ESLint + Prettier + TS strict + frontend CI (W2)
```

### CI 狀態
- ez_crm `Frontend` workflow:🟢 first run green(50s)
- ez_crm `PHPUnit` workflow:🟢 with new PHPStan step(1m 15s,PHPStan ~30s + PHPUnit ~4min)

### 測試
- 後端:**215 tests / 689 assertions**(無變動 — 今早只動 infra/config 沒動 code)
- 前端:0 tests(Vitest 留作 stretch / Phase 2 開始時補)

---

## 🎁 亮點

- **「規範 + 工具 = 紀律」具體實踐**:ESLint 加 `vue/no-restricted-syntax` 禁 static inline style,寫在 ADR-0003 — 直接對照 4/28 討論 Linky360 codebase 3,554 處 inline style 的反例。**寫成程式碼層守線**而非「文件規範」就是這個哲學的展現。
- **後端 0 / 前端 baseline 反差很有趣**:後端 Larastan 凍結 63 條 Eloquent magic,前端 TS strict + ESLint 0 error 直通。「**dynamic vs explicit 的型別系統差異**」具體化在數字上。
- **Phase 2 plan 不直接動 code**:對齊 4/28 的紀律 — plan → ADR → 實作。Points 涉及錢相關(冪等、race、不可負餘額),沒對齊就動是高風險。寫 plan 1 小時後續省 1-2 天 rework。
- **Phase 2 plan 的 6 個 review 問題**:刻意把「業務決策」(過期機制)跟「工程偏好」(amount 用 bigInteger)分開,讓 Kevin 拍板路徑清晰。

---

## 🔜 下一步候選

對齊 STATIC_ANALYSIS_PLAN.md 跟 POINTS_INTEGRATION_PLAN.md:

1. **Phase 2 Points 開動**(estimated 5-7 days)— 等 Kevin 答 6 個 review 問題升 v1.0
2. **W2 baseline 消化第一波**(後端 8 個 model relation type hint,清掉 ~25 條 baseline,半天)
3. **ENGINEERING W3 — Pre-commit hook**(husky / commitlint,deadline 2026-05-12,還有 13 天)
4. **Filament 補 NotificationDeliveryResource 的 charts**(Phase 8.0 SMS 留下的 stretch)
5. **Vitest 第一個 component test**(W1 漏的前端 test,Phase 2 開始前補上)

Kevin 連戰 2 天的爆量後,**今天下午建議休息**。Phase 2 留週四(明天)開始,進入「正常步調」(每天 4-6 小時,不再 burst)。
