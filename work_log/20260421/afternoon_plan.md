# 下午工作計畫 — 2026-04-21 (Tue) PM

> 建立時間：12:45（午休前）
> 工作時段：13:30 - 18:00（扣除午休 = 4.5 小時）
> 策略：Y — 前端 `ez_crm_client` 初始化 + 完成 Auth 基本頁面

---

## 🌅 上午戰況回顧

```
bcf1adb  feat(auth): Phase 4.1 — GitHub OAuth login
59122ad  feat(auth): Phase 4.0 — Google OAuth login
```

- 前台 Auth API **15/24 完成(63%)**
- Tests **135 passed / 440 assertions**
- 現在分支:`feature/member-oauth`(還沒 merge,因為 LINE + Discord 還要做)

---

## 🎯 下午目標

**主戰場是前端**(後端 Phase 4 明天再補 LINE + Discord)。

核心成果物:
1. 新 repo `ez_crm_client` 上 GitHub
2. Vite + Vue 3 + TS + Tailwind + Pinia + Router 骨架跑起來
3. `api/client.ts`(axios interceptor)+ auth store
4. **Login + Register 兩頁可以打 ez_crm 後端 API 並成功**

明天再接:Email Verify、Forgot/Reset、OAuth popup、/me 頁面。

---

## ⏱️ 時間分配(含 15% 緩衝)

| 時段 | 任務 | 估時 |
|---|---|---|
| 13:30-13:40 | (小)更新 `.env.example` 補 GOOGLE_* / GITHUB_* / MAIL_* | 10 分 |
| 13:40-14:00 | GitHub 建 repo `TuanTsungShiang/ez_crm_client` + 建 `c:\code\` | 20 分 |
| 14:00-14:30 | `npm create vite@latest ez_crm_client -- --template vue-ts` + 驗 dev server | 30 分 |
| 14:30-15:15 | Tailwind + Pinia + Vue Router 裝配 + 最小路由 | 45 分 |
| 15:15-16:15 | `api/client.ts` + auth store + router guards | 60 分 |
| 16:15-17:15 | Register 頁(用後端 schema 動態 render)+ 送表單 | 60 分 |
| 17:15-17:45 | Login 頁 + token 儲存 + 錯誤提示 | 30 分 |
| 17:45-18:00 | Commit + push(ez_crm_client 首發)+ 更新 work_log | 15 分 |

---

## ✅ 任務清單(下午照著打勾)

### 階段 0 — 熱身 + 清理(10 分)

- [ ] 更新 `c:\xampp\htdocs\ez_crm\.env.example`:
  - 加 `GOOGLE_CLIENT_ID=` / `GOOGLE_CLIENT_SECRET=` / `GOOGLE_REDIRECT_URI=`
  - 加 `GITHUB_CLIENT_ID=` / `GITHUB_CLIENT_SECRET=` / `GITHUB_REDIRECT_URI=`
  - 檢查 `MAIL_MAILER` / `MAIL_HOST` 等是否齊全(供新 dev 參考)
- [ ] 在 `feature/member-oauth` 分支 commit 這個 `.env.example` 變動

### 階段 1 — Repo 建立(20 分)

- [ ] 確認 GitHub 帳號登入:`TuanTsungShiang`
- [ ] 在 GitHub 網頁新建 repo:`ez_crm_client`
  - Public or Private 自己決定(建議先 Public 給未來面試用)
  - **不要**勾 Initialize with README / .gitignore(讓 Vite 幫我們產)
  - Description:`Frontend SPA for ez_crm — Vue 3 + TS + Vite + Pinia`
- [ ] 開 cmd / PowerShell(不是 bash,避免路徑雷):
  ```
  mkdir c:\code
  cd c:\code
  ```
- [ ] 先不要 clone,下一步 Vite 會直接產資料夾

### 階段 2 — Vite + Vue3 + TS(30 分)

- [ ] 在 `c:\code\` 跑:
  ```
  npm create vite@latest ez_crm_client -- --template vue-ts
  cd ez_crm_client
  npm install
  npm run dev
  ```
- [ ] 瀏覽器打 `http://localhost:5173` 看到 Vue 預設畫面 → ✅
- [ ] 關 dev server(Ctrl+C),繼續裝套件

### 階段 3 — 周邊套件(45 分)

- [ ] `npm install axios pinia vue-router@4 @vueuse/core`
- [ ] `npm install -D tailwindcss postcss autoprefixer @types/node`
- [ ] `npx tailwindcss init -p`
- [ ] 編 `tailwind.config.js` 加 content paths
- [ ] 編 `src/style.css`(或新 `src/tailwind.css`)加 Tailwind directives
- [ ] 編 `src/main.ts` 串 Pinia + Router
- [ ] 建最小路由架構:`src/router/index.ts` 含 `/`、`/login`、`/register` 三條路由
- [ ] 建最小 view:`HomeView.vue` / `LoginView.vue` / `RegisterView.vue`(各放 "TODO" 就好)
- [ ] `npm run dev` → 切換三條路由都能顯示 → ✅

### 階段 4 — API Client + Auth Store(60 分)

- [ ] 建 `.env.development`:
  ```
  VITE_API_BASE_URL=http://ez-crm.local/api/v1
  ```
- [ ] 建 `src/api/types.ts`:定義 `ApiResponse<T>`、`Member`、`ApiCode`
- [ ] 建 `src/api/client.ts`:axios instance + request interceptor(塞 Bearer token)+ response interceptor(401 導 login)
- [ ] 建 `src/api/auth.ts`:`register()` / `login()` / `verifyEmail()` 三個 function 先空殼
- [ ] 建 `src/stores/auth.ts`:Pinia store,含 `token` / `member` / `isAuthenticated` / `setToken()` / `clearToken()`,用 `useLocalStorage` 持久化
- [ ] 建 `src/router/guards.ts`:`requireAuth` / `redirectIfAuth` 兩個 guard
- [ ] Home 頁顯示「已登入」或「未登入」(簡單 debug 看 store 運作)

### 階段 5 — Register 頁(60 分)

- [ ] 在 `src/api/auth.ts` 真的實作:
  - `getRegisterSchema()` → GET `/auth/register/schema`
  - `register()` → POST `/auth/register`
- [ ] 建 `src/components/DynamicForm.vue`:接 `fields` prop(schema)render input + 收集 v-model
- [ ] `RegisterView.vue`:
  - `onMounted` 打 schema → 交給 DynamicForm
  - submit 時呼叫 `register()` → 成功後導到 `/verify?email=xxx`(頁面等明天做)
  - 顯示 422 validation errors

### 階段 6 — Login 頁(30 分)

- [ ] 實作 `login()` function
- [ ] `LoginView.vue`:
  - Email + Password 欄位(先不要 dynamic form,寫死)
  - submit → 呼叫 `login()` → 把 token 存 authStore → 導 `/`
  - 顯示 A009 錯誤(帳號或密碼錯誤)

### 階段 7 — 首發 Commit + Push(15 分)

- [ ] `git init` + `git remote add origin https://github.com/TuanTsungShiang/ez_crm_client.git`
- [ ] `git add . && git commit -m "feat: initial Vue 3 + Vite + TS scaffolding with auth skeleton"`
- [ ] `git branch -M main && git push -u origin main`
- [ ] 更新本 `work_log/20260421/afternoon_plan.md` 勾選完成項,或另寫一份 `end_of_day.md`

---

## 🔥 今天可能遇到的雷(先打預防針)

| 雷 | 對策 |
|---|---|
| **CORS 問題**:ez_crm_client 在 `:5173` 打 ez-crm.local 的 API 被擋 | 後端 `config/cors.php` 要加 `'allowed_origins' => ['http://localhost:5173']`,之後 commit 回 ez_crm repo |
| **Vite env vars 必須 `VITE_` 開頭** | `.env.development` 裡 `VITE_API_BASE_URL` 不是 `API_BASE_URL` |
| **Tailwind v4 beta vs v3 穩定版** | 建議鎖 v3.x:`npm install -D tailwindcss@^3` |
| **TypeScript 嚴格模式抱怨 vue-router 型別** | `tsconfig.json` 打開 `"moduleResolution": "bundler"` 或 `"bundler"` 別用 `"node"` |
| **Pinia 在 router guard 無法使用**(外於 component scope) | 先 `setActivePinia(createPinia())` 或在 guard 內 lazy import |

---

## 📌 不在今天範圍(留給明天)

- Phase 4.2 LINE OAuth 後端
- Phase 4.3 Discord OAuth 後端
- 前端:Email Verify / Forgot / Reset / OAuth popup / /me 頁面
- 後端 OAuth callback 改 postMessage HTML(等前端真要接 popup 時再改)

---

## 🌇 收工檢核(18:00 前做)

- [ ] `ez_crm_client` 已在 GitHub
- [ ] Register + Login 頁實際能打 API 並拿到 token(或看到正確錯誤)
- [ ] Commit 上 `main`,message 乾淨
- [ ] 寫 `work_log/20260421/evening_summary.md` 記錄實際進度 vs 計畫落差、明天起點

---

**午休後 13:30 回來,從階段 0 直接開動。12 小時後的你會感謝現在花 15 分鐘寫清楚的自己。** 🍱→💻
