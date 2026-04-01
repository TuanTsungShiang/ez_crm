# Token / 密碼安全管理

> 建立日期：2026-04-02

---

## 外露時的 SOP

1. **立刻 Revoke** — 不管是截圖、不小心 commit 進 git、貼到聊天室，立即撤銷
2. **重新產生** 新的 token
3. **檢查 git log** — 確認 token 沒有被 commit 進去

```bash
git log -p | grep ghp_
```

---

## 常見外露場景

| 情境 | 風險 |
|---|---|
| `.env` 不小心 commit | 高，永遠留在 git history |
| terminal 截圖分享 | 中，看到截圖的人都能用 |
| 貼到 Slack / Discord | 高，有 log 記錄 |
| hardcode 進程式碼 | 極高，push 後全世界看得到 |

---

## 預防措施

- `.env` 永遠加進 `.gitignore`，敏感資訊永遠不進 repo
- 使用 `secrets` 或環境變數管理 token，不要 hardcode
- CI/CD 的 token 統一用 GitHub Secrets 管理（`${{ secrets.XXX }}`）
- 定期 rotate token，不要讓同一個 token 用太久
