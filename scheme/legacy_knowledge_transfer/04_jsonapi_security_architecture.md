# 04. JSON API SQL Injection 防護架構（皇冠級作品）

> **我的角色：** 架構設計者 + 主要實施者  
> **我的貢獻：**  
> - `inc/PdoGuardProxy.php` **159 行 / 100%** （全新檔案，全部我寫的）  
> - `inc/DatabaseOperations.php` 868 行中的 **705 行原創安全邏輯**（含 541 行 security guards + 164 行 error handling）  
> - `jsonapi/config/attack_harness.php` **167 行 / 100%** （全新自動化測試套件）  
> - `jsonapi/config/database.php` constructor + 安全常數重寫  
> - `jsonapi/objects/` 14 筆 fetch_arrr 遷移 commits（17 個業務邏輯檔案）  
> **總原創安全程式碼：** ~1,005 行  
> **交付天數：** 9 天日曆天 / 7 個活躍天 / ~22 工時  
> **時間：** 2025-09-11 ~ 2025-09-19  
> **影響範圍：** 82 個 endpoint 零修改自動獲得防護  
> **至今安全漏洞回報：** 0

> **這份文件是面試的核心武器。** 每一段都對應一個可以講 5-10 分鐘的技術故事。

---

## 系統概觀

linky_360 的 JSON API 層原本是 2020 年用 vanilla PHP 寫的，沒有 SQL injection 防護。會員資料、訂單、點數、優惠券等業務操作全部用拼接 SQL 的方式執行：

```php
// 改造前的典型寫法
$sql = "SELECT * FROM members WHERE email = '" . $_POST['email'] . "'";
$stmt = $db->query($sql);  // 經典 SQL injection 漏洞
```

問題：

| 痛點 | 規模 |
|------|------|
| 沒有 SQL injection 防護 | 76+ 個 endpoint |
| 業務邏輯散落在 endpoint 和 objects/ 層 | 數萬行 |
| 沒有測試 | 0 |
| 沒有 review 機制 | 任何人都能 push 到 master |
| 無分支權限 | 我必須在 master 上直接改 |

我的任務：**在不破壞任何 endpoint 的前提下，從根部解決 SQL injection 問題。**

---

## 核心架構決策：Strangler Fig 策略

### 為什麼不能 big-bang 重寫

直接做法是：「建一個新的 ORM 層，要求所有 endpoint 改用它」。但這在 linky_360 的環境下是死路：

- **82 個 endpoint 都 include `database.php`**，改它就是改全部
- 沒有測試，改了沒辦法驗證沒壞
- 沒有分支權限，所有改動直接上 master
- 沒有 staging 環境，bug 直接面對使用者
- 改一個壞一個，後果無法承擔

### 我選擇的策略：先嵌入，再抽離

```
Phase 1 (Sep 11)  把 Guard 邏輯直接寫進 database.php
                  → 82 個 endpoint 零修改，自動獲得防護
                  → 可以即時用 attack_harness 測試
                  
Phase 2 (Sep 15)  把 PdoGuardProxy 從 database.php 抽出到獨立檔案
                  → database.php 加一行 require_once 就完成
                  → 82 個 endpoint 仍然零修改
                  
Phase 3 (Sep 17)  把 DatabaseOperations 抽出到 Trait
                  → database.php 加一行 use DatabaseOperations
                  → 82 個 endpoint 仍然零修改
                  
Phase 4 (Sep 19)  工具補強 + PHP <8.2 向下相容
                  → 確保 production 環境（PHP 8.1）能跑

整個抽離過程，endpoint 修改數 = 0
```

**這就是 Strangler Fig Pattern：** 不殺掉舊系統，而是在它內部慢慢長出新系統，等新系統穩定了，舊系統自然被取代。

**面試講法：**
> 「我接手過一個沒有 SQL injection 防護的舊系統，82 個 endpoint 直接拼接 SQL。我用 Strangler Fig 策略，9 天內建立完整防護：先把新邏輯嵌入到所有人都 include 的核心檔案，82 個 endpoint 自動獲得防護而不需修改任何一個檔案；確認穩定後再分階段抽離成獨立的 Proxy 和 Trait。整個過程零 endpoint 修改、零生產事故。」

---

## 四層防禦架構

### Layer 1: PdoGuardProxy — PDO 代理層

**檔案：** [`inc/PdoGuardProxy.php`](https://github.com/kevino430/inc/PdoGuardProxy.php) — 159 行 / 100% 原創  
**Commit：** `9dd16edbb` (2025-09-15 20:50)

**核心職責：**
- 封鎖 `query()` 和 `exec()` — 強迫使用 prepared statement
- `prepare()` 時做 SQL lint 檢查（無 placeholder + 危險字元 → 擋）
- 封鎖 `setAttribute()` — 防止外部程式改壞 PDO 安全屬性
- 提供 `onBlock` callback — 被擋時記錄到 log，不直接 exit

**關鍵設計：**

```php
class PdoGuardProxy
{
    public function query($sql)  { return $this->deny('query', $sql); }
    public function exec($sql)   { return $this->deny('exec', $sql); }
    
    public function prepare(string $sql, array $options = []): PDOStatement
    {
        $this->lintSqlOnPrepare($sql);  // 防呆檢查
        return $this->pdo->prepare($sql, $options);
    }
    
    private function lintSqlOnPrepare(string $sql): void
    {
        $hasNamed = preg_match('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql);
        $hasPos   = strpos($sql, '?') !== false;
        $hasPH    = $hasNamed || $hasPos;
        
        // 無 placeholder 且含危險字元 → 擋
        if (!$hasPH && preg_match("/['\"]|--|\/\*/", $sql)) {
            $this->deny('prepare', $sql);
        }
    }
}
```

**為什麼這是 Senior 級設計：**

1. **Proxy Pattern 經典應用** — 包住 PDO 而不是繼承它，可以隨時換掉底層 driver
2. **Whitelist > Blacklist** — `query()`/`exec()` 一律封鎖，只開放 `prepare()`
3. **Defensive Lint** — 不只擋執行，連 prepare 階段都做靜態檢查
4. **Logging Hook** — onBlock callback 讓上層決定怎麼處理（記 log、發警報、回 error）
5. **不破壞現有介面** — 對外仍然是 PDO-like 介面，舊程式碼不需改

**面試講法：**
> 「我用 Proxy Pattern 包住 PDO，封鎖 raw query 強迫所有 SQL 必須走 prepared statement。設計上有一個關鍵決策：whitelist 不是 blacklist——我直接擋掉 query() 和 exec() 兩個 method，只開放 prepare()。這樣即使未來有人不小心寫了 raw SQL，根本不會 compile 過。連 prepare 階段都做 lint，無 placeholder 且含 SQL 注入記號的就直接擋下。」

---

### Layer 2: DatabaseOperations — Trait 安全層

**檔案：** [`inc/DatabaseOperations.php`](https://github.com/kevino430/inc/DatabaseOperations.php) — 868 行  
**我的原創部分：** 705 行（541 行 security + 164 行 error handling）  
**Commit：** `6ef2c0581` (2025-09-17 18:37)

**核心職責：**
- `fetch_arrr()` 安全包裝 — 強制只能 SELECT、強制 WHERE 必須參數化
- `PDO_Data()` 安全包裝 — 強制 INSERT/UPDATE/DELETE 走參數化、無 WHERE 禁止
- `_assert_select_only()` — 確保是 SELECT/WITH，不允許 DDL
- `_assert_where_and_in_safe()` — WHERE 必須有 placeholder、IN(...) 內容白名單
- `expandInParams()` — 把 IN(:ids) 安全展開成 IN(:ids0, :ids1, ...)
- `normalizeParams()` — 統一參數命名（加冒號）
- `validatePlaceholdersMatch()` — 檢查 placeholder 和參數對應

**關鍵設計：**

```php
trait DatabaseOperations
{
    function fetch_arrr($sql, $aValue = array())
    {
        try {
            // 1. 只准 SELECT / WITH
            $this->_assert_select_only($sql);
            
            // 2. WHERE 必須參數化、IN 必須白名單
            $this->_assert_where_and_in_safe($sql);
            
            // 3. IN(:ids) 安全展開
            list($sql, $aValue) = $this->expandInParams($sql, $aValue, 0);
            
            // 4. 標準化參數命名
            $aValue = $this->normalizeParams($aValue);
            
            // 5. 驗證 placeholder 對應
            $this->validatePlaceholdersMatch($sql, $aValue, 0);
            
            // 6. 才執行
            $stmt = $this->db->prepare($sql);
            $stmt->execute($aValue);
            // ...
        } catch (Throwable $e) {
            $this->handleException($e, '[DB][fetch_arrr]', json_encode($ary_value), false);
            return false;
        }
    }
    
    private function _assert_where_and_in_safe(string $sql): void
    {
        // === (1) WHERE 規則 ===
        if (preg_match('/\bWHERE\b/i', $s)) {
            $hasNamed = preg_match('/:(?:[A-Za-z_][A-Za-z0-9_]*)/', $s);
            $hasPos   = strpos($s, '?') !== false;
            if (!$hasNamed && !$hasPos) {
                throw new InvalidArgumentException('SELECT...WHERE 必須使用參數化');
            }
        }
        
        // === (2) IN(...) 白名單 ===
        if (preg_match_all('/\bIN\s*\((.*?)\)/is', $s, $mm)) {
            foreach ($mm[1] as $content) {
                $tmp = preg_replace('/:(?:[A-Za-z_][A-Za-z0-9_]*)|\?/', '0', $content);
                $left = preg_replace('/[0-9,\s\-]/', '', $tmp);
                
                if ($left !== '') {
                    throw new InvalidArgumentException('IN(...) 只允許陣列參數或整數常數清單');
                }
            }
        }
    }
    
    function PDO_Data($sTbName, $aCon, $type)
    {
        // ...
        if ($type === 'update') {
            if (empty($cons)) throw new InvalidArgumentException('UPDATE without WHERE is blocked');
            // ...
        }
        if ($type === 'delete') {
            if (empty($cons)) throw new InvalidArgumentException('DELETE without WHERE is blocked');
            // ...
        }
    }
}
```

**為什麼這是 Senior 級設計：**

1. **多層斷言（Defense in Depth）** — 一道沒擋住，下一道擋；下一道沒擋住，再下一道
2. **無 WHERE 的 UPDATE/DELETE 直接 throw** — 工程師最容易犯的災難性錯誤，從根部杜絕
3. **IN(...) 白名單** — 只允許陣列參數或整數常數清單，杜絕 string literal 注入
4. **IN(:ids) 自動展開** — 開發者寫 `IN(:ids)`，框架自動展開成 `IN(:ids0, :ids1, ...)`，又安全又好用
5. **錯誤統一處理** — 走 `handleException` 統一記 log、生 error code、回前端

**面試講法：**
> 「我設計了一個多層斷言的安全層，包在 PDO 上面。任何 SELECT 都必須先過 `_assert_select_only` 確認不是 DDL，再過 `_assert_where_and_in_safe` 確認 WHERE 有 placeholder、IN 內容是白名單。UPDATE 和 DELETE 沒有 WHERE 直接 throw 例外——這是工程師最容易犯的災難性錯誤，與其防爆炸後清理，不如從根部杜絕。整套框架對開發者透明——他們只要寫 `fetch_arrr($sql, $params)` 就好，背後的所有檢查自動跑。」

---

### Layer 3: attack_harness — 自動化安全測試

**檔案：** [`jsonapi/config/attack_harness.php`](https://github.com/kevino430/jsonapi/config/attack_harness.php) — 167 行 / 100% 原創  
**Commit：** `c7d0df06f` (2025-09-11 04:59) — 凌晨 4:59 開工的證據

**核心職責：**
- 自動化驗證所有安全防護是否真的有效
- 包含 10 項攻擊測試 + 壓力測試
- 可重複執行，每次部署後跑一遍

**測試項目：**

```php
// 測試 1：封鎖 raw query()
$blocked = expectThrow(function() use ($pdo){
    $pdo->query("SELECT 1");
}, 'BLOCKED');

// 測試 2：prepare 無 placeholder + 危險字元 被擋
$blocked = expectThrow(function() use ($pdo, $table){
    $pdo->prepare("SELECT * FROM `{$table}` WHERE name = 'Amy'");
}, 'BLOCKED');

// 測試 3：fetch_arrr 正常參數化能用
$rows = $db->fetch_arrr("SELECT * FROM `{$table}` WHERE name = :n", [':n'=>'Amy']);

// 測試 4：嘗試在參數中注入 → 應無效
$rows = $db->fetch_arrr("SELECT * FROM `{$table}` WHERE name = :n", 
                        [':n'=>"Amy' OR 1=1 --"]);
// 應該回傳 0 筆（因為真的去找叫 "Amy' OR 1=1 --" 的人）

// 測試 5：堆疊語句 (; DROP TABLE) 被擋
$blocked = expectThrow(function() use ($db, $table){
    $db->fetch_arrr("SELECT * FROM `{$table}`; DROP TABLE `{$table}`", []);
}, 'BLOCKED');

// 測試 7：UPDATE 無 WHERE 被擋
$ok = $db->PDO_Data($table, ['value'=>['status'=>0]], 'update');  // 無 con
// 應該回 false

// 測試 8：DELETE 無 WHERE 被擋
$ok = $db->PDO_Data($table, [], 'delete');  // 無 con
// 應該回 false

// 測試 10：IN (:ids) 陣列展開
$rows = $db->fetch_arrr("SELECT * FROM `{$table}` WHERE id IN (:ids)", 
                        ['ids'=>[1,3,9999]]);
```

**還有壓力測試：**

```php
// 簡易壓測：fetch_arrr SELECT by :name 跑 2000 次
bench(function() use ($db, $table){
    $db->fetch_arrr("SELECT id FROM `{$table}` WHERE name=:n", [':n'=>'Amy']);
}, 2000);
```

**為什麼這是 Senior 級設計：**

1. **不只是「防護」，還有「驗證防護」** — 大部分安全方案沒有自動測試
2. **Smoke test 思維** — 部署後跑一遍，所有核心防護都驗證一次
3. **Benchmark 一起做** — 確保安全層沒有顯著拖慢效能
4. **每個測試都對應一種攻擊向量** — 不是隨便寫測試湊數

**面試講法：**
> 「我相信『沒驗證的防護等於沒防護』。所以建了一個 attack_harness 測試套件，包含 10 項真實攻擊向量的自動化驗證——raw SQL injection、堆疊語句、無 WHERE 操作、IN 注入、prepare 階段繞過。每次部署後跑一遍，確保防護沒有 regression。同時還做了 benchmark，確認安全層的 overhead 在可接受範圍。」

---

### Layer 4: database.php Constructor — 入口統一控管

**檔案：** [`jsonapi/config/database.php`](https://github.com/kevino430/jsonapi/config/database.php)  
**我的修改：** constructor 重寫 + 安全常數定義  
**Commit：** `9dd16edbb` (2025-09-15)

**關鍵設計：**

```php
class Database {
    use DatabaseOperations;   // ← Trait 注入
    
    public $db = null;  // ← PdoGuardProxy 包裝後的 PDO
    
    private const BLOCK_RAW_SQL          = true;  // 擋 query()/exec()
    private const STRICT_PREPARE_CHECK   = true;  // prepare 無 placeholder + 危險字元 → 擋
    private const ALLOW_SET_ATTR         = false; // 禁止外部更改 PDO 安全屬性
    private const FETCH_ALLOW_FOR_UPDATE = false;
    
    public function __construct($DIR_PATH, $order = '')
    {
        // ... 讀設定檔 ...
        
        $options = [
            PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE     => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES       => false,  // ← 真正的 prepared statement
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,  // ← 禁多語句
        ];
        $pdo = new PDO($dsn, $dbuser, $dbpasswd, $options);
        
        // SQL mode 收緊
        $sqlMode = "NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";
        $stmt = $pdo->prepare("SET SESSION sql_mode = :sql_mode");
        $stmt->execute(['sql_mode' => $sqlMode]);
        
        // 用 PdoGuardProxy 包住
        $this->db = new PdoGuardProxy(
            $pdo,
            self::BLOCK_RAW_SQL,
            self::STRICT_PREPARE_CHECK,
            self::ALLOW_SET_ATTR,
            function (Throwable $e, string $method, string $sqlPreview) {
                $this->handleException($e, "PDO.$method", 'auto deny', false);
            }
        );
    }
}
```

**為什麼這是關鍵：**

- 82 個 endpoint 都 `new Database()`，所以這個 constructor 是**整個安全體系的單一入口**
- `EMULATE_PREPARES => false` 確保是 server-side prepared statement，不是 client-side 字串拼接
- `MULTI_STATEMENTS => false` 從 MySQL 連線層杜絕堆疊注入
- SQL mode 收緊，讓資料層也參與防禦

---

## 9 天衝刺時間軸

```
Sep 03 (Wed)  upload_photo 安全強化 v1→v2→v3
              （前置探索，發現問題嚴重性）

Sep 04 (Thu)  emit429AndLog 預備功能（後來移除）

Sep 11 (Thu)  ┌─ Phase 1 啟動
   04:59      │  c7d0df06f — 第一筆安全 commit
              │  attack_harness 167 行 + Guard 嵌入 database.php +482 行
   12:12      │  
   23:52      │  Regex 微調 (放行 360_di_setting)
              │
Sep 12 (Fri)  │  c53ad018d — 三套測試套件
   12:54      │  smoke test + bench test + attack test (+422/-251)
   14:11      └─ Phase 1 結束 (33 hrs)
              
Sep 15 (Mon)  ┌─ Phase 2 啟動
   01:11      │  凌晨測試
   01:23      │
   20:50      │  9dd16edbb — PdoGuardProxy.php 抽出
              │  153 行獨立檔案 + handleException 體系
              │  
Sep 16 (Tue)  │  47d9ec0d6 — Lint 微調
   12:45      │  68c6edf37 — show_error_stack 自由度
   16:20      └─ Phase 2 結束 (39 hrs)
              
Sep 17 (Wed)  ┌─ Phase 3：架構抽離（高潮）
   18:37      │  6ef2c0581 — DatabaseOperations.php 建立
              │  867 行 Trait 從 database.php 拆出
   19:03      │  變數位置調整
   19:41      │  移除廢棄註解
   20:41      └─ Phase 3 結束 (2 hrs)
              
Sep 19 (Fri)  ┌─ Phase 4：相容性
   14:50      │  PHP <8.2 向下相容（const → static array）
   16:25      │  98a368fa5 — buildLimit/appendLimit 工具
   16:37      └─ Phase 4 結束 (2 hrs)
              
Sep 22 (Mon)  終於拿到 dev_sql_injection 分支
              開始在分支上做 fetch_arrr 遷移
              
Sep 23~30     objects/ 17 個業務檔案 fetch_arrr 遷移
              14 筆 commit
              
2026-01-23    dev_sql_injection 終於 merge 回 master
              （從開分支到 merge 等了 4 個月）
```

**統計：**
- 日曆天：9 天（Sep 11 → 19）
- 活躍天：7 天
- 工時：~22 小時
- 原創安全程式碼：~1,005 行
- 速度：~46 行/工時，~144 行/活躍天

---

## 為什麼 9 天能完成？Strangler Fig 是關鍵

如果不用 Strangler Fig，傳統做法的時程估算：

| 階段 | 傳統做法 | Strangler Fig（我的做法）|
|------|---------|---------------------|
| 設計階段 | 1-2 週 | 同上 |
| 建立新框架（獨立檔案）| 1 週 | 同上 |
| 改 82 個 endpoint 的 include | 2-3 週 | **0 天** ← 關鍵節省 |
| 改 82 個 endpoint 的 SQL 呼叫 | 4-6 週 | 可在新框架穩定後分批做 |
| 測試 + 修 bug | 2-4 週 | 同上 |
| **合計** | **10-15 週** | **9 天 + 後續分批遷移** |

**為什麼能省這麼多？** 因為傳統做法的「改 82 個 endpoint」需要一次完成才能上線，是 big-bang 風險。Strangler Fig 讓「框架就緒」和「endpoint 遷移」解耦——框架先就緒並上線，endpoint 可以慢慢遷移，每遷一個就獲得多一份安全。

**面試講法：**
> 「我估計這個改造如果用傳統 big-bang 做法要 10-15 週，但我用 Strangler Fig 把『框架建立』和『endpoint 遷移』解耦，9 天就讓框架上線運作。endpoint 的遷移後續可以分批做，每遷一個就獲得多一份安全。最關鍵的是：在改造期間沒有任何 endpoint 需要修改，意味著零生產風險。」

---

## 組織阻力（Senior 必備的軟實力）

這個改造不是在理想環境下做的。實際情況：

| 障礙 | 影響 |
|------|------|
| **沒有分支權限** | 9 天的核心架構工作全部在 master 上直接做 |
| **沒有 code review 制度** | 沒人能 review 我的程式碼，但有人會造成 regression |
| **沒有 staging 環境** | 改了直接面對 production |
| **有人直接 push 到 master 造成事故** | 後來甚至有 bot 誤刪 2,025 行，我修過的東西被搞壞 |
| **dev_sql_injection 等了 4 個月才被允許 merge** | 從 2025-09-22 開分支到 2026-01-23 merge |

**這些阻力是 Strangler Fig 策略的最大原因。** 如果有正常的分支 + review + staging，我可以做 big-bang 重構；正因為沒有，所以必須選最低風險的路徑。

**面試講法（這段要練）：**
> 「我做這個改造的時候，公司沒有分支權限給我，所以核心架構全部在 master 上直接寫。沒有 code review，沒有 staging 環境，改了就直接上 production。這些限制塑造了我的技術選擇——Strangler Fig 不是我的偏好，是我在那個環境下的唯一安全做法。經歷過這個專案讓我深刻理解：**好的工程實踐不是奢侈品，是必需品**。我希望加入有 code review、有 CI/CD、有 staging 環境的團隊。」

> ⚠️ **講這段時的注意事項：** 不要批評前公司或前同事，把它當成「我學到了什麼」而不是「他們做錯了什麼」。

---

## 至今的成績單

| 指標 | 數字 |
|------|------|
| 原創安全程式碼 | ~1,005 行 |
| 涵蓋 endpoint | 82 個 |
| 改造期間的 endpoint 修改 | **0 個** |
| 改造期間的生產事故 | **0 次** |
| 改造後至今的 SQL injection 漏洞回報 | **0 次**（持續 6 個月以上）|
| attack_harness 測試項目 | 10 項 + 1 壓測 |
| 測試通過率 | **100%** |

---

## 可借鏡到 ez_crm 的部分

### 直接複用：Defense in Depth 思維

ez_crm 用 Laravel + Eloquent，本身就有 ORM 防護，但 Defense in Depth 的思維仍然適用：

```php
// app/Services/Database/SafeQueryService.php

class SafeQueryService
{
    // Layer 1: 強制 type-safe 介面
    public function findMember(int $id): ?Member
    {
        return Member::find($id);
    }
    
    // Layer 2: 業務層的權限檢查
    public function findMemberForUser(int $id, User $user): ?Member
    {
        $member = Member::find($id);
        
        if ($member && !$user->can('view', $member)) {
            return null;  // 不是 throw，安靜地不給看
        }
        
        return $member;
    }
    
    // Layer 3: 任何 raw query 必須走 reviewable method
    public function rawSelect(string $sql, array $bindings): array
    {
        if (!$this->isReadOnly($sql)) {
            throw new \LogicException('rawSelect only allows SELECT statements');
        }
        
        return DB::select($sql, $bindings);
    }
}
```

### 學到的教訓：自動化測試是 Mandatory

linky_360 的 attack_harness 是事後補的。ez_crm 從 day 1 就要有：

- PHPUnit Feature test 覆蓋每個 endpoint
- Unit test 覆蓋每個 Service method
- 安全相關的 edge case 都要有對應的 test case

我已經在 ez_crm 的 `tests/Feature/Api/V1/MemberSearchTest.php` 開始這樣做了。

### 學到的教訓：Idempotent CI 很重要

linky_360 沒有 CI，所以 attack_harness 是手動跑。ez_crm 有 GitHub Actions，要把所有測試自動跑起來：

```yaml
# .github/workflows/test.yml
- name: Run PHPUnit
  run: vendor/bin/phpunit
- name: Run security tests
  run: vendor/bin/phpunit --testsuite Security
```

---

## 完整面試彈藥（這份文件最重要的部分）

### 故事 1：四層防禦架構（最完整的故事）

**觸發問題：** 「介紹一下你做過最複雜的系統？」

```
我接手過一個 6 年歷史的 PHP 系統，82 個 endpoint 直接拼接 SQL，
完全沒有 SQL injection 防護。

我設計了一個四層防禦架構：
- Layer 1: PdoGuardProxy 用 Proxy Pattern 包住 PDO，封鎖 raw query
- Layer 2: DatabaseOperations Trait 做多層斷言，WHERE 必須參數化、
           IN 內容白名單、無 WHERE 的 UPDATE/DELETE 直接 throw
- Layer 3: attack_harness 自動化測試套件驗證 10 種攻擊向量
- Layer 4: database.php constructor 在連線層收緊 SQL mode 和 multi-statement

整個架構 9 天交付，~1,005 行原創程式碼，至今 6 個月零漏洞回報。
```

### 故事 2：Strangler Fig 策略

**觸發問題：** 「你怎麼處理 legacy 系統？」

```
我有一個經典案例：要在一個沒有測試、沒有 staging、沒有分支權限的環境
裡，幫 82 個 endpoint 加上 SQL injection 防護。

傳統做法是建獨立的安全層，讓所有 endpoint 改 include。但這在沒有測試
的環境下是 big-bang 風險——改一個壞一個。

我選擇 Strangler Fig：先把新邏輯嵌入到所有人都會 include 的核心檔案，
82 個 endpoint 自動獲得防護而不需要修改。確認穩定後再把新邏輯抽離成
獨立的 Proxy 和 Trait——抽離過程中 endpoint 還是不需要改任何一行。

整個過程 9 天交付，零生產事故。傳統做法估計要 10-15 週。
```

### 故事 3：在組織限制下做工程決策

**觸發問題：** 「跟我說一個你在限制下做技術決策的例子」

```
我做安全改造的時候沒有分支權限——這意味著我必須在 master 上直接改架
構。這個限制完全塑造了我的技術選擇。

如果我有分支，我可以做 big-bang 重構，建立全新的 ORM 層，然後一次切
換。但在 master 上直接改，big-bang 等於賭命。

所以我選了最保守的路徑：Strangler Fig + 嵌入式起步 + 漸進式抽離。每
一步都要確保「即使這一步失敗，也不會比現狀更糟」。

這個經驗讓我學到：**技術決策不是純技術的，是組織環境的函數**。同樣的
問題在不同環境下有不同的最優解。
```

### 故事 4：自動化測試 = Defense Validation

**觸發問題：** 「你怎麼確認你的安全防護有效？」

```
我建了一個 attack_harness 測試套件，包含 10 項真實攻擊向量：
- raw query 封鎖測試
- 注入字串測試
- 堆疊語句測試
- 無 WHERE UPDATE/DELETE 測試
- IN 注入測試
- 等等

每次部署後跑一遍。如果有 regression，立刻知道。

我的信念是：**沒驗證的防護等於沒防護**。光寫了防護邏輯不夠，要有東西
持續證明它有效。
```

### 故事 5：凌晨 04:59 的承諾

**觸發問題：** 「跟我說一個你對工作有承諾的例子」

```
git log 上有一個 commit 是 2025-09-11 04:59 的，那是我整個安全改造
的第一筆 commit。

那不是加班，是我自願在凌晨開工——因為意識到問題嚴重性後，我想盡快有
東西可以擋住攻擊。9 天的衝刺裡有兩個凌晨 commit（Sep 11 04:59 和
Sep 15 01:11）。

我不是建議大家熬夜，但這證明了我對重要事情的投入度。現在我選擇加入
有 work-life balance 的團隊，但需要的時候我有能力 push 自己。
```

---

## 證據索引

| 工件 | Commit | 日期 |
|------|--------|------|
| Phase 1 啟動：attack_harness + Guard 嵌入 | `c7d0df06f` | 2025-09-11 04:59 |
| 三套測試套件建立 | `c53ad018d` | 2025-09-12 12:54 |
| Phase 2：PdoGuardProxy 抽出 | `9dd16edbb` | 2025-09-15 20:50 |
| PdoGuardProxy 微調 | `47d9ec0d6` | 2025-09-16 12:45 |
| Phase 3：DatabaseOperations Trait 建立 | `6ef2c0581` | 2025-09-17 18:37 |
| Phase 4：buildLimit 工具 + PHP 相容 | `98a368fa5` | 2025-09-19 16:25 |
| dev_sql_injection 分支終於 merge | `10937b400` | 2026-01-23 17:21 |
| objects/ 層 fetch_arrr 遷移（14 筆連續 commit）| `b71f803d7` ~ `55fdea0bd` | 2025-09-23 ~ 10-02 |

---

## 一句話總結

**linky_360 的 SQL injection 防護架構是我職涯目前最完整的個人作品：架構設計、實作交付、測試驗證、上線維護全部一手包辦，9 天從零到一，至今零漏洞，影響 82 個 endpoint。這份文件就是要讓未來的我（和未來的面試官）記得這件事的全貌。**
