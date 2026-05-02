# ARCHITECTURE.md — ez_crm 架構防呆

## 為什麼有這份文件

這是個人專案，理論上跟其他 codebase 完全獨立。但 anti-pattern 不從 codebase 傳染，從**開發者的手感**傳染。

Linky360 踩過的所有坑，這個 repo 在零行業務程式碼之前就具備全部「遺傳基因」 — 因為作者同一個。greenfield 的代價是必須先訂規矩，因為還沒痛過、沒有記憶觸發點。

這份文件把 Linky360 學到的決策**提前釘在 ez_crm**，把過去的痛點轉成顯式規則，避免肌肉記憶把同樣的東西長出來。

---

## 五條硬規矩（禁令）

違反了不是「補規矩」，是**回頭刪除違規程式碼**。每多一個例外，下一個例外的門檻就降低。Linky360 怎麼長出 71 個 page CSS 的？因為第 1 個看起來「只是這次特例」。

### 1. 禁止 `resources/css/pages/`

- `app.css` 唯一允許的內容：`@import` Tailwind / Filament theme override、`@layer components` 寫 `@apply` 共用 class
- 「這頁需要一點微調」的衝動 → utility class 寫在 Blade 上，或抽成 component class
- 連 `pages/` 這個資料夾名字都不准出現

> 對應 Linky360 痛點：`css2/pages/` 71 個檔案、跨頁借檔（authority_* 借用 account.css）、SPEC 規定與實務矛盾

### 2. 禁止 raw Blade 偷渡業務頁面

- 95%+ 業務頁面走 Filament Resource / Cluster / Custom Page
- 例外（landing、login 客製、健康檢查）才允許 raw Blade，且必須列入 `docs/blade-exceptions.md`
- 一旦開放「特殊頁直接寫 Blade」的口子，Filament 的紀律失效，回到 Linky360 模式

### 3. 禁止 jQuery

- 互動層只允許 Livewire + Alpine
- 涵蓋：`npm install jquery`、CDN `<script src=...jquery...>`、Blade 內 `$(...)`、任何 jQuery plugin
- Linky360 上萬行 jQuery 的命運不要重演

### 4. 禁止單頁 `<link>` / `<script>`

- 所有 CSS / JS 走 Vite entry（`app.css` / `app.js`）
- 不可在 Blade 內插 `<link rel="stylesheet">` 或 `<script src="">`
- Filament 的資產管線自動處理 component-level 資源，不要繞過

### 5. 禁止手寫權限判斷散落

- 任何「這個 user 能不能看 / 改 / 刪」的判斷必須走 Spatie Policy + Filament 的 `canViewAny()` / `canUpdate()` / `canDelete()` API
- 不准在 Controller、Blade、Service、Job 內寫 `if ($user->hasRole(...))` 之類散落判斷
- 新增 model 必須同時補對應 Policy，沒寫 Policy 的 model 在 Filament 預設 deny
- API 端點權限走 middleware + Policy，不寫 inline 判斷

> 對應 Linky360 痛點：250 個 PHP 散落 `if ($_SESSION['role']===...)` 檢查，漏一個 = 越權
> ez_crm 已做：commit `1e0fa69` 引入 RBAC、`a3095bc` gate WebhookHealthWidget — 把這個習慣維持下去

---

## 專案定位（避免規矩錯置）

ez_crm 是 **single-tenant 個人專案**，採 RBAC 控制權限，**不做 multi-tenancy**。

這個定位影響哪些規矩**不適用**：
- 不需要 `BelongsToTenant` trait 或 tenant scope
- 不需要 per-tenant theming contract（要改自己的 UI 就改，沒有對外契約）
- 不需要 Filament `->tenant()` 設定

如果未來定位改變（例如想開放給第二個使用者群體 = 變 multi-tenant），這份文件必須先改，再寫程式。架構決策不該被功能 PR 順便改掉。

---

## 架構決策

### Service layer
- 業務邏輯放 `app/Services/` 或 `app/Actions/`
- Filament Resource、API Controller、Artisan command 全部呼叫同一個 Service
- Validation 走 Form Request，跨入口共用
- 目的：避免 Linky360 「UI 一套 query、API 又一套」的雙軌離散

### API 文件
- Swagger 透過 L5-Swagger annotation 生成
- API endpoint 變更必須同步更新 annotation（PR check 把關）
- 三個月不維護就會跟現實脫鉤，跟 Linky360 的 SPEC 對不上實作一樣的下場

---

## 自動化擋坑（CI / pre-commit）

- **stylelint**：禁用 `@layer pages`、禁 page-specific class 命名前綴
- **phpstan**：自訂規則檢查未掛 tenant scope 的 model
- **eslint**：禁 `import.*jquery`、禁 `$(...)` syntax
- **grep check**（pre-commit）：Blade 內 `<link rel="stylesheet"` / `<script src=` 直接 block

---

## 違規處理原則

不接受「先做完再回來補」。違反任何一條 = block PR、回頭重寫。

理由：Linky360 的 71 個 page CSS、上萬行 jQuery、250 個散落的 `WHERE account_id`，**沒有一個是有人故意違反規則造成的**，全部都是「這次例外、下次補回來」累積的結果。下次永遠不會來。

---

## 文件維護

- 新踩到的坑 → 補進這份文件，**不**寫進 commit message 就忘掉
- 規矩鬆綁 → 必須改這份文件 + 在 CHANGELOG 留紀錄，不可口頭協議
- 這份文件本身是 architecture 的一部分，跟 schema 一樣慎重
