# ADR-0003: Frontend ESLint + Prettier + TypeScript Strict

> Status: **Accepted** (2026-04-29)
> Deciders: Kevin, Claude
> Related: ENGINEERING_INFRASTRUCTURE_ROADMAP W2 / STATIC_ANALYSIS_PLAN.md
> Builds on: ADR-0002(後端 PHPStan baseline)— 同一份 W2 主題,前後端兩半

---

## Context

ENGINEERING W2 主題是 Static Analysis,後端 PHPStan 早上完成(ADR-0002)。前端 ez_crm_client 啟動時用了 Vue 3 + TypeScript,但**完全沒裝 ESLint / Prettier**,且 `tsconfig.app.json` 雖然有 `noUnusedLocals` 但沒開 `strict`(只靠 vue-tsc build 時的 type check)。

Phase 2 將擴大前端範圍(Points 領取頁 / 會員等級顯示 / Coupon 兌換 UI 等),沒守線會踩跟後端一樣的問題:
- `null` / `undefined` access 在 prod 才暴露
- props 型別不對(senders 傳 string,receiver 預期 number)
- inline style 蔓延(Linky360 3,554 處的反面教材)

---

## Decision

### Stack(對齊 STATIC_ANALYSIS_PLAN 已寫的選型)

- **ESLint 9 flat config**(不用舊的 `.eslintrc.js`)
- `typescript-eslint` + `eslint-plugin-vue` (`flat/recommended`) + `eslint-plugin-prettier` + `eslint-config-prettier`
- **Prettier 3** 對齊既有風格(no semi / single quote / trailing all / 100 col)
- **TypeScript `strict: true` + `noUncheckedIndexedAccess: true`**

### House Rules(寫死在 eslint.config.js)

```js
'vue/no-restricted-syntax': ['error', {
  selector: "VAttribute[key.name='style']",
  message: '禁止 inline style,請用 Tailwind class 或 scoped CSS。動態值請用 :style="{}"。',
}]
```

這條 rule 是對 2026-04-28 跟 Kevin 討論 Linky360 codebase 有 3,554 處 inline style 的具體預防 — **「規範 + 工具 = 紀律」**:工具強制,不靠人類自律。

`:style="{}"` 動態綁定**不**會被擋(AST 不同 node:`VDirective` 而非靜態 `VAttribute`),允許進度條 / 動態高度等合法用例。

### TS Strict 拆解
- `strict: true` 開全套(noImplicitAny / strictNullChecks 等)
- 加 `noUncheckedIndexedAccess`:`array[i]` 自動是 `T | undefined`,強迫處理 boundary
- 既有 `noUnusedLocals` / `noUnusedParameters` 保留

### CI

獨立 workflow `.github/workflows/frontend.yml`(不像後端塞進 phpunit.yml,因為前端沒 PHPUnit 可附);兩 step:
1. `npm run type-check`(vue-tsc strict mode)
2. `npm run lint`(ESLint + Prettier check)

`--max-warnings=0` 嚴格模式。

---

## 處理既有 code 的策略

### 階段 1:Lint 自動 fix(已完成)
- 第一次裸跑:**2,027 problem(2,026 error + 1 warning)**
- `npm run lint:fix` + `npm run format` 一次過:剩 **13 error**
- 13 個全是 browser globals 沒被認識(`sessionStorage` / `window` / `setTimeout` / `console`)
- 加 `globals.browser` 進 eslint.config:剩 **1 error**
- 最後 1 個是 `src/stores/auth.ts` 的 unused `ref` import,手動修掉
- **最終:0 error 0 warning**

### 階段 2:TS Strict 開(意外順利)
- 開 `strict: true` + `noUncheckedIndexedAccess` 後跑 `npm run type-check`
- **0 error** — 既有 code 寫得相當謹慎,可能是 vue-tsc -b build 時就用了部分 strict
- 沒有 baseline 機制需求(對比後端 63 條 baseline)

### 階段 3:Prettier mass commit(獨立)
為了 review 容易,**「tooling 配置」跟「prettier --write 大改動」分兩個 commit**:
- C1 `9f6587a feat(tooling)`:配置 + deps + 1 行手修
- C2 `59a7598 style: apply prettier`:18 個 .vue/.ts 純格式重排,review 時知道「這 commit 不會有邏輯改動」

---

## Consequences

### ✅ Pros
- 前端從第一行新 code 就被 lint + type 雙線守護
- inline style 結構性禁止 → Linky360 那種債永遠不會在這累積
- 後端 PHPStan + 前端 ESLint/TS strict 統一守線哲學,**W2 主題完整收尾**
- type-check 跟 lint 在 CI 跑得快(<1 分鐘),feedback loop 快
- ESLint flat config 是 ESLint 10+ 的未來,沒走舊的 `.eslintrc` 過渡規格

### ❌ Cons / Trade-offs
- 新加 119 packages dev dep,`node_modules` 變大(但只影響 CI / dev,prod build 不受影響)
- prettier mass commit `59a7598` 有 955 行 insert / 1052 行 delete 的 diff,git blame 會被沖一波(但 follow-up commit 影響有限)
- TS strict 對未來新 code 要求高 — 寫 `let foo: string` 不能 `foo = null`,需要 `string | null`,新人需學習曲線

---

## Alternatives considered

### Alt A — 不裝 ESLint,只開 TS strict ❌
- TS 不擋 style / convention(component 命名、props 順序、inline style)
- 半套守線等同沒守線

### Alt B — 用舊式 `.eslintrc.cjs` ❌
- ESLint 10 已預設 flat config,舊式進入 deprecation
- 新專案沒理由寫 legacy 配置

### Alt C — Biome(Rust 寫的 lint+format 一體)❌
- Biome 對 Vue 支援還在 alpha
- ESLint 9 flat + Prettier 3 已成熟,生態完整(plugin 多)
- 等 Biome 對 Vue 穩定再評估遷移(stretch goal)

### Alt D — TS strict 但不開 noUncheckedIndexedAccess ❌
- noUncheckedIndexedAccess 是 senior 級的 strict — 強迫處理 array boundary,catch 大量「為什麼 undefined ?」bug
- 既然開了沒額外 error,沒理由不開

---

## 跟 ADR-0002 的對照

| 維度 | ADR-0002 後端 PHPStan | ADR-0003 前端 ESLint+TS |
|---|---|---|
| 既有 code error 數 | 63 | 0(lint 自動 fix 後)+ 0(TS strict)|
| 需要 baseline? | ✅ 是 | ❌ 不需要 |
| Level | level 5 起,目標 6 / 7 | strict + noUncheckedIndexedAccess(已最高)|
| House rule 客製 | 無 | 禁 static inline style |
| CI 整合 | 加 step 到 phpunit.yml | 獨立 frontend.yml |

**為什麼前端 0 error 後端 63 error**:
- 後端 Eloquent magic property / API Resource 用 `$this->` 動態存取,phpstan 不知道 wrap 的是哪個 model — 結構性問題
- 前端 TypeScript 完全 explicit,而且 Vue 3 `<script setup>` + `defineProps<{}>` 的 type 是強制的 — 寫法本身就 type-safe

---

## References

- `eslint.config.js` — flat config 主檔
- `prettier.config.js` — 格式風格
- `.prettierignore` — 歷史 doc(work_log / scheme / README)排除
- `tsconfig.app.json` — strict + noUncheckedIndexedAccess
- `.github/workflows/frontend.yml` — 前端 CI 第一個 workflow
- ADR-0002(後端 PHPStan)— W2 另一半
- 2026-04-28 SESSION_LOG「規範 + 工具 = 紀律」討論 — 本 ADR 的 inline style 禁令來源
