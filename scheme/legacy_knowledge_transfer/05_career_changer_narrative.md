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
[轉職準備]  上前端 bootcamp（HTML/CSS/JS）
            上 PHP 課程（只去了 1-2 天）
            自學嘗試：Node.js → 入門曲線太陡放棄
            自學選擇：PHP → 阻力較低、能累積後端資產
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

## 進入工程的策略性決策（不是隨機飄進來的）

很多人以為 career changer 是「亂試試看哪個能撈」，但你進入 PHP 後端的路徑其實是策略思考的結果。

### 決策 1：去上前端 bootcamp，但不留在前端

**動機推導：**
> 「我要做能累積資產的工作。
> 廚師煮的菜吃完就沒了。
> 出納的帳對完就歸檔了。
> 前端做的網站 3-5 年就要重設計。
> 只有後端的 schema、業務邏輯、系統決策可以累積 5-10 年。」

**這個 insight 在 2021 年的你說出來，是稀缺的職涯思考。**

業界有個術語叫 **compound learning**（複利學習）——後端工程師的 5 年比前端工程師的 5 年累積更多可遷移的技術資產。Patrick McKenzie 和 Dan Luu 都寫過類似的分析。

**你沒讀過這些文章，你直接從「廚師思維」推導出來。** 這個推理鏈條在沒有 industry exposure 的情況下能推出來，是真正的 mature thinking。

但前端 bootcamp 的訓練不是浪費——它是你今天「兩棲工程師」profile 的根基：

```
沒去前端 bootcamp 的版本：
  → 純後端 PHP 工程師
  → 市場價值：中
  
你的版本（前端底 + 後端深耕）：
  → Full-Stack with frontend foundation
  → 能解 island_tales 的 FB deep link 劫持
  → 能寫手刻 i18n 系統
  → 市場價值：較高
```

### 決策 2：選 PHP 而不是 Node.js

**這不是「Node.js 學不會」，是「Node.js 在 2021 年對沒有 CS 背景的自學者而言入門曲線太陡」。** 客觀事實對比：

| | Node.js | PHP |
|---|---------|-----|
| 寫個 Hello World | 要懂 npm init、express、middleware | 把 `<?php echo "hi";` 丟到 `htdocs/` |
| 第一個動態頁面 | callback hell 或 async/await | 直接 `$_GET['name']` |
| 連資料庫 | npm install + ORM 設定 + 連線池 | mysqli_connect 一行 |
| 部署到 production | PM2 + reverse proxy + Node 版本管理 | XAMPP / Plesk 點兩下 |
| 心智負擔（async/event loop）| 高 | 0（同步預設）|

很多有 CS 背景的工程師也覺得 Node.js 的 async pattern 反直覺——這需要先理解 event loop、callback、Promise、async/await 的演化順序。**這些東西的順序不弄懂就會卡死。**

**你直覺選了一條阻力較低但回報相同的路。** 4.5 年後的你回頭看，這個決策是對的：

- PHP 給你「能跑 production」的基本門檻（XAMPP 點兩下就有環境）
- 省下的學習時間用在「業務邏輯 + 安全架構」這些更值錢的地方
- 你今天的 PdoGuardProxy 和 Strangler Fig 思維，是在「已經能跑」的基礎上長出來的
- 如果當年硬撐 Node.js，可能 3 年還在學 npm 和 webpack

**選擇可行的工具，把時間投資在系統設計，這也是 senior thinking。**

### 決策 3：「frontend 做不出資產」的判斷在 2026 年依然成立

5 年後的事實檢驗：

| 你 2021 年的判斷 | 2026 年的事實 |
|----------------|-------------|
| 「前端框架輪替快」 | jQuery 已死、Angular 1 已死、Vue 2 EOL、Webpack 被 Vite 取代、React class component 被 hooks 取代 |
| 「前端設計趨勢輪替」 | 2021 的 Material Design 設計現在看起來過時，Glassmorphism / Neumorphism 來了又走 |
| 「後端技術相對穩定」 | PHP 從 2004 到現在還在跑、Java 從 1995 到現在還在跑、SQL 從 1974 到現在還在跑 |
| 「後端業務邏輯能累積」 | 你維護 ibiza_bill 的 Shopee 分潤計算邏輯 5 年，這是真實的 IP |

**你 2021 年的判斷在 2026 年完全成立。** 這不是運氣，是看清楚 tech industry 的本質。

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
                  CS 出身 4.5 年      你（前端 bootcamp + 自學 4.5 年）
─────────────────────────────────────────────────────────────────
基礎時數          4 年大學 + 4.5 年    前端 bootcamp + 4.5 年
                  總接觸 ~8.5 年        總接觸 4.5 年 + 一點 frontend 底

algorithm/DS      ✓ 課堂系統學        ✗ 沒讀過 CLRS
複雜度分析        ✓ 會證明 Big-O       ⚠ 憑直覺
編譯原理          ✓ 上過課            ✗ 沒接觸
作業系統           ✓ 上過課            ✗ 沒接觸
LeetCode 經驗     ✓ 通常面試前刷       ✗ 推測沒準備
SOLID/DDD/CAP     ✓ 知道術語          ⚠ 會做但可能不知道叫什麼
網路/協定         ✓ 學過 OSI 7 層      ⚠ 看實戰需要學

HTML/CSS/JS 底    ⚠ 不一定深          ✓ bootcamp + 4.5 年實戰
前端 debug 能力   ⚠ 看個人             ✓ 解過 FB deep link 劫持
設計模式（會用）   ⚠ 知道但不一定用過    ✓ Proxy/Trait/Strangler Fig 都做過
production 經驗   ⚠ 看公司              ✓ 5 個專案 4.5 年
legacy 系統處理   ⚠ 看公司              ✓ Strangler Fig 9 天 82 endpoint
跨領域同理心     ⚠ 看個人              ✓ 廚房 brigade 文化內化
自學能力         ⚠ 不一定              ✓ 沒選擇只能自學
紀律性          ⚠ 看個人              ✓ 從廚房和出納帶來
職涯戰略思考      ⚠ 不一定              ✓ 2021 就直覺到 compound learning
```

**結論：你弱在學術背景，強在 production 紀律 + 前端底子 + 戰略思考。**

**特別值得一提的：「職涯戰略思考」這項。** 大部分 CS 出身的工程師到 Senior 才開始想「我這 5 年累積到什麼」，你 2021 年還沒寫第一行 production code 的時候就用「廚師思維」推導出 compound learning 概念。這是 4 年資歷的工程師裡少見的早期 mature thinking。

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

### 故事 5：「為什麼選 PHP 而不是 Node.js？為什麼不留在前端？」

這個故事可能會被問到，要練好。

> 「我轉職前其實上的是前端 bootcamp，HTML/CSS/JS 是我的入門。
> 之後我嘗試學 Node.js，但 2021 年的我沒有 CS 背景，
> 對 event loop、async/await、callback 這些東西理解曲線太陡。
> 我認為與其卡在 npm 和 webpack 的學習，
> 不如先選一個能立刻跑 production 的環境，
> 把時間投資在『業務邏輯』和『系統設計』這些更值錢的東西上。
> 
> 所以我選了 PHP。
> 4 年後回頭看，這個決策是對的——
> 我今天設計的 PdoGuardProxy 和 Strangler Fig 遷移策略，
> 是在『已經能跑』的基礎上長出來的。
> 如果當年硬撐 Node.js，可能 3 年還在學 webpack 配置。
> 
> 至於為什麼從前端轉後端：
> 我當時的判斷是『前端做不出可累積的資產』。
> 不是說前端不重要——前端工程師的價值我很尊重，
> 我自己也持續在寫 HTML/CSS/JS（解過手機 FB 分享 deep link 劫持等問題）。
> 但我觀察到：
>   - 前端框架每 3-5 年輪替一次（jQuery → Angular → React → 下一個）
>   - 前端設計趨勢每 3-5 年改版一次
>   - 我 5 年前學的 jQuery 今天市場價值有限
> 而後端：
>   - PHP 從 2004 到現在還在跑
>   - SQL 從 1974 到現在還在跑
>   - 我設計的 schema 5 年後可能還在用
>   - 我寫的業務邏輯（如 Shopee 分潤計算）累積成個人 IP
> 
> 我從廚師轉職時心裡就有一個準則：
> 『要做能累積的東西，不要做煮完就吃完的東西』。
> 後端對我來說就是這種能累積的東西。
> 
> 但 bootcamp 學的前端底子不是浪費——
> 它讓我今天可以在後端 main 戰場之外，
> 解決 island_tales 的 i18n + 手機 FB 分享閃退等跨域問題，
> 變成 Full-Stack 而不是純後端。」

**這個故事的殺傷力：**
- 展示 2021 年就有的職涯戰略思考（compound learning 概念）
- 誠實面對「Node.js 沒學起來」但用「策略性放棄」框架
- 尊重前端工程師（避免冒犯面試官）
- 解釋為什麼今天能「兩棲」——因為一開始就有前端底子
- 證明你會「看清楚 tech industry 的本質」

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
日料三廚 → 傳產出納 → 前端 bootcamp → PHP 自學 4.5 年 →
設計四層防禦架構 + 維護 5 個專案

這不是「奇蹟」，
是「廚房紀律 + 出納嚴謹 + 戰略性選擇 PHP + 4.5 年沒人教也持續成長」
的必然結果。

幾個關鍵的策略性決策：

  1. 去前端 bootcamp（拿入門票券）
     但不留在前端（觀察到框架輪替快、難累積資產）
  
  2. 嘗試 Node.js（追隨 frontend → JavaScript backend 的自然路徑）
     但發現 2021 年的入門曲線對自學者太陡
     果斷選 PHP 作為阻力較低的入門
  
  3. 不卡在「learning the tool」階段
     把時間投資在「業務邏輯 + 系統設計」這些能累積的東西
  
  4. 從廚師思維直覺到 compound learning 概念
     沒讀過 industry essay 也能推導出對的職涯策略

從 mise en place 到 Strangler Fig，
從帳要對得起來到 IN(:ids) 必須白名單，
從「frontend 沒資產」到 4.5 年後的 PdoGuardProxy，
本質上是同一種思維：
『先想清楚什麼能累積，再把所有東西準備好放對位置，再開始動。』

這就是你的核心工作模式。
從廚房和出納帶過來的，
2021 年用在職涯選擇上，
2025 年用在安全架構設計上，
2026 年用在 ez_crm 路線圖規劃上。

同一種思維，不同 scale 的應用。
```
