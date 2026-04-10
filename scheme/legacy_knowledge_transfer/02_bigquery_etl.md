# 02. BigQuery ETL Pipeline

> **我的角色：** 長期維護者 + DI-Tag 模組部分貢獻者  
> **我的貢獻：**  
> - `schedule/script_360_ditag.php` 1,041 行中的 **276 行 (26%)**  
> - `schedule/script_360_bigquery.php` 1,060 行中的 **98 行 (9%)**  
> - `schedule/script_360_overview_gen.php` 維護  
> **總 commits：** 73 筆  
> **時間跨度：** 2022-09 ~ 2025-11

> **誠實聲明：** 主架構是 max_elidot (2021) 和 kai29234523 (2020) 寫的。我是後來接手維護、修 bug、加新功能的人，不是設計者。但我修過的 bug 和加過的功能，都是真實的工程經驗。

---

## 系統概觀

linky_360 用 BigQuery 處理會員行為的 ETL pipeline，每日 cron 跑：

```
原始 raw_log
   ↓ Stage 1: 去離群值（quartile / IQR）
   ↓ Stage 2: 行為類別聚合
   ↓ Stage 3: 動作層級索引（action_lv_idx）
   ↓ Stage 4: 寫回 MySQL 維度表
```

**規模：** 每日處理百萬筆事件，跨多個 BigQuery dataset，支援 multi-region 部署。

---

## 我接觸過的核心技術點

### 1. Multi-Region BigQuery 部署

**問題：** BigQuery 的資料集（dataset）有 region 概念，跨 region 查詢成本高昂且可能失敗。原本的程式碼假設都在 US region，但客戶分布在亞洲，造成 latency 和 cost 問題。

**Commit 證據：** `9a462575b` (2022-11-08) `fd2e4c0da` (2022-11-09) `8dc0a3bd6` (2022-11-08)

**修改：** 把 `location` 參數注入到所有 `BigQueryClient` 實例和 query job 中。

```php
// inc/GoogleBigQuery.php (我的修改)
public function __construct($config) {
    $this->bigQuery = new BigQueryClient([
        'projectId' => $config['projectId'],
        'location'  => $config['location'],  // ← 新增
    ]);
}

// schedule/script_360_bigquery2.php
$GBQ = new GoogleBigQuery([
    'projectId' => $projectId,
    'location'  => $dataset_location,  // ← 從系統設定讀
]);
```

**為什麼這是 Senior 級的修改：**
- 不是寫新功能，是**修架構假設**
- 影響範圍跨多個檔案，要小心不能破壞現有 query
- 需要理解 BigQuery 的 region/location 概念

---

### 2. 除以零防護（Edge Case Handling）

**問題：** LTV 計算公式 `(訂單數 / 會員數) * 100` 在某些 dataset 會出現 `member_sum = 0` 的情況，導致 BigQuery 拋 division by zero 錯誤，整個 pipeline 中斷。

**Commit 證據：** `b3613d9f8` (2025-09-18)

```sql
-- 修正前
($order_count / $member_sum) * 100 as PR

-- 修正後
IF(($order_count) != 0, ($order_count / $member_sum) * 100, 0) as PR
```

**面試講法：**
> 「我修過一個影響整個 ETL pipeline 的 edge case：除以零導致 nightly batch 失敗。原本以為是資料問題，後來發現是某些客戶的 dataset 真的可能完全沒有會員。修法是在 SQL 層用 IF 包住除法，預設值設 0。看起來是 1 行的修改，但這 1 行救了我們的 SLA。」

---

### 3. DI-Tag 動態標籤生成

**位置：** `schedule/script_360_ditag.php`  
**我的貢獻：** 276 行（26%）

**核心邏輯：** 根據業務端定義的條件（DI-Tag 設定），把符合條件的會員寫入對應的標籤表。

**踩過的坑：**

#### Bug 1: $sourceTable4 寫死問題
**Commit：** `494bf0f9a` (2025-09-03)

> 修正 -524 $buyType 的編號誤用 -735 $sourceTable4 linky360 被寫死的問題

某段 SQL 把 dataset 名稱寫死成 `linky360`，導致換客戶時 ETL 會跑到錯的 dataset。修法是改用變數注入。

#### Bug 2: 資料表變更後的相容性
**Commit：** `1cca045ab` (2024-01-10)

> 修正 因 2022年11月11日 已不在新增資料至 b_usr_order_list

業務端在 2022-11 停用了某張中間表，但 DI-Tag 排程還在 query 它（query 不會報錯，只是回空值）。**bug 潛伏了 14 個月才被發現**——因為「沒結果」不會讓系統 crash，只會讓業務看到空的 dashboard。

**面試講法：**
> 「我修過一個潛伏 14 個月的 silent bug：某張中間表被廢棄後，依賴它的 ETL job 還在 query，回傳空值不會 crash，所以沒人發現。直到業務發現某個 dashboard 都是空的。這個故事讓我深刻理解 observability 的重要性——光有 error log 不夠，要有 data quality monitoring。」

---

### 4. ETL 階段間的資料一致性

**問題：** Pipeline 有 4 個階段，每階段寫到不同的中間表。如果某階段失敗，後續階段會用到不完整的資料。

**linky_360 的做法（接手時就有的）：**
- 每階段獨立執行
- 失敗就停，但不會自動 rollback 前面階段的中間表
- 靠 cron 每天重跑

**問題反思：** 這個做法在 BigQuery context 下是合理的（BigQuery 的 transaction 很貴），但會留下不一致的中間狀態。

**ez_crm 可以做更好的：**
- 用 Laravel Queue 的 chain job
- 失敗時自動標記中間表為「invalid」
- 下一次重跑時知道從哪個 stage 開始

---

## 可借鏡到 ez_crm 的設計模式

### Pattern 1: Multi-Stage Pipeline with State Tracking

```php
// app/Services/MemberAnalyticsPipeline.php

class MemberAnalyticsPipeline
{
    public function __construct(
        private RawLogStage $stage1,
        private OutlierRemovalStage $stage2,
        private AggregationStage $stage3,
        private IndexBuildStage $stage4,
    ) {}

    public function run(string $datasetId): PipelineResult
    {
        $context = new PipelineContext($datasetId);
        
        DB::transaction(function () use ($context) {
            $this->stage1->execute($context);
            $this->stage2->execute($context);
            $this->stage3->execute($context);
            $this->stage4->execute($context);
        });
        
        return $context->result();
    }
}
```

**比 linky_360 好的地方：**
- 每個 stage 是獨立 class，可以單獨測試
- 用 transaction 確保 atomicity
- Pipeline context 帶著 state 跨 stage，不用全域變數

### Pattern 2: 統計學處理（IQR + Standard Deviation）

雖然這部分不是我寫的（max_elidot 寫的），但我讀過理解過。**值得搬到 ez_crm 上重做一次：**

```sql
-- linky_360 用 BigQuery 的做法
WITH stats AS (
    SELECT
        PERCENTILE_CONT(value, 0.25) OVER() as q1,
        PERCENTILE_CONT(value, 0.75) OVER() as q3
    FROM raw_log
)
SELECT * FROM raw_log, stats
WHERE value BETWEEN (q1 - 1.5 * (q3 - q1)) AND (q3 + 1.5 * (q3 - q1))
```

**ez_crm 用 MySQL 8.0 也能做：**

```sql
-- MySQL 8.0+ 支援 window functions
WITH stats AS (
    SELECT 
        PERCENT_RANK() OVER (ORDER BY value) as rank,
        value
    FROM events
),
quartiles AS (
    SELECT
        MAX(CASE WHEN rank <= 0.25 THEN value END) as q1,
        MAX(CASE WHEN rank <= 0.75 THEN value END) as q3
    FROM stats
)
SELECT events.* 
FROM events, quartiles
WHERE value BETWEEN (q1 - 1.5 * (q3 - q1)) AND (q3 + 1.5 * (q3 - q1))
```

**面試講法：**
> 「我接手過一個用 BigQuery 做使用者行為分析的 ETL pipeline，裡面有用 quartile + IQR 做離群值去除。雖然原本是 BigQuery，但這個統計邏輯用 MySQL 8.0 的 window functions 也能做。我在 ez_crm 用同樣的方法處理會員行為分析，成本只有 BigQuery 的 1/100。」

---

## 我的技能定位（誠實版）

| 技能 | 等級 | 說明 |
|------|------|------|
| BigQuery 基礎 query | 中高 | 4 年實戰 |
| BigQuery 進階優化（partitioning, clustering） | 中 | 接觸過但沒主導過 |
| Multi-region 部署 | 中 | 修過相關 bug |
| 統計學處理（quartile, stddev） | 中 | 讀懂 + 維護過 |
| ETL pipeline 設計 | **入門** | **是維護者不是設計者** |
| Async job management | 中 | 接觸過 BigQuery 的 polling 機制 |
| 大資料量處理 | 中 | 處理過百萬筆等級 |

**重要提醒：** 面試時不要把自己包裝成「設計者」。誠實說「我是長期維護者，部分模組是我擴展的」反而更可信，也更能說 4 年的真實故事。

---

## 待挖掘的部分

- [ ] 整理出 BigQuery pipeline 的完整資料流程圖
- [ ] 把 quartile / IQR / stddev 的處理邏輯抽出來做筆記
- [ ] 列出 4 年來修過的所有 ETL bug（不只上面三個）
- [ ] 計算我接手後 ETL 的失敗率變化（如果有資料）

---

## 證據索引

| 修改 | Commit | 日期 |
|------|--------|------|
| Multi-region location 注入（GoogleBigQuery.php）| `8dc0a3bd6` | 2022-11-08 |
| Multi-region 全面套用 | `9a462575b` | 2022-11-08 |
| LTV 除以零修正 | `b3613d9f8` | 2025-09-18 |
| DI-Tag $sourceTable4 寫死修正 | `494bf0f9a` | 2025-09-03 |
| 廢棄表潛伏 bug 修正 | `1cca045ab` | 2024-01-10 |
| 沒買過商品邏輯整合到 DI-Tag | `6e4460b87` | 2024-01-25 |
