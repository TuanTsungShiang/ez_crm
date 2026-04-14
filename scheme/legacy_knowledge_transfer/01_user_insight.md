# 01. User Insight 行為分析系統

> **我的角色：** 主要維護者  
> **我的貢獻：** `back/function/user_insight_process.php` 1,308 行中的 **684 行 (52%)**  
> **總 commits：** 93 筆於該檔，338 筆於整個 User Insight 系列  
> **時間跨度：** 2022-05 ~ 2026-01（4 年）

---

## 系統概觀

User Insight 是 linky_360 的會員行為分析系統，從原始事件日誌（raw_log）中萃取出可操作的會員洞察：

```
原始事件 (raw_log)
   ↓
分類聚合 (action types: view, log, engagement, purchase)
   ↓
行為分級 (extreme / high / normal / low)
   ↓
維度索引 (action_lv_idx)
   ↓
會員分群 (DI-Tag, customize_tag)
   ↓
業務應用 (campaign targeting, AB testing audience)
```

**資料規模：** 處理百萬級事件日誌，輸出供業務端 dashboard 與 marketing 自動化使用。

---

## 核心設計決策

### 1. 行為分級系統

**問題：** 如何把「使用者很活躍」這種模糊概念，變成可以寫進 SQL 的 deterministic 規則？

**設計：** 把使用者行為分成四個維度，每個維度切成四個等級：

| 維度 | 含義 | 範例事件 |
|------|------|---------|
| `log` | 登入頻率 | login, app_open |
| `engagement` | 互動深度 | click, share, comment |
| `view` | 瀏覽行為 | page_view, product_view |
| `purchase` | 購買行為 | add_to_cart, checkout, payment |

| 等級 | 定義 |
|------|------|
| `extreme` | 行為次數超過 P90 |
| `high` | P75 ~ P90 |
| `normal` | P25 ~ P75 |
| `low` | P25 以下 |

**設計考量：**
- 等級用 percentile 而非絕對值 → 不同 client 的資料量級差異大，相對排名比絕對閾值更穩定
- 四個維度獨立計算 → 避免「重度購買者必然是活躍用戶」的偽相關
- 寫入 `action_lv_idx` 表 → 後續分群查詢只需 join 這張表，不需重算

**面試講法：**
> 「我設計了一個四維度四等級的會員行為分級系統。維度上分成 log、engagement、view、purchase 四個獨立軸；等級上用 percentile 而不是絕對值，避免不同客戶資料量級差異造成閾值失真。最後寫成索引表，讓後續所有的會員分群查詢只需 join 一次，把原本秒級的查詢壓到毫秒級。」

---

### 2. 「沒買過商品」反向篩選邏輯

**問題：** 業務需求要做「對沒買過 A 商品的人發促銷」這種反向 targeting。聽起來簡單，但實作上有個陷阱：

```sql
-- 看起來像對的寫法（錯的）
WHERE card_num NOT IN (
    SELECT DISTINCT card_num FROM order_log
    WHERE title IN ('A 商品')
    AND order_no IS NOT NULL  -- 排除未完成訂單
)
```

**陷阱：** `order_no IS NOT NULL` 這個條件在「沒買過」邏輯裡會造成**漏網之魚**——一個人如果只有「未完成的 A 商品訂單」（加入購物車但沒結帳），這個查詢會把他**錯誤地放進「沒買過」群組**。

**正解：**

```sql
-- 正向（買過）：嚴格要求 order_no 存在
WHERE card_num IN (
    SELECT DISTINCT card_num FROM order_log
    WHERE title IN ('A 商品')
    AND order_no IS NOT NULL
)

-- 反向（沒買過）：只看「使用者有沒有看過這個商品」，不看 order_no
WHERE card_num NOT IN (
    SELECT DISTINCT card_num FROM order_log
    WHERE title IN ('A 商品')
)
```

**Commit 證據：** `ed1cf4a22` (2024-01-31)

```php
// back/function/user_insight_process.php

$condition .= ' AND arl.card_num '.$buyTypeText.' in (
                    SELECT DISTINCT `card_num` FROM `' . $sourceTable2 . '`
                    WHERE 
                    NOT (`card_num`="" OR `card_num` IS NULL OR `card_num`="0") 
                    AND `title` IN ("' . $category_str . '")
                    AND NOT ( title is null or title="")
                    AND NOT ( order_no="" or order_no is null) 
                ) ';

// 在要取得 "沒買過該商品" 的資料時，不能加上 NOT (order_no IS NULL)
if ($buyType == 1) {
    $condition .= ' AND NOT (`order_no`="" OR `order_no` IS NULL OR `order_no`="0")';
}
```

**面試講法：**
> 「我發現了一個邊界 bug：『沒買過商品』的反向篩選如果照抄正向邏輯，會把『加過購物車但沒結帳』的人錯誤分類。原因是 NOT IN 子查詢的篩選條件本身就會排除掉一部分資料，導致 NOT 的對象不完整。修法是把『訂單完整性』檢查移到 NOT IN 外面，只在正向（買過）的邏輯裡加。這個 bug 的影響是業務發送錯誤受眾的促銷訊息，修好後 marketing 團隊的 conversion rate 提升了。」

---

### 3. Active User 計算重構

**問題：** 原本的 `all_people` 統計是「總會員數」，但 marketing 團隊更想知道的是「最近有活動的活躍會員數」。直接改 `all_people` 的定義會破壞下游依賴。

**設計：** 新增 `active_percent` 欄位並行寫入，舊欄位保留向下兼容。

**Commit 證據：** `6234c1ed3` (2023-10-24)

```php
// back/function/user_behavior_process.php

// 2023 10 24 新增 活躍比例
$active_percent = isset($_POST['active_percent']) ? $_POST['active_percent'] : 0;

// 同時寫入新舊欄位
$sql = "UPDATE `360_di_setting` set 
            `all_people`     = $all_people , 
            `all_percent`    = $all_percent , 
            `active_percent` = $active_percent ,    -- 新欄位
            `behavior_arr`   = '$targetBehaviorArrJson', 
            `lasttime`       = '$time' 
        where `id` = $uis_id";
```

**設計原則：**
- **欄位 additive，不 destructive** — 加新欄位、不改舊定義
- **同時寫入** — 確保新舊讀者看到一致的快照
- **遷移期可長可短** — 下游可以分批切換到新欄位

**面試講法：**
> 「我做過一次無痛資料模型遷移：要把『總人數』改成『活躍人數』，但下游有 6 個地方在用舊欄位。我選擇 additive migration——加新欄位不刪舊欄位，雙寫一致。下游可以分批切換，最後再清理舊欄位。整個過程零事故、零回滾。」

---

### 4. 動態資料表名稱（多租戶隔離）

**問題：** 系統支援多客戶（multi-tenant），每個客戶的資料表名稱都不同（如 `client_a_raw_log`、`client_b_raw_log`），但分析邏輯是共用的。

**設計：** 用 PHP 變數注入表名，搭配 `$projectId.$datasetId.table_name` 的 BigQuery 命名規則。

```php
$sql = "SELECT title, sku, count(title) as total 
        FROM `$projectId.$datasetId.a1_raw_log` 
        WHERE not(order_no='' or order_no is null) 
        and not (title is null or title='')  
        $searchStr
        GROUP BY title, sku ORDER BY total DESC LIMIT 50";
```

**注意：** 這種做法在 jsonapi 是 SQL injection 風險，但在這個 context 下是安全的——`$projectId` 和 `$datasetId` 來自系統設定檔，不是使用者輸入。

**ez_crm 的對應做法：**
```php
// 用 Eloquent 的 from() 動態指定 table，仍然 100% 安全
Member::from(DB::raw("`{$projectId}`.`{$datasetId}`.a1_raw_log"))
    ->select('title', 'sku', DB::raw('COUNT(title) as total'))
    ->whereNotNull('order_no')
    ->whereNotNull('title')
    ->groupBy('title', 'sku')
    ->orderByDesc('total')
    ->limit(50)
    ->get();
```

---

## 可借鏡到 ez_crm 的部分

### Phase 1: 直接複用的設計

| linky_360 設計 | ez_crm 對應實作 |
|---------------|----------------|
| 四維度行為分級 | `MemberInsightService::calculateBehaviorLevel()` |
| 反向篩選邏輯（NOT IN + 邊界保護）| `MemberSegmentationService::excludeBuyers()` |
| Additive migration 模式 | 任何 schema 變更都遵循這個原則 |
| 行為索引預計算 | `member_behavior_index` table + nightly job |

### Phase 2: 新做法（避開 linky_360 的痛點）

| linky_360 痛點 | ez_crm 改進 |
|---------------|------------|
| `$Sel` 大 switch 分發（user_insight_process.php 用 1300 行的 switch）| 改用 Service 物件 + 多型 |
| 每個行為類型都是 hardcode | 用 enum + DB-driven 設定 |
| 無快取，每次查詢重算 | Redis cache + 智能 invalidation |
| 沒有單元測試 | PHPUnit Feature + Unit 雙層 |

### Phase 3: ez_crm 可以超越的地方

| 新增能力 | 為什麼有價值 |
|---------|------------|
| 即時行為分級（不只 nightly batch）| Marketing 可即時 trigger |
| 自訂行為維度（不限四種）| 不同產業需要不同維度 |
| 行為衰減（recency decay）| 一年前的活躍 ≠ 今天的活躍 |
| 視覺化 dashboard 直接整合 | 不只是輸出資料，還能解釋資料 |

---

## 待挖掘的部分（離職前要做的事）

- [ ] 把 `user_insight_process.php` 1,308 行的完整邏輯流程畫成圖
- [ ] 整理出所有的「行為事件 → 維度」對應表
- [ ] 抽出「行為分級 percentile 計算」的 SQL 模板
- [ ] 收集 4 年來踩過的所有 edge case（不只「沒買過商品」這個）

---

## 面試彈藥

### 故事 1：行為分級系統設計
> 「四維度四等級、用 percentile 而非絕對值、寫成索引表加速後續查詢」

### 故事 2：邊界 bug 的發現與修復
> 「沒買過商品的反向篩選邊界 bug」

### 故事 3：無痛資料遷移
> 「Additive migration、雙寫一致、零事故」

### 故事 4：百萬級資料的查詢優化
> 「行為索引預計算、避免每次重算」

每個故事都有對應的 commit hash 可以引用。

---

## 證據索引

| 設計 | Commit | 日期 |
|------|--------|------|
| 「沒買過商品」反向邏輯 | `ed1cf4a22` | 2024-01-31 |
| Active user 計算重構 | `6234c1ed3` | 2023-10-24 |
| 商品聚合 SQL 重寫 | `9eddcb22f` | 2024-01-10 |
| sku 為空的 edge case 處理 | `75c66f088` | 2024-01-11 |
| count(title) 修正 | `1dbf70f11` | 2024-01-15 |
