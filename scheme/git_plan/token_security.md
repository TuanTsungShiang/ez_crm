# Token / 密碼安全管理

> 版本：v2.0（2026-04-15 擴充，含 PAT 使用 SOP 與實戰教訓）
> 建立日期：2026-04-02

---

## 核心原則

1. **Token = 密碼**：外洩等同帳號被盜
2. **最小權限**：只勾用得到的 scope
3. **定期輪替**：建議 90 天換一次
4. **絕不明文儲存**：不放 code、不放對話、不放 email
5. **一旦懷疑洩漏，立刻撤銷**

---

## 外露時的 SOP

1. **立刻 Revoke** — 不管是截圖、不小心 commit 進 git、貼到聊天室，立即撤銷
2. **重新產生** 新的 token
3. **檢查 git log** — 確認 token 沒有被 commit 進去

```bash
git log -p | grep ghp_
```

4. **檢查 `.git/config`** — 本地有沒有殘留明文

```bash
cat .git/config | grep -i token
```

---

## 常見外露場景

| 情境 | 風險 |
|---|---|
| `.env` 不小心 commit | 高，永遠留在 git history |
| terminal 截圖分享 | 中，看到截圖的人都能用 |
| 貼到 Slack / Discord | 高，有 log 記錄 |
| 貼到 AI 對話（ChatGPT / Claude） | 高，對話 log 可能被檢視 |
| hardcode 進程式碼 | 極高，push 後全世界看得到 |
| `git remote set-url https://TOKEN@...` | 高，明文存在 `.git/config` |

---

## GitHub PAT 使用 SOP

### 何時需要 PAT？

大部分情況**不需要**。Git Credential Manager 預設會處理 OAuth。

需要 PAT 的情境：

- 修改 `.github/workflows/*.yml`（OAuth app 無此權限）
- CI / 自動化腳本需要非互動式認證
- 某些第三方工具不支援 OAuth

### 產生 PAT

到 https://github.com/settings/tokens → Generate new token (classic)

| 欄位 | 建議值 |
|---|---|
| Note | 描述用途（例：`ez_crm local dev`） |
| Expiration | **90 days**（不要選 No expiration） |
| Scopes | 只勾用得到的（通常 `repo` + `workflow` 夠） |

---

## 儲存 Token 的正確位置（Windows）

### Git Credential Manager（推薦）

Windows 裝完 Git for Windows 後預設啟用。

#### 確認啟用

```bash
git config --global credential.helper
```

應回傳 `manager-core` 或 `manager`。若沒設定：

```bash
git config --global credential.helper manager-core
```

#### 觸發認證

第一次 push 時會彈出視窗：
- 瀏覽器：GitHub OAuth 登入
- CLI：username + password（password 欄位**貼 token**）

輸入一次系統記住，之後 push 不用再輸。

#### 管理位置

`控制台 → 認證管理員 → Windows 認證 → 找 git:https://github.com`

- 編輯：更新 token
- 刪除：下次 push 會再彈認證視窗

---

## 預防措施

- `.env` 永遠加進 `.gitignore`
- 使用 `secrets` 或環境變數管理 token，不要 hardcode
- CI/CD 的 token 用 GitHub Secrets（`${{ secrets.XXX }}`）
- 定期 rotate token
- token 只貼進官方驗證視窗 / 環境變數 / Credential Manager，**不貼給人或 AI**

---

## 絕對不要做的事

| 動作 | 原因 |
|---|---|
| 把 token 貼給 AI（Claude / ChatGPT） | 對話 log 可能被第三方看到 |
| `git remote set-url origin https://TOKEN@github.com/...` | Token 明文存在 `.git/config` |
| commit token 到 repo | 即使 revert，git history 還是有 |
| 截圖分享時沒塗掉 token | 常見意外洩漏管道 |
| 跨電腦複製同一個 token | 應該每台電腦各自的 token（或用 SSH key） |

---

## 實戰教訓

### 2026-04-15 事件

**場景**：修改 `.github/workflows/pr-review.yml` 被 remote 拒絕（OAuth app 無 `workflow` scope）

**過程**：臨時產 PAT → 貼到 Claude 對話 → Claude 用 PAT 完成 push → 事後撤銷

**問題**：雖然功能完成了，但 PAT 曾經出現在對話 log 中

**正確做法應該是**：
- 方案 A：在 GitHub 網頁 UI 直接編輯檔案（最安全）
- 方案 B：產 PAT 後**自己**執行 `git push`（Git Credential Manager 會彈窗，輸入 token），AI 只提供指令步驟
- 方案 C：改用 SSH key（長期方案）

**教訓**：
- AI 協助 ≠ 把機密交給 AI 執行
- 遇到需要機密的操作，AI 給指引，人操作
- 事後立即撤銷 + 清理 remote URL + 輪替所有活躍 token

---

## 相關文件

- [branching_strategy.md](branching_strategy.md)
- [GitHub PAT 官方文件](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens)
