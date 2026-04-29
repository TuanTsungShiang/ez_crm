# Static Analysis 啟用計畫(ENGINEERING W2)

> 版本:v1.0
> 建立日期:2026-04-29
> 狀態:📋 Plan(尚未實作)
> 目標截止:**2026-05-05**(對應 ENGINEERING_INFRASTRUCTURE_ROADMAP W2)
> 估時:後端 4h + 前端 4h ≈ **1 個工作天**(可拆 2 個半天)
> 前置依賴:無(Phase 1 已完成,baseline 穩定;PHPUnit CI 已就位 W1)

---

## 一、為什麼做(Context)

W1 PHPUnit CI 確保「**code 跑得起來**」(行為層守線);W2 Static Analysis 確保「**code 寫得對**」(型別/語意層守線)。

兩層守線缺一不可:
- 沒 PHPUnit:bug 上 prod
- 沒 PHPStan / strict TS:**「跑得起來但邏輯錯」**(null pointer、undefined property、wrong type 傳入)
- 兩層都沒:就是 Linky360

Phase 2 即將動工(Points / Coupon / Order)— 涉及金錢/餘額/冪等,**型別不對的代價遠大於慢一週裝 PHPStan**。

---

## 二、範圍

### In scope(W2 必做)
- ✅ 後端 Larastan(PHPStan + Laravel-aware extensions)level 5 → 6,baseline 模式接納既有 code
- ✅ 前端 ESLint + Prettier 標準配置
- ✅ 前端 TypeScript `strict: true`
- ✅ CI 整合(GitHub Actions 加 step,失敗紅燈)

### Out of scope(W3 之後)
- ❌ Pre-commit hook(W3 主題,husky / captainhook)
- ❌ PHPStan level 7+(level 6 是 senior 起跳線,7+ 之後增量推進)
- ❌ Coverage 報告(W2 不算範圍)

---

## 三、後端:Larastan(PHPStan)

### 現況
- 0 phpstan / larastan dependency
- 0 `phpstan.neon` 配置
- ✅ `laravel/pint` 已裝(formatter,跟 static analysis 互補不重疊)

### 安裝

```bash
composer require --dev larastan/larastan:^3.0
```

> Larastan 包了 phpstan 主套件 + Laravel-aware extensions(知道 Eloquent magic、Facade、Container resolve 等)。直裝 phpstan 會誤報一堆 false positive。

### 配置範本 — `phpstan.neon`(repo root)

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    # 啟用 baseline 後,既有 error 凍結,新 code 強制達標
    - phpstan-baseline.neon

parameters:
    level: 5

    paths:
        - app
        - database/seeders
        - database/factories
        - tests

    excludePaths:
        - app/Filament/Resources/*/Pages/*    # Filament 自動產的 page 多 dynamic call
        - bootstrap/cache

    # 執行時的 memory limit;Laravel 大專案常吃到 512M
    memoryLimitFile: .phpstan-memory-limit
```

### baseline 策略 — 重點

直接 level 5 跑會吐**幾百個** error(Member model 的 magic property、Eloquent relation 等)。處理方式:

1. **第一次跑**:`vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`
   → 凍結既有 error,baseline 檔進 git
2. **新 code 強制達標**:之後 commit 引入新 error 立刻紅燈,baseline 不會自動成長
3. **逐步消化**:每週清理 baseline 裡 5–10 條(refactor / type hint 補齊),目標 3 個月內歸零
4. **絕不**用 `--allow-empty-baseline`(那會把所有 error 都「合法化」)

### Level 升級節奏

| Level | 範圍 | 目標時間 |
|---|---|---|
| 5(起點)| 基本型別、未定義 method/property | W2 完成日(2026-05-05)|
| 6 | 加上 missing typehint,所有 callable / array shape | 2026-05-19(2 週後)|
| 7 | 嚴格 partial type、call on null | 2026-06-30(1 個月後)|
| 8(stretch)| 完全 strict,no mixed | 不主動推,看 baseline 消化進度 |

### CI 整合

`.github/workflows/phpunit.yml` 末尾加一個 step(或拆 `.github/workflows/phpstan.yml` 獨立 job 平行跑):

```yaml
- name: PHPStan
  run: vendor/bin/phpstan analyse --memory-limit=512M --no-progress
```

> 不加 `--ansi`(GitHub log 會有色碼髒污);獨立 job 平行跑可以縮 CI 時間從 5min → 3min。

### 預期踩坑

| 坑 | 解法 |
|---|---|
| Eloquent `$model->relation` 報「undefined property」 | Larastan 會處理 95%,剩下用 `@property` PHPDoc 標記 |
| `Filament\Forms\Components\TextInput::make()->required()` 鏈式報錯 | 升 phpstan 9.0 + Filament 自帶 stub;暫時加進 baseline |
| spatie/permission `$user->can('member.view_any')` 字串 | level 5 不會報;level 8 才會強制 enum,W2 不到那 |
| Memory limit 爆掉 | `--memory-limit=512M` 通常夠;不夠加到 1G,實在不行就 split paths 跑 |

---

## 四、前端:ESLint + Prettier + TS strict

### 現況
- 0 eslint / prettier
- `tsconfig.app.json`:有 `noUnusedLocals` / `noUnusedParameters`,但 **沒 `strict: true`**
- 已有 `vue-tsc -b` 做 type check(build 時),但不是 strict 模式

### 安裝

```bash
npm i -D \
  eslint \
  @eslint/js \
  typescript-eslint \
  eslint-plugin-vue \
  vue-eslint-parser \
  @vue/eslint-config-typescript \
  eslint-plugin-prettier \
  eslint-config-prettier \
  prettier
```

> 用 ESLint 9 的 **flat config**(`eslint.config.js`),不要再開新 repo 用舊的 `.eslintrc.js`。

### 配置範本 — `eslint.config.js`(repo root)

```js
import js from '@eslint/js'
import vue from 'eslint-plugin-vue'
import ts from 'typescript-eslint'
import prettier from 'eslint-plugin-prettier'
import prettierConfig from 'eslint-config-prettier'

export default [
  js.configs.recommended,
  ...ts.configs.recommended,
  ...vue.configs['flat/recommended'],
  prettierConfig,
  {
    files: ['**/*.{js,ts,vue}'],
    languageOptions: {
      parserOptions: { parser: ts.parser },
    },
    plugins: { prettier },
    rules: {
      'prettier/prettier': 'error',

      // 對齊本專案規範(對 Linky360 inline style 的反面,參考昨天討論)
      'vue/no-restricted-syntax': ['error', {
        selector: "VAttribute[key.name='style']",
        message: '禁止 inline style,請用 Tailwind class 或 scoped CSS',
      }],

      // TS strict 補強
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      '@typescript-eslint/consistent-type-imports': 'error',

      // Vue 3 慣例
      'vue/component-name-in-template-casing': ['error', 'PascalCase'],
      'vue/no-v-html': 'warn',
    },
  },
]
```

### `prettier.config.js`(對齊現有風格)

```js
export default {
  semi: false,
  singleQuote: true,
  trailingComma: 'all',
  printWidth: 100,
  vueIndentScriptAndStyle: true,
}
```

### `tsconfig.app.json` 改動

```json
{
  "compilerOptions": {
    "strict": true,                    // ← 加
    "noUncheckedIndexedAccess": true,  // ← 加(strict 額外:array[i] 自動是 T | undefined)
    // 既有的 noUnusedLocals 等保留
  }
}
```

### npm scripts 補

```json
"scripts": {
  "dev": "vite",
  "build": "vue-tsc -b && vite build",
  "preview": "vite preview",
  "lint": "eslint . --max-warnings=0",
  "lint:fix": "eslint . --fix",
  "type-check": "vue-tsc --noEmit",
  "format": "prettier --write ."
}
```

### CI 整合(`ez_crm_client` 自己的 workflow)

需要先建 `.github/workflows/test.yml`(這是 W1 後端做了但前端沒做的部分,可順便補):

```yaml
name: Frontend
on:
  push: { branches: [main, develop] }
  pull_request: { branches: [main, develop] }

jobs:
  lint-and-typecheck:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm' }
      - run: npm ci
      - run: npm run type-check
      - run: npm run lint
      # vitest 留 W1 補完(目前無 frontend test)
```

### 預期踩坑

| 坑 | 解法 |
|---|---|
| TS strict 開了立刻一堆 `null` / `undefined` error | 先列 `compilerOptions` 用 `// @ts-expect-error` 暫時標,逐個收 |
| Vue `<script setup>` 的 `defineProps<{}>` 報 type | 確保 `vue-tsc` ≥ 3.0(已在 `~6.0.2`,OK)|
| Prettier 跟 ESLint 在 quote / semi 上打架 | `eslint-config-prettier` 已經在配置 disable 衝突 rule |
| Vue template 裡 `:style="{}"` 動態綁定誤報 | `vue/no-restricted-syntax` selector 用 `VAttribute[key.name='style']` 只抓**靜態** `style="..."`,不抓 `:style="{}"` 的動態綁定 — selector 寫法重點 |
| 既有 `.vue` file 一次性 lint --fix 引發大 diff | **獨立 commit** 「ci: apply prettier + eslint --fix to existing code」,review 時看 prettier 改動容易 |

---

## 五、執行順序(建議 1 工作天節奏)

### 早上(後端,~3-4h)

1. (15min)`composer require larastan` + 建 `phpstan.neon`(level 5)
2. (15min)第一次跑 `vendor/bin/phpstan analyse --generate-baseline`,生成 baseline
3. (30min)看 baseline 有多大,**手動清掉**「明顯該修不該凍結」的 5-10 條
4. (30min)CI workflow 加 step,本地 + push 確認 CI 綠
5. (1h)寫 ADR-0002 紀錄 baseline 策略 + level 升級節奏

### 下午(前端,~3-4h)

6. (30min)`npm i -D` 全套 + 建 `eslint.config.js` + `prettier.config.js`
7. (30min)`npm run lint:fix` + `npm run format` 一次過所有 .vue / .ts 檔(獨立 commit)
8. (30min)tsconfig 加 `strict: true`,跑 `npm run type-check` 看 error 數
9. (1-2h)修 type-check error(預估 5-15 個,大多是 props 缺 type、ref<T | null> 沒處理)
10. (30min)寫 `.github/workflows/test.yml`(前端 CI 第一個 workflow)
11. push 看綠燈

---

## 六、驗收標準

對齊 ENGINEERING_INFRASTRUCTURE_ROADMAP W2 的驗收 checklist:

- [ ] 後端 `vendor/bin/phpstan analyse` 跑出 0 error(透過 baseline)
- [ ] 後端 CI 加 phpstan step,綠燈
- [ ] 前端 `npm run lint` + `npm run type-check` 都 0 error 0 warning
- [ ] 前端 CI 第一次跑(`Frontend` workflow)綠燈
- [ ] 前端 README 加 lint badge(可選)
- [ ] phpstan-baseline.neon 進 git,初始 baseline 數紀錄在 ADR-0002
- [ ] 故意加一條 `$user->wrongMethod()` 在 PR 中,CI 紅燈擋下

---

## 七、產出文件

實作完成時應該有:

1. `phpstan.neon` + `phpstan-baseline.neon`(後端)
2. `eslint.config.js` + `prettier.config.js`(前端)
3. `tsconfig.app.json` 開 strict(前端)
4. `.github/workflows/phpstan.yml` 或現有 phpunit.yml 加 step(後端)
5. `.github/workflows/test.yml`(前端,新建)
6. `work_log/2026MMDD/adr_0002_static_analysis_baseline.md`(決策紀錄)
7. session log update

---

## 八、跟 Phase 2 的時序

W2 完成前**不要**動 Phase 2 Points 大規模 code。理由:

- Phase 2 一進去新 code 量級會跳到「200-500 行/週」
- 等 Points 寫了一半才裝 PHPStan,baseline 會把 Points 的 error 一起凍結 → 失去守線意義
- 標準節奏:**W2 完成 → 5/6 起 Phase 2**(剛好對齊 ENGINEERING_ROADMAP W2 截止 5/5)

---

## 九、Stretch — 順手做這些(時間有餘再說)

| 加分項 | 估時 | 為什麼有意義 |
|---|---|---|
| 後端 Pint 配 `pint.json` 對齊團隊風格 | 30min | format 跟 static analysis 互補,且 Pint 已裝 |
| 前端 `vue/no-static-inline-styles` 搭配 [eslint-plugin-tailwindcss] | 1h | Tailwind class 排序 + 禁 inline style 雙保險 |
| 加 stylelint 擋 `!important` 濫用 | 1h | 對 Linky360 那種 `!important` 滿天飛的反面教材 |
| `.github/CODEOWNERS` | 15min | 大家都不寫,senior repo 有 |

---

## 十、參考

- [Larastan 官方 ruleset](https://github.com/larastan/larastan)
- [ESLint flat config 移植指南](https://eslint.org/docs/latest/use/configure/configuration-files)
- ENGINEERING_INFRASTRUCTURE_ROADMAP.md 主題 2(本 plan 的 source of truth)
- ADR-0001(RBAC baseline)— 同樣的 baseline 哲學:source of truth in code
