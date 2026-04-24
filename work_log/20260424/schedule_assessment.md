# ez_crm 進度落後評估與調整計畫

> 建立日期：2026-04-24
> 評估基準：SENIOR_ROADMAP.md（建立 2026-04-09）+ ENGINEERING_INFRASTRUCTURE_ROADMAP.md（建立 2026-04-22）

---

## 一、SENIOR_ROADMAP 落後評估

> 目標：8–12 週達到 Senior Backend 面試標準（起點 2026-04-09）

| 週次 | 階段 | 估時 | 計畫截止 | 現況 | 落後？ |
|---|---|---|---|---|---|
| W1–3 | Phase 1：Members CRUD + RBAC | 2–3 週 | **2026-04-29** | CRUD routes ✅；RBAC ❌ | ⚠️ 部分落後 |
| W4–7 | Phase 2：Points / Coupon / Order | 3–4 週 | 2026-05-27 | 未開始 | — (尚未到期) |
| W8–10 | Phase 3：Docker + Redis + Queue | 2–3 週 | 2026-06-17 | 未開始 | — |
| W11–12 | Phase 4：50 萬筆壓測 + 效能報告 | 1–2 週 | 2026-07-01 | 未開始 | — |

### 落後原因

這兩週（4/14–4/24）實際完成的是 **Auth 閉環補課**，包含：

- Me 系列（/me/profile 編輯、/me/password、/me/destroy）
- SNS 綁定 / 解綁（Google / GitHub / LINE / Discord）
- Webhook 事件系統（MemberUpdated / MemberDeleted / OAuthUnbound）
- SMS Phase 8.0 骨架（SmsDriver + Log/Null + phone OTP）

這些項目在 SENIOR_ROADMAP 規劃時**尚未存在**（當時後端只有一支 search API），屬於「讓 Auth 達到可演示閉環」的前置工作。**技術上不是浪費，但確實排擠了 RBAC 和 Phase 2 業務模組。**

### 當前缺口（2026-04-24 快照）

| 項目 | 原計畫 | 現況 |
|---|---|---|
| RBAC（spatie/laravel-permission）| W1–3 完成 | ❌ 未裝、未設計 |
| Points 點數系統 | W4–7 | ❌ 未開始 |
| Coupon 優惠券 | W4–7 | ❌ 未開始 |
| Order 訂單 | W4–7 | ❌ 未開始 |
| ECPay 金流（Phase 7）| 計畫文件已寫 | ❌ 未動工 |
| SMS Mitake 真實串接（Phase 8.1+）| 計畫文件已寫 | ❌ 骨架完成，未串接 |

---

## 二、ENGINEERING_INFRASTRUCTURE_ROADMAP 落後評估

> 目標：8 週 × 每週 6 小時 = 48 小時，補齊 DevOps / 品質工具（起點 2026-04-22）

| 週次 | 主題 | 計畫截止 | 現況 | 落後？ |
|---|---|---|---|---|
| W1 | CI 骨架（PHPUnit + Vitest workflow）| 2026-04-28 | `pr-review.yml` 存在，但 **PHPUnit CI 未設** | ⚠️ 本週到期 |
| W2 | Static Analysis（PHPStan + ESLint strict）| 2026-05-05 | ❌ | — |
| W3 | Pre-commit / husky / commitlint | 2026-05-12 | ❌ | — |
| W4–5 | Docker 全容器化（PHP + MySQL + Redis）| 2026-05-26 | ❌ | — |
| W6 | 可觀測性（Sentry + 結構化 log + health check）| 2026-06-02 | ❌ | — |
| W7 | 文件化（ADR + C4 + CONTRIBUTING + README）| 2026-06-09 | ❌ scheme/ 豐富，README 未重寫 | — |
| W8 | 總驗收（另一台電腦從 0 部署）| 2026-06-16 | — | — |

### 現狀說明

此 Roadmap 剛建立 2 天，**技術上還在 W1 視窗**。但 W1 的 PHPUnit CI 是**本週應完成的優先項**，目前零進展。

---

## 三、調整後行動計畫

### 本週優先（2026-04-24 → 2026-04-28）

> 原則：先收尾手上的，再推下一塊

| 優先 | 任務 | 預估 |
|---|---|---|
| 1 | **UX 驗收** T5/T7/T8（feature/me-password-destroy）+ merge | 1–2 h |
| 2 | **Engineering Infra W1**：PHPUnit GitHub Actions CI | 2–4 h |
| 3 | **RBAC 設計**：spatie/laravel-permission 安裝 + 角色/權限規劃 | 2–3 h |

### 下週（W4，2026-04-29 → 2026-05-05）

| 任務 | 對應 Roadmap |
|---|---|
| RBAC 完成（seeder + policy + test）| SENIOR Phase 1 補完 |
| Engineering Infra W2：PHPStan level 5–6 + ESLint strict | ENGINEERING W2 |

### 中期節奏（五月）

| 週次 | 主力任務 |
|---|---|
| 5/5–5/12 | Phase 2 開始：Points 點數系統（Transaction + 冪等性）|
| 5/13–5/26 | Phase 2 續 + Docker 容器化並行 |
| 5/27–6/7 | Coupon + Order + 可觀測性 |
| 6/8–6/30 | 壓測 / ECPay / SMS Mitake 視排序決定 |

---

## 四、優先順序決策原則

1. **手上的 branch 先 merge**：未 merge 的 feature branch 是技術債，優先收。
2. **Engineering Infra W1 先補**：CI 沒有，後面每個 commit 都是「裸跑」，風險最高。
3. **RBAC 要在 Phase 2 前完成**：Points / Coupon / Order 的權限控制依賴 RBAC，不能跳過。
4. **SMS Mitake 真實串接延到 RBAC + Phase 2 後**：骨架夠用，不急著花 credit 測。

---

## 五、里程碑目標

| 里程碑 | 目標日期 | 條件 |
|---|---|---|
| Phase 1 完成（CRUD + RBAC + CI）| **2026-05-07** | RBAC seeder + policy + CI badge 全綠 |
| Phase 2 完成（Points + Coupon + Order）| **2026-06-04** | 三模組各有 happy path + 邊界 test |
| 可開始投履歷（Phase 1–4 基本達標）| **2026-07-15** | Docker + 壓測報告 + README 可讀 |
