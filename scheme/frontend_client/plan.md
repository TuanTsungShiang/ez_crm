# ez_crm_client — 前端 SPA 規格草案

> 版本：v0.1 (草案)
> 建立日期：2026-04-20
> 定位：ez_crm 後端 API 的前台會員介面，獨立 repo `ez_crm_client`
> 主要用途：驗證 OAuth + 會員自助功能完整流程（註冊 → OTP → 登入 → OAuth → /me 管理）

---

## 📐 核心決策

| 決策項 | 選擇 | 理由 |
|---|---|---|
| 框架 | **Vue 3（Composition API + `<script setup>`）** | 學習曲線平緩、生態穩定、與 Laravel 社群搭配常見 |
| 語言 | **TypeScript** | 後端 ApiCode / response 結構清楚，型別對應能擋很多錯 |
| 建置工具 | **Vite** | 啟動快、HMR 好、Vue 官方推薦 |
| 狀態管理 | **Pinia** | Vue 3 官方推薦，比 Vuex 簡潔 |
| 路由 | **Vue Router 4** | 標配 |
| UI / 樣式 | **Tailwind CSS + HeadlessUI** | Utility-first、客製彈性高、省時 |
| HTTP | **axios** | Interceptor 成熟、錯誤處理清晰 |
| 工具函式 | **VueUse** | 常用 composables（useLocalStorage / useFetch 等）|
| 測試 | **Vitest + Vue Test Utils** | Vite 原生整合 |
| 部署 | dev: Vite 本機 → prod: static build 丟 Netlify / Vercel / S3 | 與 Laravel API 解耦，獨立 CI/CD |

**選 Vue 而非 React 的理由**：
- 後端是 Laravel，Laravel 社群（Filament / Livewire / Inertia）都是 Vue-friendly
- Composition API + `<script setup>` 程式碼簡潔度接近 React Hooks
- Template 對設計師友善（未來要協作）
- 這是練習專案，選一個能**深學**的框架比選**最熱門**重要

---

## 🗂️ Repo 結構

```
ez_crm_client/
├── src/
│   ├── api/
│   │   ├── client.ts            ← axios instance + interceptors
│   │   ├── auth.ts              ← register / login / verify / forgot / reset
│   │   ├── me.ts                ← /me 全部 endpoints
│   │   └── types.ts             ← ApiResponse<T> / Member / ApiCode 型別
│   ├── stores/
│   │   ├── auth.ts              ← Pinia: token / currentMember / login / logout
│   │   └── notifications.ts     ← 全域 toast 訊息
│   ├── router/
│   │   ├── index.ts
│   │   └── guards.ts            ← requireAuth / redirectIfAuth
│   ├── views/
│   │   ├── HomeView.vue
│   │   ├── LoginView.vue
│   │   ├── RegisterView.vue
│   │   ├── VerifyEmailView.vue
│   │   ├── ForgotPasswordView.vue
│   │   ├── ResetPasswordView.vue
│   │   ├── OAuthCallbackView.vue
│   │   ├── MeView.vue           ← /me dashboard
│   │   ├── MeEditView.vue       ← PUT /me
│   │   └── MePasswordView.vue   ← PUT /me/password
│   ├── components/
│   │   ├── DynamicForm.vue      ← 吃 schema 動態 render 的表單（給 register schema 用）
│   │   ├── OtpInput.vue         ← 6 格 OTP 輸入框（專業感）
│   │   ├── OAuthButton.vue      ← "Continue with Google / GitHub" 按鈕
│   │   ├── FormField.vue        ← 基礎 input + label + error
│   │   └── layout/
│   │       ├── AppHeader.vue
│   │       └── AppNav.vue
│   ├── composables/
│   │   ├── useAuth.ts
│   │   └── useApiError.ts       ← ApiCode → 使用者可讀訊息
│   ├── types/
│   │   └── api.d.ts
│   ├── App.vue
│   └── main.ts
├── tests/
│   └── e2e/
│       └── auth.spec.ts         ← Playwright（可選，後期加）
├── .env.development             ← VITE_API_BASE_URL=http://ez-crm.local/api/v1
├── .env.production
├── vite.config.ts
├── tsconfig.json
├── tailwind.config.js
├── package.json
└── README.md
```

---

## 🔑 Token 儲存策略

**選擇：localStorage**（先求能跑，XSS 防護靠 CSP header + React 輸出跳脫）

| 方案 | 優 | 缺 |
|---|---|---|
| **localStorage**（我們選這個） | 實作簡單、跨分頁共享 | XSS 可讀取 token |
| httpOnly cookie + Sanctum SPA auth | XSS 偷不到 | 需要 CSRF 處理、CORS credentials、同源限制多 |

未來升級路徑：如果真的要 production-grade，改走 Sanctum 的 SPA session auth（stateful），本 plan 保留彈性但不先做。

---

## 🌐 API Client 設計

### `src/api/client.ts` 骨架

```typescript
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import router from '@/router';

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: { 'Accept': 'application/json' },
});

// Request：自動附帶 Bearer token
apiClient.interceptors.request.use((config) => {
  const token = useAuthStore().token;
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Response：401 → 清 token + 導向 login；其他錯誤丟給 caller 處理
apiClient.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401 && err.response?.data?.code === 'A001') {
      useAuthStore().clearToken();
      router.push({ name: 'login', query: { expired: '1' } });
    }
    return Promise.reject(err);
  },
);
```

### ApiCode → 訊息映射（`useApiError.ts`）

```typescript
const MESSAGES: Record<string, string> = {
  A004: '帳號已停用，請聯繫客服',
  A005: 'Email 尚未驗證，請前往 Email 收取驗證碼',
  A007: '驗證碼錯誤或已過期',
  A008: '操作太頻繁，請稍後再試',
  A009: '帳號或密碼錯誤',
  V006: '該 Email 已被註冊',
  // ...
};
```

---

## 🔐 OAuth 流程(SPA 版)

這塊**會影響後端 Phase 4 實作**,先在這裡對齊設計。

### 方案 A：popup + postMessage(推薦,SPA 標準)

```
1. 使用者點 "Continue with Google"
2. frontend: window.open('/api/v1/auth/oauth/google/redirect-html', 'oauth', 'width=600,height=700')
   （開 popup,而非 top-level navigation）
3. backend redirect-html endpoint: return HTML page with <script>window.location=googleUrl</script>
   OR frontend: GET /api/v1/auth/oauth/google/redirect → 拿到 url → popup.location=url
4. 使用者在 Google 授權
5. Google redirect 到 backend /callback?code=xxx
6. backend 處理完,發 token,return HTML page:
     <script>
       window.opener.postMessage({ token, member }, '*');
       window.close();
     </script>
7. frontend 原本 window 收到 message,存 token + 導頁
```

**好處**:主視窗不離開,token 不出現在 URL,cross-origin safe。

### 方案 B：full redirect(簡單但 URL 帶 token)

```
1. frontend: window.location = googleOauthUrl（直接換頁）
2. Google → backend /callback → backend redirect to frontend/oauth/callback?token=xxx
3. frontend /oauth/callback 從 query 取 token，存到 store，導到 /me
```

**缺點**:token 在 URL 會進瀏覽器歷史、referer、可能被日誌記到。

**決策**:先做 **A(popup + postMessage)**,安全性較高。後端 callback 要改寫成 return HTML 而非 JSON。

---

## 🧭 路由規劃

```typescript
const routes = [
  { path: '/',                 name: 'home',            component: HomeView },
  { path: '/login',            name: 'login',           component: LoginView,            meta: { guest: true } },
  { path: '/register',         name: 'register',        component: RegisterView,         meta: { guest: true } },
  { path: '/verify',           name: 'verify',          component: VerifyEmailView,      meta: { guest: true } },
  { path: '/forgot-password',  name: 'forgot',          component: ForgotPasswordView,   meta: { guest: true } },
  { path: '/reset-password',   name: 'reset',           component: ResetPasswordView,    meta: { guest: true } },
  { path: '/oauth/callback',   name: 'oauth.callback',  component: OAuthCallbackView },
  { path: '/me',               name: 'me',              component: MeView,               meta: { requiresAuth: true } },
  { path: '/me/edit',          name: 'me.edit',         component: MeEditView,           meta: { requiresAuth: true } },
  { path: '/me/password',      name: 'me.password',     component: MePasswordView,       meta: { requiresAuth: true } },
];
```

Guards：
- `meta.requiresAuth` → 沒 token 導 `/login?redirect=<原路徑>`
- `meta.guest` → 已登入則導 `/me`

---

## 🎯 頁面流程圖

```
       ┌─────────────┐
       │ LoginView   │◀─────┐
       └──┬───────┬──┘      │
   email  │       │  OAuth  │ 失敗 → 顯示錯誤
          ▼       ▼         │
  ┌──────────┐ ┌─────────┐  │
  │ /me      │ │ popup   │──┘
  └──────────┘ └─────────┘
                   │ 成功
                   ▼
              postMessage
                   │
                   ▼
             token 存 store
                   │
                   ▼
              /me Dashboard
```

註冊流程：
```
RegisterView (動態表單)
     │ 送出
     ▼
POST /register → 201
     │
     ▼
VerifyEmailView (OTP 輸入)
     │
     ▼
POST /verify/email → 200 + token
     │
     ▼
存 token → /me
```

---

## 🚀 開發順序（建議）

| 階段 | 內容 | 估時 |
|---|---|---|
| 1 | `npm create vite@latest` + Vue 3 + TS 初始化，跑起來 | 30 分 |
| 2 | Pinia / Vue Router / Tailwind 裝配 | 30 分 |
| 3 | `api/client.ts` + auth store 骨架 | 1 小時 |
| 4 | Login + Register 流程（含 DynamicForm）| 2 小時 |
| 5 | Email Verify + Forgot / Reset | 1.5 小時 |
| 6 | OAuth popup 串接（後端要先改 callback 成 HTML）| 2 小時 |
| 7 | /me 三個頁面（show / edit / password）| 1.5 小時 |
| 8 | Error handling + toast notifications 全站 | 1 小時 |
| 9 | Deploy dev build + 測試 | 30 分 |
| **合計** | | **~10 小時（1.5-2 個工作日）** |

---

## 🧪 驗收標準

### MVP（第一版就要達到）

- [ ] 可以用 email + 密碼完整註冊 → 驗證 → 登入
- [ ] Google OAuth 可以走完整流程並拿到 token
- [ ] GitHub OAuth 同上
- [ ] /me 顯示自己的資料
- [ ] 可以改暱稱、電話、密碼
- [ ] 登出可以清 token + 導回 login
- [ ] Token 過期 / 錯誤 → 自動導 login
- [ ] RegisterView 真的用後端回傳的 schema 動態 render 欄位（證明前後端解耦設計有效）

### 後期再加

- [ ] 忘記密碼完整流程
- [ ] 所有裝置登出
- [ ] 註銷帳號
- [ ] Email 變更（Phase 5 剩下）
- [ ] Avatar 上傳（Phase 5 剩下）
- [ ] 多裝置管理（/me/devices）
- [ ] i18n（中 / 英切換）
- [ ] Dark mode

---

## 📦 後端對應調整（Phase 4 OAuth 前要先談）

1. **OAuth redirect endpoint** 回 JSON `{url}`（plan 已設計好）
2. **OAuth callback endpoint** 改成 return **HTML with postMessage**，而非 JSON：
   ```html
   <script>
     window.opener.postMessage({ success: true, token: '...', member: {...} }, 'http://ez-crm-client.local');
     window.close();
   </script>
   ```
3. **CORS 設定**：`config/cors.php` 加 allowed_origin `http://localhost:5173`（dev）與未來的 prod domain
4. **Sanctum 不需 SPA 模式**（因為我們走 Bearer token），但 `sanctum.stateful` 配置要確認不衝突

---

## 🗺️ Repo 啟動方式

```bash
# 初始化（明天開工第一件事）
cd c:\code
npm create vite@latest ez_crm_client -- --template vue-ts
cd ez_crm_client
npm install
npm install -D tailwindcss postcss autoprefixer @types/node
npm install axios pinia vue-router@4 @vueuse/core
npx tailwindcss init -p

# 建 git repo
git init
git remote add origin https://github.com/TuanTsungShiang/ez_crm_client.git
git branch -M main
git push -u origin main

# 開發
npm run dev  # → http://localhost:5173
```

---

## ❓ 待決事項（明天開工前確認）

1. Repo 要開在 GitHub 哪個帳號 / 組織?(同個 `TuanTsungShiang/ez_crm_client`?)
2. 路徑要放 `c:\code\` 還是 `c:\xampp\htdocs\` 外?(上次討論提過 htdocs 外比較乾淨)
3. Google / GitHub OAuth Client ID 你要何時申請?(建議:**做前端之前**先申請,才能同時驗 backend Phase 4 + 前端 OAuth 串接)
4. Deploy 目標:Netlify / Vercel / 自架?(dev 先不用決定)

---

## 📚 學習資源

- [Vue 3 官方文件](https://vuejs.org/)
- [Vue Router 4 官方文件](https://router.vuejs.org/)
- [Pinia 官方文件](https://pinia.vuejs.org/)
- [VueUse](https://vueuse.org/)
- [Tailwind CSS](https://tailwindcss.com/)
- [Laravel Sanctum SPA docs](https://laravel.com/docs/10.x/sanctum#spa-authentication)（未來升級參考）

---

**結論**：
- 明天先做 **後端 Phase 4 OAuth**（用 curl + 手動 redirect URL 測通）
- 接著開新 repo `ez_crm_client` 照本規格走
- 分開 commit 歷史、分開 CI/CD，長期可維護性最好
