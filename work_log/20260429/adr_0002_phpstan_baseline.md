# ADR-0002: PHPStan Baseline Strategy

> Status: **Accepted** (2026-04-29)
> Deciders: Kevin, Claude
> Related: ENGINEERING_INFRASTRUCTURE_ROADMAP W2 / STATIC_ANALYSIS_PLAN.md
> Builds on: ADR-0001(RBAC baseline 哲學:source of truth in code)

---

## Context

W1 PHPUnit CI 已覆蓋「**行為層**」守線(code 跑得起來、輸出對),但缺「**型別/語意層**」守線:
- Eloquent magic property / relation 拼錯不會被擋
- `null` / `undefined property` 容易在 prod 才暴露
- Phase 2 即將動工(Points / Coupon / Order),涉及金錢計算與冪等,**型別錯的代價遠大於現在裝 PHPStan 的成本**

對齊 ENGINEERING_INFRASTRUCTURE_ROADMAP 的 W2 範圍(deadline 2026-05-05),今天動工後端部分。

---

## Decision

採用 **Larastan v2.x**(基於 PHPStan v2,含 Laravel-aware extensions)+ **baseline 模式**:

### 起跳配置
- **Level 5**(基本型別 + 未定義 method/property)
- 範圍:`app/` + `database/seeders/`(暫不含 tests,test code 大量 magic 容易誤報)
- baseline 凍結既有 63 個 error,**baseline 進 git** 作為 source of truth

### Source of truth(對齊 ADR-0001 哲學)
- `phpstan.neon` 寫死 level + paths + excludePaths
- `phpstan-baseline.neon` 是「歷史欠債清單」— 進版控,review 看得到
- **新 commit 引入新 error 立刻紅燈**,baseline 不會自動成長
- Custom 的 ignore pattern 一律不用(維持 baseline 是唯一例外通道)

### Level 升級節奏
| Level | 目標日期 | 預估剩餘 baseline error |
|---|---|---|
| 5 | 2026-04-29(今天)✅ | 63(初始) |
| 5 + baseline 消化過半 | 2026-05-12 | < 30 |
| 6 | 2026-05-19(2 週後) | TBD |
| 7 | 2026-06-30(2 個月後) | TBD |
| 8 | 不主動,看 baseline 消化進度 | — |

### CI 整合
PHPStan step 加在既有 `.github/workflows/phpunit.yml` 的 PHPUnit 之前(fast fail 原則:30 秒的 phpstan 比 4 分鐘的 phpunit 早回饋)。共用 composer cache step,不獨立 workflow file 避免重複設定。

---

## Consequences

### ✅ Pros
- Phase 2 新 code 從第一行開始就被 type 守線
- baseline 進 git → review PR 時看 baseline 變動就能評估「這個 PR 是清債還是欠債」
- Larastan 對 Eloquent / Facade / Container 有原生支援,90% magic property 不需要手動標 `@property`
- Level 升級節奏可量化(baseline 數遞減 = 進度可視化)

### ❌ Cons / Trade-offs
- baseline 一開始 63 條,**心理不爽** — 但接受「歷史債」比「假裝沒事」誠實
- Larastan v2 跟 PHPStan v2 的 deprecation(`checkMissingIterableValueType`)需要注意,直接升 level 6 時會踩
- **可修但沒修的 30+ 條 relation type hint**(Eloquent `function group(): BelongsTo` 等)被凍結,得在 baseline 消化階段補回 — 帳本上明確看得到

---

## Alternatives considered

### Alt A — 不裝 PHPStan,只靠 PHPUnit + code review ❌
- 行為層守線存在,但型別錯會在 prod 才被使用者觸發
- Phase 2 涉及錢的場景無法接受這風險

### Alt B — 直接 level 8 起跳 ❌
- 預估會吐 800+ error,baseline 太大失去意義
- 「逐步推進」比「一次到位」可永續(同 ADR-0001 哲學)

### Alt C — Custom ignore patterns 散在各檔(`@phpstan-ignore-line`)❌
- 散在 code 各處,review 看不到全貌
- baseline 集中管理,單一 source of truth

### Alt D — 走 Psalm 而非 PHPStan ❌
- Larastan 對 Laravel 整合更深(Filament / Sanctum / Eloquent magic 都有 stub)
- 業界 Laravel 生態 Larastan 是事實標準

---

## 初始 baseline 統計(2026-04-29)

```
Total: 63 errors at level 5
```

主要分類(預估,從 baseline 看):
| 類別 | 數量(估)| 修法 |
|---|---|---|
| Eloquent relation `$model->relation` 沒 type hint | ~25 | 8 個 model 的 relation method 加 return type hint |
| API Resource `toArray()` 用 `$this->property` | ~25 | 加 `@mixin Member` PHPDoc 或重寫成顯式取值 |
| Sanctum `currentAccessToken()->id/delete()` | ~3 | instanceof check / PHPDoc cast |
| Socialite `stateless()` | ~2 | PHPDoc cast 或 phpstan ignore |
| Match expression 不完整 | ~2 | 加 default case |
| FormRequest `$request->id` union(object|string)| ~3 | cast 或 type narrow |
| 其他 | ~3 | case-by-case |

**第一波消化(2026-05-05 到 5-12)**:
- 集中清 relation type hint(收益最大,~25 條)
- 預期降到 ~38 baseline

---

## References

- `phpstan.neon` — 主配置(level + paths)
- `phpstan-baseline.neon` — 凍結的 63 條 error
- `.github/workflows/phpunit.yml` — CI 整合(PHPStan step 在 PHPUnit 之前)
- `scheme/improvement_plan/2026/04/STATIC_ANALYSIS_PLAN.md` — 完整 W2 plan(後端 + 前端)
- ADR-0001(RBAC baseline)— 同一 source-of-truth 哲學
