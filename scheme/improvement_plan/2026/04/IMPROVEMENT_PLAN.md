# ez_crm 改進方案

> 版本：v1.0
> 建立日期：2026-04-02
> 來源：Codex 5.4 評估 + Claude Code 程式碼審查（交叉驗證）

---

## 現況評估

### 已完成且設計良好

| 項目 | 說明 |
|---|---|
| 資料骨架 | `members` 主表設計完整，含 SoftDeletes、`uuid`、`email/phone_verified_at`、`last_login_at` |
| 關聯設計 | group / profile / sns / tags 四組關聯清楚，FK 刪除策略合理（見 `scheme/schema/member.md`）|
| 分層架構 | Controller → Request → Service → Resource，職責清晰，沒有業務邏輯外漏 |
| 搜尋功能 | keyword / status / group / tag / gender / has_sns / 日期區間 / 排序 / 分頁，覆蓋度佳 |
| Schema 文件 | `scheme/schema/member.md` 與 `scheme/api/plan_member_search_api.md` 已有規劃依據 |
| 測試方向 | Feature Test 已驗證 response structure / keyword / status / has_sns / pagination / 422 |

### 產品價值現況

**中低 → 中**。資料底座已穩，但目前對外只有一支搜尋 API，且該 API 本身有安全問題。距離可支撐日常 CRM 營運仍需補幾個關鍵模組。

---

## 問題清單（嚴重度排序）

### P0 — 安全性（必須優先處理）

**1. 會員搜尋 API 完全公開裸露**

- 位置：[routes/api.php:22-24](../routes/api.php#L22)、[MemberSearchRequest.php:12](../app/Http/Requests/Api/V1/MemberSearchRequest.php#L12)
- 問題：`GET /api/v1/members/search` 沒有任何 middleware 保護；`authorize()` 直接回傳 `true`。任何人都能查詢全體會員的姓名、電話、Email。
- 解法：加上後台管理員認證 middleware（Sanctum token 或 Session），並在 `MemberSearchRequest::authorize()` 驗證操作者身份。

---

### P1 — 可執行性與可理解性

**2. README 是 Laravel 預設內容**

- 位置：[README.md:1-66](../README.md)
- 問題：完全沒有專案說明、安裝流程、資料表結構說明、API 用法、測試方式。對外協作或評估時會嚴重低估專案價值。
- 解法：重寫 README，至少含：專案目標、安裝步驟（`composer install` / migration / seed）、API 清單、測試指令。

**3. `vendor/` 未納入版控（正常），但缺乏安裝引導**

- 問題：`composer install` 後才能跑測試，這在早期多人協作時容易卡關。
- 解法：在 README 補齊，並考慮加一支 `Makefile` 或 `setup.sh` 腳本。

---

### P2 — 程式碼品質（功能正確，但有效能與防禦隱患）

**4. `tag_ids` 篩選產生 N 個 subquery**

- 位置：[MemberSearchService.php:51-53](../app/Services/MemberSearchService.php#L51)
- 問題：每個 `tag_id` 各加一個 `whereHas`，傳入 5 個 tag 就有 5 個 `EXISTS` subquery。資料量大時效能急劇下降。
- 解法：改用 `JOIN` 搭配 `GROUP BY` + `HAVING COUNT = n` 方式，一次 query 完成 AND 交集。

```php
// 改進前（現況）
foreach ($params['tag_ids'] as $tagId) {
    $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
}

// 改進後
$query->whereIn('members.id', function ($sub) use ($params) {
    $sub->select('member_id')
        ->from('member_tag')
        ->whereIn('tag_id', $params['tag_ids'])
        ->groupBy('member_id')
        ->havingRaw('COUNT(DISTINCT tag_id) = ?', [count($params['tag_ids'])]);
});
```

**5. `MemberSearchService` 的 `sort_by` 無獨立防禦**

- 位置：[MemberSearchService.php:21](../app/Services/MemberSearchService.php#L21)
- 問題：Service 直接把 `$sortBy` 傳進 `orderBy()`，依賴上游 Request 驗證。若 Service 未來被其他地方呼叫（Console command、Job、Test 直接 new），就可能有 SQL injection 風險。
- 解法：在 Service 內加白名單 guard：
```php
$allowed = ['created_at', 'last_login_at', 'name'];
$sortBy  = in_array($params['sort_by'] ?? '', $allowed) ? $params['sort_by'] : 'created_at';
```

**6. `members` 資料表缺查詢索引**

- 問題：`keyword` 搜尋跑 `LIKE '%...%'` 在 `name`、`email`、`phone` 上，沒有任何輔助索引。`status`、`member_group_id`、`created_at` 也會被頻繁過濾，未來資料量一大就會 full table scan。
- 解法（migration 中補索引）：
  - `members`: `(status)`, `(member_group_id)`, `(created_at)`, `(last_login_at)`
  - `member_tag`: `(tag_id)`, `(member_id, tag_id)` 複合索引
  - 中文 / 全文搜尋長期考慮 Full-Text Index 或 Meilisearch

---

### P3 — 測試覆蓋不足

**7. 以下情境目前沒有測試**

| 缺失情境 | 說明 |
|---|---|
| `group_id` 篩選 | 沒有測試 group filter 是否有效 |
| `tag_ids` 篩選 | 多標籤 AND 交集沒有測試 |
| `gender` 篩選 | 跨 profile join 沒有測試 |
| `created_from/to` 日期篩選 | 只有 validation 422，沒有資料正確性測試 |
| `sort_by` / `sort_dir` | 排序順序沒有驗證 |
| soft delete 隔離 | 已刪除會員是否不出現在搜尋結果 |
| 空資料情境 | 無任何資料時 response 結構是否正確 |
| 授權保護（P0 修完後） | 未授權存取應回 401/403 |

---

### P4 — 功能完整性（從「搜尋 demo」到「可操作 CRM」）

**8. 缺少核心 CRUD**

目前沒有以下 API，無法稱為可用的 CRM：
- 新增會員 `POST /api/v1/members`
- 查看單一會員 `GET /api/v1/members/{uuid}`
- 編輯會員 `PUT /api/v1/members/{uuid}`
- 停用/啟用 `PATCH /api/v1/members/{uuid}/status`
- 刪除（軟刪除）`DELETE /api/v1/members/{uuid}`

**9. 缺少標籤與分群管理 API**

- 標籤 CRUD、會員打標 / 移標
- 會員群組 CRUD

**10. 搜尋條件可補強的 CRM 高價值篩選**

- `email_verified` / `phone_verified`（驗證狀態）
- `last_login_from` / `last_login_to`（最近登入區間）
- `source`（來源渠道，待 schema 支援）
- tag 聯集（OR）vs 交集（AND）策略切換

---

## 優先執行順序

```
Phase 1 — 安全與可用性（現在就做）
  ├── [P0] 補後台認證 middleware，保護所有 CRM API
  ├── [P0] MemberSearchRequest::authorize() 驗證身份
  └── [P1] 重寫 README（安裝 / API / 測試流程）

Phase 2 — 程式碼品質（下一個 PR）
  ├── [P2] tag_ids 改用 JOIN + HAVING COUNT 方式
  ├── [P2] MemberSearchService 補 sort_by 白名單 guard
  └── [P2] migration 補 members / member_tag 查詢索引

Phase 3 — 測試補強（與 Phase 2 同步或緊接）
  └── [P3] 補齊 group / tag / gender / date / sort / soft_delete / empty / auth 測試

Phase 4 — 功能擴充（CRM 核心可用性）
  ├── [P4] 會員 CRUD API（新增 / 查看 / 編輯 / 停用 / 刪除）
  ├── [P4] 標籤管理 API（CRUD + 會員打標）
  └── [P4] 分群管理 API

Phase 5 — 進階 CRM 能力（之後再做）
  ├── 互動紀錄 / 備註
  ├── 搜尋條件擴充（驗證狀態 / 登入區間 / 來源渠道）
  └── 產品邊界決策：會員中心 vs 完整 CRM
```

---

## 技術債摘要表

| # | 類型 | 位置 | 嚴重度 | 估算工時 |
|---|---|---|---|---|
| 1 | 安全 | routes/api.php + MemberSearchRequest | P0 | 2-4h |
| 2 | 文件 | README.md | P1 | 1-2h |
| 3 | 效能 | MemberSearchService:51-53 | P2 | 1h |
| 4 | 防禦 | MemberSearchService:21 | P2 | 0.5h |
| 5 | 效能 | migrations（補索引） | P2 | 1h |
| 6 | 測試 | MemberSearchTest | P3 | 3-5h |
| 7 | 功能 | 會員 CRUD | P4 | 1-2 天 |
| 8 | 功能 | 標籤 / 分群管理 | P4 | 1 天 |

---

## 相關文件

- [schema 規劃](./schema/member.md)
- [搜尋 API 計畫](./api/plan_member_search_api.md)
- [分支策略](./git_plan/branching_strategy.md)
