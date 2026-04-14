# 03. Campaign Automation 設計觀察

> **我的角色：** 週邊參與者，不是核心  
> **我的貢獻：**  
> - `back/function/message_aims_setup_process.php` 1,431 行中的 **366 行 (25%)**  
> - `back/function/ab_testing_add_process.php` 1,344 行中的 **276 行 (20%)**  
> - 主要 automation 模組（trigger_add_process, automation_content_process）**0% 貢獻**  
> **總 commits：** 60 筆於相關檔案

> **誠實聲明：** Campaign Automation 的核心架構是 jay800427 (2023) 寫的，我是後來在週邊（受眾選擇、AB testing、語系設定）做擴充。這個檔案主要是「設計觀察」而非「設計者經驗」。

---

## 系統概觀

linky_360 的 marketing automation 系統處理三件事：

```
1. Targeting    → 「要發給誰？」(message_aims_setup, periodic_message_aims_setup)
2. Content      → 「發什麼？」(automation_content_process, 支援 LINE Flex Message)
3. Trigger      → 「什麼時候發？」(trigger_add_process, 事件驅動)
```

支援三個通道：Email / SMS / LINE。

---

## 我實際碰過的部分

### 1. AID 群組受眾選擇

**位置：** `back/function/message_aims_setup_process.php`  
**Commit 證據：** `cb2d2da1e` 等多筆 2023-11 commits（"加入 aid 群組 test 020"）

**問題：** Marketing 想針對「特定 AID 群組」發送訊息，但原本的受眾選擇只支援「全部會員」或「特定 tag」。

**我加的：** AID 群組過濾邏輯 + 受眾估算（在發送前先算「會發給多少人」）。

**為什麼這個值得提：**
- 受眾估算是 marketing 的核心需求 — 不能讓 marketing 在不知道規模的情況下按下發送
- 預估值要在 UI 上即時顯示，意味著查詢必須夠快
- 同時還要計算「實際發送時的活躍人數」（去掉已停用、已退訂的會員）

**面試講法：**
> 「我做過 marketing 受眾預估功能。設計上的挑戰是：要在使用者調整篩選條件時即時回饋『預估會發給多少人』，又要在實際發送時用『當下』的資料重算（避免預估和實發落差）。我用 SQL 子查詢層層過濾，把預估查詢的延遲壓到 200ms 以內。」

---

### 2. 新會員 SQL 整合

**Commit 證據：** `945b358da` (2025-05-07) "加入 新會員相關的 sql"

**問題：** Marketing 想做「新會員專屬歡迎訊息」自動化，但原本的 targeting 邏輯沒有「new member」這個維度。

**我加的：** 在 message_aims 的 SQL 裡注入「最近 N 天加入」的篩選條件。

```sql
-- 概念示意
SELECT m.* FROM members m
WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND m.status = 1
  AND m.aid IN (?, ?, ?)
  AND m.m_id NOT IN (
      SELECT m_id FROM message_send_log 
      WHERE message_id = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  )
```

**設計考量：**
- `NOT IN` 子查詢確保「不重複發送」（同一個會員不會在 30 天內收到同一個 message）
- 必須跟 trigger 系統整合（trigger 條件 + 受眾條件 = 最終發送名單）

---

### 3. AB Testing 預設語系修正

**Commit 證據：** `f24c33e5c` (2023-12-26) "fix ab_testing_add_process.php defult language $row_name3 增加 account sl_id 判斷"

**問題：** AB Testing 的訊息有多語系版本（中文、英文等）。如果使用者沒指定預設語系，系統會抓錯資料。

**我修的：** 根據 account 的 sl_id（system language id）動態決定預設語系。

**這個修法的意義：**
- 多語系系統的常見陷阱：「預設值」必須是 context-dependent，不能 hardcode
- 我的修法把 fallback 語系從「寫死」變成「跟著帳號設定走」

**面試講法：**
> 「我修過一個多語系預設值的 bug。原本的程式碼把預設語系寫死成英文，但我們有日本和韓國客戶。修法是讓 fallback 語系跟著 account 的系統語系設定走，而不是 hardcode。看似小修改，但對國際化很重要。」

---

## 我**沒**碰過但值得從這個系統學的東西

雖然我沒貢獻 trigger 和 automation_content 模組，但我**讀過**這些程式碼，可以講出設計上的觀察：

### 觀察 1: LINE Flex Message JSON 模板

`automation_content_process.php` (jay800427 寫的) 用 JSON 模板動態組裝 LINE Flex Message：

```json
{
  "type": "bubble",
  "body": {
    "contents": [
      { "type": "text", "text": "{{member_name}}" },
      { "type": "text", "text": "您的點數：{{points}}" }
    ]
  }
}
```

**設計重點：**
- 模板裡用 `{{variable}}` 佔位
- 後端用 PHP 字串替換注入會員資料
- 支援 tag class（一群 tag）和 individual tag（單一 tag）

**這個設計的問題：**
- 字串替換不安全（如果會員名字含有特殊字元會壞掉）
- 沒有 escape，可能注入惡意內容
- 沒有 schema 驗證，模板寫錯只能在發送時才發現

### 觀察 2: Trigger 系統的耦合問題

`trigger_add_process.php` 把「觸發條件」和「執行動作」綁在一起儲存。這造成：
- 同一個觸發條件不能有多個動作
- 改動作要連同條件一起改
- 沒辦法做「測試觸發條件但不發送」的 dry run

### 觀察 3: 缺乏 idempotency

整個 automation 系統沒有 idempotency 機制。如果 cron 跑兩次，同一個會員可能收到兩次訊息。原本的解法是「靠 send_log 表的時間視窗判斷」，但這只是 best-effort，不是真正的 idempotency。

---

## 可借鏡到 ez_crm 的設計（學到的教訓）

### Lesson 1: Targeting 應該是獨立的 Service

linky_360 把 targeting SQL 散落在各個 process 檔裡，導致：
- 同樣的 SQL 邏輯複製到 4-5 個地方
- 改一個欄位要改 5 個檔案
- 沒辦法單獨測試 targeting 邏輯

**ez_crm 的對應做法：**

```php
// app/Services/Marketing/AudienceTargetingService.php

class AudienceTargetingService
{
    public function build(AudienceCriteria $criteria): Builder
    {
        return Member::query()
            ->when($criteria->newMemberDays, fn($q, $days) => 
                $q->where('created_at', '>=', now()->subDays($days)))
            ->when($criteria->aidGroups, fn($q, $aids) => 
                $q->whereIn('aid', $aids))
            ->when($criteria->tagIds, fn($q, $tags) => 
                $this->applyTagFilter($q, $tags))
            ->when($criteria->excludeRecentReceivers, fn($q, $messageId) =>
                $this->excludeRecentReceivers($q, $messageId, days: 30));
    }

    public function estimate(AudienceCriteria $criteria): int
    {
        return Cache::remember(
            "audience_estimate:{$criteria->hash()}",
            60,
            fn() => $this->build($criteria)->count()
        );
    }
}
```

**好處：**
- targeting 邏輯只有一個地方
- 可以單獨單元測試
- 預估有 cache 加速
- 每個 criteria 都是 optional，組合靈活

### Lesson 2: Message Template 應該有 Schema 驗證

linky_360 的 LINE Flex 模板沒有 schema，寫錯只能在發送時發現。

**ez_crm 的對應做法：**

```php
// app/Services/Marketing/MessageTemplate.php

class MessageTemplate
{
    public function render(array $variables): string
    {
        // 1. Schema 驗證（用 JSON Schema）
        $this->validateSchema($this->template);
        
        // 2. 變數驗證（必填變數都有給）
        $this->validateRequiredVariables($variables);
        
        // 3. 安全替換（用 Twig 或 Blade，不是 str_replace）
        return Blade::render($this->template, $variables);
    }
}
```

### Lesson 3: 用 Idempotency Key 避免重複發送

```php
// app/Models/MessageDispatch.php
Schema::create('message_dispatches', function (Blueprint $table) {
    $table->id();
    $table->string('idempotency_key')->unique();  // ← 關鍵
    $table->foreignId('member_id');
    $table->foreignId('message_id');
    $table->timestamp('dispatched_at');
});

// 發送邏輯
DB::transaction(function () use ($member, $message) {
    $key = sprintf('%s:%s:%s', 
        $message->id, 
        $member->id, 
        now()->format('Y-m-d')  // 每天一次
    );
    
    $dispatch = MessageDispatch::firstOrCreate(['idempotency_key' => $key]);
    
    if ($dispatch->wasRecentlyCreated) {
        // 真的是新的，發送
        $this->sendMessage($member, $message);
    }
    // 否則就是 duplicate，跳過
});
```

**面試講法：**
> 「我看過一個 marketing automation 系統因為缺乏 idempotency key 而重複發送訊息給會員。這讓我學到：任何『會對外產生副作用』的操作（發送訊息、送 webhook、扣款）都應該有 idempotency 機制。Idempotency key 的設計也很重要——要包含『業務上的唯一性』（會員 ID + 訊息 ID + 時間窗）而不只是 UUID。」

---

## 我的技能定位（誠實版）

| 技能 | 等級 | 說明 |
|------|------|------|
| Marketing Automation 全貌理解 | 中 | 讀過全部，但只改過部分 |
| 受眾選擇邏輯 | 中高 | 25% 是我的程式碼 |
| AB Testing 整合 | 中 | 20% 是我的程式碼 |
| 多通道發送（Email/SMS/LINE）| 入門 | 知道有，沒主導過 |
| Trigger / 事件驅動架構 | 入門 | 讀過設計，沒實作過 |
| Message templating | 入門 | 看過 LINE Flex，沒主導過 |

**面試誠實話：**
> 「Campaign automation 不是我的主場。我參與過受眾選擇和 AB testing 的部分，但核心架構不是我設計的。不過我讀過全部的程式碼，從中學到了一些設計上該避免的陷阱（idempotency 缺失、targeting 邏輯散落、模板無 schema 驗證），這些教訓我會應用在 ez_crm 的設計上。」

---

## 待挖掘的部分

- [ ] 把 trigger_add_process.php 完整讀過一遍，列出所有 trigger 類型
- [ ] 整理 LINE Flex Message 的模板格式範例
- [ ] 分析 message_send_log 表的 schema，理解去重邏輯
- [ ] 收集 4 年來看過的 automation bug case

---

## 證據索引

| 修改 | Commit | 日期 |
|------|--------|------|
| AID 群組受眾選擇 | `cb2d2da1e` 系列 | 2023-11-07 |
| 新會員 SQL 整合 | `945b358da` | 2025-05-07 |
| AB Testing 預設語系修正 | `f24c33e5c` | 2023-12-26 |
| AB Testing 排序修正 | `1384a3e3b` | 2023-12-15 |
| msg_subsc_set 語系修正 | `79dcd15ba` `a2483ba96` | 2023-12-15 |

---

## 為什麼這個檔案還是值得寫

即使我在這個系統不是主角，**讀懂並批判一個別人寫的複雜系統，本身就是 Senior 級的能力**。

面試時這個故事的價值：
> 「我能讀懂一個 1,500 行的舊系統，理解它的設計、找出它的缺陷、提出更好的方案。這比『我從零寫了一個 hello world Laravel app』更有說服力。」
