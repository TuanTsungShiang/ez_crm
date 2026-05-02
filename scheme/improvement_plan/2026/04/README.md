# `scheme/improvement_plan/2026/04/` — 索引與導讀

> 本資料夾匯集 2026 年 4 月的 **工作日誌、提案、評審回應、長期路線圖、整合計畫**。
> 累積 22 份文件、約 5,600 行。新進維護者或未來的自己,先看這份 README 找路比較快。

---

## 🧭 從哪裡開始讀?

依你的角色選入口:

| 你是... | 建議先讀 |
|---|---|
| 🆕 **今天第一次打開這個 repo** | `SENIOR_ROADMAP.md` → `SESSION_LOG_2026_04_22.md`(最新一天)|
| 🔧 **今天/明天要開工** | 最新 session log + 對應的 integration plan |
| 🎯 **想看長期規劃** | `SENIOR_ROADMAP.md`(1025 行,最完整)+ `TOP_TIER_ROADMAP.md` |
| 💼 **面試 / 履歷場景** | `TOP_TIER_ROADMAP.md`(含 showcase 策略)+ `ENGINEERING_INFRASTRUCTURE_ROADMAP.md` |
| 🏗️ **準備做金流 / SMS / SCIM** | 對應的 `*_INTEGRATION_PLAN.md`(本資料夾或 `scheme/scim_2.0_reference/`)|

---

## 📂 文件分類

### 📅 Session Logs(時序工作日誌)

每日收工前紀錄當天完成項、關鍵決策、踩坑、明天接續。**想回溯「那件事什麼時候做的?」從這裡找。**

| 檔案 | 重點 |
|---|---|
| [STAGE_REVIEW_2026_04_02.md](STAGE_REVIEW_2026_04_02.md) | 階段性評審 |
| [SESSION_LOG_2026_04_14.md](SESSION_LOG_2026_04_14.md) | API Response Code 系統、Member Create |
| [SESSION_LOG_2026_04_15.md](SESSION_LOG_2026_04_15.md) | Member CRUD 完成、Filament 後台、Release v1.0 |
| [SESSION_LOG_2026_04_20.md](SESSION_LOG_2026_04_20.md) | Auth API Phase 0-3 + 5,`ez_crm_client` 前端初始化、vhost、Mailtrap |
| [SESSION_LOG_2026_04_21.md](SESSION_LOG_2026_04_21.md) | Phase 4 OAuth 四 provider 全通、SPA popup、/me 頁 |
| [SESSION_LOG_2026_04_22.md](SESSION_LOG_2026_04_22.md) | Webhook 系統上線(Phase 1+2)、4 個 event 類型、Filament 管理 |
| [SESSION_LOG_2026_04_23.md](SESSION_LOG_2026_04_23.md) | Webhook 補事件(MemberUpdated / Deleted / OAuthUnbound)、T6 SNS 推進 |
| [SESSION_LOG_2026_04_24.md](SESSION_LOG_2026_04_24.md) | Schedule assessment(behind analysis + 調整)、Phase 8 SMS skeleton |
| [SESSION_LOG_2026_04_28.md](SESSION_LOG_2026_04_28.md) | RBAC 上線(spatie + policies)、Filament Nav reshuffle、T5.1 OAuth-only password |
| [SESSION_LOG_2026_04_29.md](SESSION_LOG_2026_04_29.md) | PHPStan Larastan Level 5 baseline(63→10, -84%)、Phase 2.1 Day 1 Points schema + models |

### 🗺️ 長期路線圖(Roadmap)

策略性文件,回答「我們往哪走」。**想定位「目前做到哪一階段」看這些。**

| 檔案 | 範疇 |
|---|---|
| [SENIOR_ROADMAP.md](SENIOR_ROADMAP.md) | 1025 行的完整 senior 路徑,從技術 / 產品 / 職涯三軸 |
| [TOP_TIER_ROADMAP.md](TOP_TIER_ROADMAP.md) | 「頂標」視角,強調差異化賣點 |
| [ENGINEERING_INFRASTRUCTURE_ROADMAP.md](ENGINEERING_INFRASTRUCTURE_ROADMAP.md) | 工程基礎建設(CI / Docker / 可觀測性 / 文件化),8 週 48 小時學習路徑 |

**Roadmap 子計畫(drilldown)**:

| 檔案 | 範疇 |
|---|---|
| [CI_CD_IMPROVEMENT_PLAN.md](CI_CD_IMPROVEMENT_PLAN.md) | CI/CD 子主題 checklist(57 → 80 分)— ENGINEERING_INFRA 的子題 |
| [STATIC_ANALYSIS_PLAN.md](STATIC_ANALYSIS_PLAN.md) | PHPStan + ESLint 計畫,落地於 ADR-0003 與 Larastan Level 5 baseline |

### 🎯 整合計畫(Integration Plans)

具體 Phase 的實作藍圖,含 schema / 架構決策 / 踩坑清單 / 估時。**接近開工時才讀對應計畫。**

| 計畫 | Phase | 估時 | 狀態 |
|---|---|---|---|
| [POINTS_INTEGRATION_PLAN.md](POINTS_INTEGRATION_PLAN.md) | 2.1(點數)| - | 🟡 進行中(Day 1 schema + models 4/29)|
| [ECPAY_INTEGRATION_PLAN.md](ECPAY_INTEGRATION_PLAN.md) | 7(金流)| 7 天 | 📋 計畫完成 |
| [SMS_INTEGRATION_PLAN.md](SMS_INTEGRATION_PLAN.md) | 8(簡訊/三竹)| 4-7 天 | 🟡 skeleton 已 commit(4/24),真實 driver 未接 |
| `../../scim_2.0_reference/INTEGRATION_PLAN.md` | 9(SCIM 2.0)| 2.5 週 | 📋 計畫完成(跨資料夾) |
| `../../../work_log/20260422/ez_crm_webhook_plan.md` | 完成後更新 v0.2 | (已實作)| ✅ 完成(跨資料夾)|

### 🧩 提案與評審(Historical Proposals)

**做決策當下的歷史紀錄**。讀這些了解「為什麼選 A 不選 B」。現在多半已落地,當考古用。

| 檔案 | 內容 |
|---|---|
| [IMPROVEMENT_PLAN.md](IMPROVEMENT_PLAN.md) | 2026-04-02 的初版改進方案 |
| [MEMBER_CRUD_PROPOSAL.md](MEMBER_CRUD_PROPOSAL.md) | Member CRUD 架構設計 |
| [API_RESPONSE_CODE_PROPOSAL_KEVIN.xlsx](API_RESPONSE_CODE_PROPOSAL_KEVIN.xlsx) | Kevin 原始 status code 提案(excel) |
| [API_RESPONSE_CODE_PROPOSAL_CLAUDE.md](API_RESPONSE_CODE_PROPOSAL_CLAUDE.md) | Claude 的對應提案 |
| [API_RESPONSE_CODE_FINAL.md](API_RESPONSE_CODE_FINAL.md) | 合併最終版(S/V/A/N/I prefix 體系,已實作 `app/Enums/ApiCode.php`)|
| [PHASE_1_DETAILED_IMPLEMENTATION_PROPOSAL.md](PHASE_1_DETAILED_IMPLEMENTATION_PROPOSAL.md) | Phase 1 實作細節 |
| [PHASE_1_IMPLEMENTATION.diff](PHASE_1_IMPLEMENTATION.diff) | Phase 1 code diff 備份 |
| [PHASE_1_REVIEW_RESPONSE.md](PHASE_1_REVIEW_RESPONSE.md) | Phase 1 審查後的回應與修正 |
| [PHASE_2_DETAILED_IMPLEMENTATION_PROPOSAL.md](PHASE_2_DETAILED_IMPLEMENTATION_PROPOSAL.md) | Phase 2 實作細節 |
| [PHASE_2_IMPLEMENTATION.diff](PHASE_2_IMPLEMENTATION.diff) | Phase 2 code diff 備份 |
| [PHASE_2_REVIEW_RESPONSE.md](PHASE_2_REVIEW_RESPONSE.md) | Phase 2 審查後的回應與修正 |

> 📌 Phase 1 / Phase 2 **不要跟 Auth API 的 Phase 1/2 搞混**。這裡的 Phase 是 2026-04 初期的「改進方案第一波 / 第二波」,跟後來 `scheme/api/ez_crm_auth_api_plan.md` 的 Phase 系列是不同序列。

---

## 🎯 目前專案狀態對照

截至 2026-04-29 收工:

```
Phase 0-3  ✅ Auth 基礎(註冊 / 驗證 / 登入 / 忘記密碼)
Phase 4    ✅ OAuth × 4 provider(Google / GitHub / LINE / Discord)
Phase 5    🟡 /me 系列 + T5.1 OAuth-only password set flow (4/28)
Phase 6    🟡 SNS 綁定 events 已加(MemberUpdated / Deleted / OAuthUnbound, 4/23)
Phase 7    📋 ECPay 金流(計畫完成,未實作)
Phase 8    🟡 SMS skeleton 已 commit(4/24, SmsDriver + Log/Null + phone OTP),真實 driver 未接
Phase 9    📋 SCIM 2.0(計畫完成,未實作,計畫在別的資料夾)
Phase 2.1  🟡 Points integration Day 1 schema + models(4/29)
```

**不在編號內但已做**:
- Webhook 系統(4/22 發生),事件驅動架構,看 `work_log/20260422/ez_crm_webhook_plan.md` v0.2
- `ez_crm_client` 前端 SPA(4/21 起),看 `scheme/frontend_client/plan.md`
- **RBAC** 上線(4/28, commit 1e0fa69),spatie + policies + Filament canViewAny gating,WebhookHealthWidget 已 gate
- **PHPStan Larastan Level 5 baseline**(4/29, 63 → 10 errors, -84%),剩餘 10 個是第三方型別契約缺陷(Socialite / Sanctum / HasAbilities),非用戶程式碼債
- **PHPUnit GitHub Actions CI**(4/28),pr-review.yml 已關待 OPENAI_API_KEY

---

## 🔀 跟其他資料夾的對照

這個資料夾只收「中短期工作導向」文件。其他脈絡看這裡:

| 資料夾 | 放什麼 |
|---|---|
| `scheme/api/` | API 規格文件(ez_crm_auth_api_plan.md 等) |
| `scheme/schema/` | 資料庫 schema 文件(member.md)|
| `scheme/git_plan/` | Git 分支策略、token security SOP |
| `scheme/legacy_knowledge_transfer/` | 從 legacy 系統帶過來的 domain knowledge |
| `scheme/scim_2.0_reference/` | SCIM 參考資料 + `INTEGRATION_PLAN.md`(Phase 9)|
| `scheme/frontend_client/` | `ez_crm_client` 前端整體規劃 |
| `work_log/YYYYMMDD/` | 當日 planning + summary(比 session log 更即時,通常下班前整理到 `improvement_plan/`)|

---

## 🧹 維護慣例

1. **Session log 每天收工寫**,隔天起就當歷史
2. **Integration plan 開工前寫**,實作完成後標記狀態為 "已實作" 並指向對應 commit / merge
3. **Roadmap 文件可以定期更新**(月評 / 季評),但不要整個改寫 —— 舊版保留在 git history
4. **不要塞實作 code / 配置 / secrets 在這裡** —— 這是 plan 不是 repo。
5. 新文件加完**更新本 README** 的對應分類

---

## 🆕 加文件的 checklist

寫新文件時想一下:

- [ ] 檔名格式對嗎?`大寫底線分隔_計畫類`(ECPAY_INTEGRATION_PLAN.md),session log 用 `SESSION_LOG_YYYY_MM_DD.md`
- [ ] 頂部寫清楚:版本 / 建立日期 / 狀態 / 對應 phase
- [ ] 目的寫在前三段,讀者 30 秒要能判斷「這份文件跟我有沒有關」
- [ ] 本 README 要更新對應分類
- [ ] 跨資料夾引用用相對路徑(例 `../../scim_2.0_reference/INTEGRATION_PLAN.md`)

---

## 📌 歷史脈絡(給未來的自己)

這個資料夾在 2026-04-02 建立,當時的原始目的是**整理 Legacy code review 的改進建議**。後來隨專案進展,角色擴大成了:

```
早期(4 月上旬) → 單次改進提案 + 評審回應
中期(4 月中旬) → 每日 session log 成為慣例
晚期(4 月下旬) → 新功能前先寫 integration plan
```

若 5 月以後文件累積更多,**建議按 phase 分拆子資料夾**(例如 `2026/05/phase7_ecpay/`)避免單一資料夾爆炸。
