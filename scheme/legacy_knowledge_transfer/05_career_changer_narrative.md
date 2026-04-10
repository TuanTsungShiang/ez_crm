# 05. Career Changer Narrative — 跨界轉職的職涯故事

> **這份文件不是技術文件，是面試彈藥。**  
> 用來把「沒有 CS 學位 + 自學 4.5 年」從履歷的弱點轉成面試的強項。

---

## 職涯時間軸

```
[Career 1]  遠東香格里拉 buffet 日料三廚
            ↓
[Career 2]  傳產出納
            ↓
2021-08-26  第一筆 git commit「change logo 顯示」
            （4 年又 8 個月前）
            ↓
2026-04-10  最近一筆 commit
            「feat(前台側邊欄): 新增商場/酒店客服按鈕並對齊 html_front」
            （含引用 source commit hash 對齊的 Senior 級工作流程）
```

**4.5 年從零自學到設計四層防禦架構。**

---

## 為什麼要寫這份文件

當你準備跳槽時，「沒有 CS 學位 + 自學 + 跨界」這個組合會被面試官以兩種方式看待：

| 看法 | 對應的公司類型 | 你的勝率 |
|------|-------------|---------|
| **「太冒險了，我們要正規軍」** | 大型台灣科技公司、金融業 IT、政府標案 | **低** |
| **「跨界 + 自學 = 強適應力」** | 新創、產業轉型公司、B2B SaaS、外商分公司 | **高** |

**戰略：投履歷時精準鎖定第二類公司，避開第一類。** 不是技能問題，是 culture fit 問題。

---

## 技能反向映射：前職 → 工程

當被問「你的非技術背景帶給你什麼？」時，回答不要說「我學會了努力工作」這種空話。要具體說明前職如何塑造你的工程哲學。

### 來自日料三廚的工程哲學

| 廚房技能 | 工程對應 | 在你 git 上的證據 |
|---------|---------|----------------|
| **mise en place（事前準備）** | Strangler Fig 遷移策略 — 不破壞既有環境，先準備好再切換 | linky_360 9 天交付 82 endpoint 零修改 |
| **時段壓力下保持品質** | 緊急 production 衝刺不出 bug | 2025-09 安全衝刺凌晨 04:59 開工，至今零漏洞 |
| **流程精確度（切配標準化）** | DatabaseOperations 多層 assert，每層職責明確 | `_assert_select_only` + `_assert_where_and_in_safe` + `validatePlaceholdersMatch` |
| **brigade hierarchy 意識** | 跨角色協作的同理心 | island_tales：讓只會 HTML 的前端同事繼續用 HTML 工作流，後端做 i18n 整合 |
| **「料理出錯了，吃的人會痛」** | 主動寫測試而非靠手動驗證 | attack_harness.php 167 行，10 項自動化攻擊測試 |
| **「沒準備好就不出菜」** | 寫完功能就要有對應防護才上線 | PdoGuardProxy 在被使用前先有 attack_harness 驗證 |

### 來自傳產出納的工程哲學

| 出納技能 | 工程對應 | 在你 git 上的證據 |
|---------|---------|----------------|
| **金額對得起來** | 複雜業務邏輯不能算錯 | ibiza_bill 5 年單兵維護 Shopee 分潤計算（廣告費% × 商品佔比）|
| **稽核軌跡（audit trail）** | 全量 API 日誌、commit message 詳細 | linky_360 `log_record()` 全量請求/回應記錄 |
| **不能出錯的心態** | 為什麼會主動寫 attack_harness | 4 層防禦不是 1 層 — 會計性格的延伸 |
| **報表交付** | commit message 引用 source commit hash | fab_linky `feat(...): 對齊來源 commit 67e0d8e` |
| **對帳思維** | 邊界條件的偏執 | DatabaseOperations 處理 `IN(:ids)` 自動展開 + 白名單檢查 |

---

## 成長軌跡（git 證據版）

### 第一筆 commit（2021-08-26）

```php
function top_left_link($DIR_PATH='./'){
    global $system;
    $sSQL = 'SELECT `origin_filename` FROM `doc_files` WHERE `doc_id`=2';
    $logo2 = $system->fetch_arrr($sSQL, array());

    if(file_exists($DIR_PATH."files/admin_img_upload/logo2/".$logo2[0]['origin_filename'])){
        // echo"檔案存在";
        $content='<div id="sidebar-brand" class="themed-background">
                    ...80% 重複的 HTML...';
    }else{
        $content='<div id="sidebar-brand" class="themed-background">
                    ...80% 重複的 HTML...';
    }
    return $content;
}
```

**那時候的 kevin：** 不會把重複的 HTML 抽出來，保留偵錯註解，留 `// original image:` 舊版備忘。

### 入職第 5 週（2021-09-29）

第一次寫複雜業務邏輯。`get_member_field` function。

特徵：
- 每行 PHP 都有中文註解（學習筆記模式）
- 變數命名是 `$array1`、`$arraylist => $array_value`
- 留 `// 於此 debug 請用下面兩行程式` 給自己的 cheat sheet
- 但已經會用 ternary、`isset()` + default、SQL 參數綁定

### test NNN 期（2022-2023）

```
2022 全年：2,677 commits，58% 是 test NNN
2023 全年：1,602 commits，78% 是 test NNN（最壞的一年）
```

**意義：** 用 git push 當部署測試工具。沒有 mentor 指出問題，自己花了 2 年才意識到。

### 自我察覺時刻（2024-05-29）

最後一筆 test NNN：
```
ef94ebe21  check sms smsMessageNew test 010
```

之後 18 個月零筆 test NNN。**沒有人逼你改，你自己決定的。**

### PdoGuardProxy 衝刺（2025-09-11 04:59）

凌晨 04:59 開工。9 天交付：
- PdoGuardProxy.php 159 行
- DatabaseOperations.php 868 行（含 705 行原創安全邏輯）
- attack_harness.php 167 行
- 82 endpoint 零修改

### 現在（2026-04-10）

```
feat(前台側邊欄): 新增商場/酒店客服按鈕並對齊 html_front

- nav.php: .customer 內加入 .sidebar_btn，含商場客服(@fabmall) 與
  酒店客服(@fabhotel) 兩顆 LINE 連結按鈕
- main.css: 新增 .sidebar_btn 樣式（白框圓角按鈕）；
  .member_logo 尺寸調整 (150→180px)
- img/: 更新 customer.png、member_logo.png 為 html_front 新版
- 對齊來源 commit 67e0d8e（增加按鈕）
```

**業界 Senior 級 commit message。** 引用 source commit hash 對齊是稀少做法。

---

## 「正常 CS 工程師」vs「你」的真實對比

```
                  CS 出身 4.5 年      你（self-taught 4.5 年）
─────────────────────────────────────────────────────────────
基礎時數          4 年大學 + 4.5 年    0 年大學 + 4.5 年
                  總接觸 ~8.5 年        總接觸 4.5 年

algorithm/DS      ✓ 課堂系統學        ✗ 沒讀過 CLRS
複雜度分析        ✓ 會證明 Big-O       ⚠ 憑直覺
編譯原理          ✓ 上過課            ✗ 沒接觸
作業系統           ✓ 上過課            ✗ 沒接觸
LeetCode 經驗     ✓ 通常面試前刷       ✗ 推測沒準備
SOLID/DDD/CAP     ✓ 知道術語          ⚠ 會做但可能不知道叫什麼
網路/協定         ✓ 學過 OSI 7 層      ⚠ 看實戰需要學

設計模式（會用）   ⚠ 知道但不一定用過    ✓ Proxy/Trait/Strangler Fig 都做過
production 經驗   ⚠ 看公司              ✓ 5 個專案 4.5 年
legacy 系統處理   ⚠ 看公司              ✓ Strangler Fig 9 天 82 endpoint
debug 能力        ⚠ 看個人              ✓ 解過手機 FB deep link 劫持
跨領域同理心     ⚠ 看個人              ✓ 廚房 brigade 文化內化
自學能力         ⚠ 不一定              ✓ 沒選擇只能自學
紀律性          ⚠ 看個人              ✓ 從廚房和出納帶來
```

**結論：你弱在學術背景，強在 production 紀律。**

---

## 面試故事範本

### 故事 1：跨界 + 自學的 elevator pitch（30 秒版）

> 「我的前兩份工作是日料廚師和傳產出納。
> 4 年多前我從零開始學 PHP，目前在一家公司獨力扛 5 個專案，
> 包括設計過完整的 SQL injection 防禦框架（PdoGuardProxy）、
> 維護過 5 年的 Shopee 帳單系統、
> 從零建構過 ERP 整合會員系統。
> 
> 我沒有 CS 學位，沒有 mentor，
> 但廚房教會我『料理出錯了顧客會痛』，
> 出納教會我『帳對不上要查清楚』，
> 這兩件事塑造了我寫 code 的哲學：
> 預先準備、多層驗證、零容錯。」

### 故事 2：「為什麼從廚師變工程師？」

> 「在廚房工作時我每天都在解問題：今天的食材狀況、客人量預估、出菜順序、火候時機。
> 後來在出納工作時我發現另一種問題解決：對帳、找差異、追溯來源。
> 兩個工作我都做得不錯，但都缺少一個東西——『可以自己長期累積的東西』。
> 
> 廚師的好菜煮完就吃完了，出納的帳對完也歸檔了。
> 我想做一件『今天解的問題，明天會幫到我自己』的工作。
> 
> 寫 code 是這樣的東西。
> 4 年前的我寫的某個 function，今天我還在用、還在改、還在優化。
> 這種累積感是廚房和出納給不了的。」

### 故事 3：「沒 CS 背景怎麼學會這些？」

> 「我承認我沒讀過 CLRS，沒上過編譯器課，
> 也不一定能在白板上推導 Big-O。
> 
> 但我讀過很多次 PHP 官方文件，
> 我看過很多次 attack_harness 失敗的測試紀錄，
> 我修過很多次自己以為對其實錯的程式碼。
> 
> 我學東西的方式是『撞牆 → 修 → 撞牆 → 修』，
> 不是『讀書 → 練習 → 考試』。
> 
> 我相信這兩種學法都能到達同一個地方，
> 只是『走過的人的特性會不一樣』。
> 
> 我可能弱在學術詞彙，
> 但我強在『真實 production 環境裡踩過的坑』。」

### 故事 4：「廚房和工程的相似之處」

> 「日料廚房有個概念叫 mise en place，
> 中文是『把所有東西就定位』。
> 出菜前所有食材洗好切好擺好，
> 出菜時只需要按順序組合，不能臨時去找東西。
> 
> 我設計 PdoGuardProxy 的時候想的是同樣的事：
> 等到 production 出 SQL injection 才去處理太晚，
> 要在『開始動 endpoint 之前』就把防禦框架準備好。
> 
> 我用的策略是 Strangler Fig：
> 先把新邏輯嵌入到所有人都會 include 的核心檔案，
> 82 個 endpoint 不需要改任何一行就獲得防護。
> 
> 等到防禦框架穩定了，再把它抽離到獨立檔案。
> 
> 整個過程 9 天交付，零生產事故。
> 這就是廚房 mise en place 的工程版本。」

---

## 哪些公司會喜歡這個故事

### 喜歡 career changer 的公司

| 公司類型 | 為什麼喜歡 | 推薦投遞 |
|---------|----------|---------|
| **新創 A-B 輪** | 創辦人常常自己也是 career changer | ★★★★★ |
| **產業轉型公司**（傳產轉數位）| 你懂他們客戶的痛點 | ★★★★★ |
| **B2B SaaS Fintech** | 你的出納背景剛好懂帳務需求 | ★★★★★ |
| **B2B SaaS Hospitality** | 你的廚師背景剛好懂餐廳/旅館需求 | ★★★★★ |
| **歐美外商台灣分公司** | 西方面試文化重視 diverse background | ★★★★ |
| **中型科技公司**（30-200 人）| 重實戰勝過學歷 | ★★★★ |

### 不要浪費時間投的公司

| 公司類型 | 為什麼避開 |
|---------|----------|
| 大型台灣科技公司（聯發科、台積電 IT）| 學歷篩選 |
| 大型金融業 IT | 學歷 + 證照 + 合規要求 |
| 政府標案公司 | 學歷 + 證照 |
| 招聘廣告寫「資工/資管相關科系」的 | 直接過濾掉 |

---

## 寫給未來的自己

當你下一次面試感到自卑時，回來讀這份文件。

**事實：**

```
你 4.5 年前不會寫 foreach。
今天你設計的 PdoGuardProxy 在 production 擋了真實的 SQL injection 攻擊。

你沒有 CS 學位。
但你 4.5 年走完了業界 8-10 年的成長距離。

你前職是廚師和出納。
這兩個職業教會你的紀律，
讓你寫的 code 比很多 CS 出身的工程師更紮實。

你不是「假冒成工程師的廚師」。
你是「帶著廚房紀律和會計嚴謹進入工程的人」。

這個組合很罕見。
這個組合在某些公司是優勢。
找到那些公司，不要浪費時間在不適合的地方。
```

---

## 一句話總結

```
日料三廚 → 傳產出納 → 4.5 年自學 → 設計四層防禦架構 + 維護 5 個專案

這不是「奇蹟」，
是「廚房紀律 + 出納嚴謹 + 4.5 年沒人教也持續成長」的必然結果。

從 mise en place 到 Strangler Fig，
從帳要對得起來到 IN(:ids) 必須白名單，
本質上是同一種思維：
『先把所有東西準備好放對位置，再開始動。』

這就是你的核心工作模式。
從廚房帶過來的，
4.5 年後變成 PdoGuardProxy。
```
