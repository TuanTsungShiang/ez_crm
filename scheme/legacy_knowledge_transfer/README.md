# Legacy Knowledge Transfer

> 從 linky_360 專案中萃取的可重用設計模式與業務邏輯經驗  
> 目的：整理 kevino430 在 linky_360 五年期間的個人技術資產，作為 ez_crm 設計參考與面試材料

## 文件清單

| 主題 | 檔案 | 我的貢獻深度 |
|------|------|------------|
| User Insight 行為分析 | [01_user_insight.md](01_user_insight.md) | ★★★★★ 主要維護者（user_insight_process.php 52%）|
| BigQuery ETL Pipeline | [02_bigquery_etl.md](02_bigquery_etl.md) | ★★★ 長期維護者，DI-Tag 模組 26% |
| Campaign Automation | [03_campaign_automation.md](03_campaign_automation.md) | ★★ 週邊參與，Targeting/AB Testing 部分貢獻 |
| **JSON API SQL Injection 防護架構** | [04_jsonapi_security_architecture.md](04_jsonapi_security_architecture.md) | **★★★★★★ 架構設計者 + 主要實施者（皇冠級作品，1,005 行原創、9 天交付、82 endpoint 零修改）** |

## 為什麼要寫這些文件

1. **不是所有寫過的程式碼自己都記得**  
   linky_360 5 年累積 4,922 commits，許多設計決策和踩過的雷已經被時間沖淡。離開前必須把它們挖出來。

2. **這是面試的彈藥庫**  
   面試官問「你做過最複雜的系統？」時，你需要具體的故事 + 程式碼引用 + 數字。這些文件就是答案的素材。

3. **這是 ez_crm 的設計參考**  
   linky_360 解決過的問題，ez_crm 不需要重新踩雷。把好的設計搬過來，把壞的設計避開。

4. **這是離職的證明**  
   git history 在 linky_360 的 repo 裡。如果有一天那個 repo 的存取權沒了，這份文件就是你唯一的證據。

## 撰寫原則

- **程式碼引用必須有 commit hash**：證明這是你寫的，不是你嘴的
- **技術決策必須說明 why**：面試官會問「為什麼這樣做？」
- **把業務邏輯抽象成可遷移的模式**：BigQuery 不能搬，但設計思路可以
- **明確標示「可借鏡到 ez_crm 的部分」**：這是文件的最終目的

## 不在這份文件裡的東西

- linky_360 的祕密商業邏輯（避免洩密）
- 任何客戶資料 / API key / 內部 URL
- 對其他同事的批評（git 證據對事不對人）
