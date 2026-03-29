# Git 分支規劃

> 版本：v1.0
> 建立日期：2026-03-30

---

## 主要分支

| 分支 | 說明 |
|---|---|
| `main` | 正式環境，永遠保持可部署狀態，只接受來自 `release` 的 merge |
| `develop` | 開發主線，所有功能完成後 merge 回這裡 |

---

## 輔助分支

| 分支命名 | 從哪裡開 | merge 回去 | 說明 |
|---|---|---|---|
| `feature/xxx` | `develop` | `develop` | 新功能開發 |
| `release/x.x.x` | `develop` | `main` + `develop` | 準備上線，只做 bug fix 與版號更新 |
| `hotfix/xxx` | `main` | `main` + `develop` | 正式環境緊急修復 |

---

## 流程圖

```
main        ─────────────────────────────●─────────────●──▶
                                         ↑             ↑
release                            ●────●         ●───●
                                   ↑              ↑
develop     ──●────●────●────●────●───────●──────●────────▶
               ↑   ↓    ↑   ↓
feature        ●───●    ●───●
```

---

## 分支命名規則

```
feature/  功能名稱（kebab-case）    feature/member-search-api
release/  語意化版號                release/1.0.0
hotfix/   問題簡述（kebab-case）    hotfix/fix-member-login-error
```

---

## 標準工作流程

### 開發新功能

```bash
# 1. 從 develop 建立 feature 分支
git checkout develop
git checkout -b feature/xxx

# 2. 開發、commit
git add .
git commit -m "feat: xxx"

# 3. 完成後 merge 回 develop
git checkout develop
git merge --no-ff feature/xxx
git branch -d feature/xxx
```

### 準備上線（Release）

```bash
# 1. 從 develop 建立 release 分支
git checkout develop
git checkout -b release/1.0.0

# 2. 只做 bug fix、版號更新
git commit -m "chore: bump version to 1.0.0"

# 3. merge 回 main（正式上線）
git checkout main
git merge --no-ff release/1.0.0
git tag -a v1.0.0 -m "release v1.0.0"

# 4. 同步回 develop
git checkout develop
git merge --no-ff release/1.0.0
git branch -d release/1.0.0
```

### 緊急修復（Hotfix）

```bash
# 1. 從 main 建立 hotfix 分支
git checkout main
git checkout -b hotfix/xxx

# 2. 修復、commit
git commit -m "fix: xxx"

# 3. merge 回 main
git checkout main
git merge --no-ff hotfix/xxx
git tag -a v1.0.1 -m "hotfix v1.0.1"

# 4. 同步回 develop
git checkout develop
git merge --no-ff hotfix/xxx
git branch -d hotfix/xxx
```

---

## Commit Message 規範

```
feat:      新功能
fix:       Bug 修復
refactor:  重構（不影響功能）
docs:      文件更新
test:      測試相關
chore:     雜項（設定、套件更新）
```

### 範例

```
feat: add member search API with keyword and tag filters
fix: resolve member_sns duplicate key error on OAuth login
refactor: extract search logic into MemberSearchService
docs: update member schema with channel support notes
chore: bump laravel/framework to 10.50.2
```

---

## 目前專案分支狀態

| 分支 | 狀態 | 說明 |
|---|---|---|
| `main` | 🟢 active | 初始版本，Laravel 10 + member migrations |
| `develop` | 🟢 active | 開發主線 |
| `feature/member-search-api` | 🔨 in progress | 會員搜尋 API 開發中 |
