# Member 會員模組 Schema 規劃

> 版本：v1.0
> 建立日期：2026-03-30
> 說明：涵蓋會員帳號、個人資料、第三方登入、安全紀錄、CRM 標籤與分群。
> 不包含：金流、點數、交易相關資料。

---

## 關聯總覽

```
members
  ├── member_sns              (一對多) 第三方 OAuth 綁定
  ├── member_profiles         (一對一) 延伸個人資料
  ├── member_addresses        (一對多) 多組地址
  ├── member_verifications    (一對多) Email / 手機 OTP 驗證
  ├── member_login_histories  (一對多) 登入紀錄
  ├── member_devices          (一對多) 裝置推播 Token
  ├── member_groups           (多對一) 會員分群
  └── tags                    (多對多 via member_tag) 會員標籤
```

---

## Table 詳細說明

---

### 1. `members` — 會員主表

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| uuid | CHAR(36) UNIQUE | 對外公開用唯一識別碼 |
| member_group_id | BIGINT UNSIGNED FK | 所屬分群 |
| name | VARCHAR(100) | 真實姓名 |
| nickname | VARCHAR(100) NULL | 暱稱 |
| email | VARCHAR(191) UNIQUE NULL | 電子信箱 |
| phone | VARCHAR(20) UNIQUE NULL | 手機號碼 |
| password | VARCHAR(255) NULL | 密碼（第三方登入可為空）|
| email_verified_at | TIMESTAMP NULL | Email 驗證時間 |
| phone_verified_at | TIMESTAMP NULL | 手機驗證時間 |
| status | TINYINT(1) | 狀態：1=正常, 0=停用, 2=待驗證 |
| last_login_at | TIMESTAMP NULL | 最後登入時間 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |
| deleted_at | TIMESTAMP NULL | 軟刪除 |

> - `email` 與 `phone` 至少需填一個
> - 使用 `uuid` 作為 API 對外識別，避免暴露自增 ID

---

### 2. `member_sns` — 第三方 OAuth 綁定

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| provider | VARCHAR(50) | 平台：google / line / facebook / apple |
| provider_user_id | VARCHAR(255) | 第三方平台的用戶 ID |
| access_token | TEXT NULL | Access Token |
| refresh_token | TEXT NULL | Refresh Token |
| token_expires_at | TIMESTAMP NULL | Token 到期時間 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

> - 複合唯一索引：`(provider, provider_user_id)`
> - 一個會員可綁定多個不同平台

---

### 3. `member_profiles` — 延伸個人資料

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK UNIQUE | 關聯 members.id（一對一）|
| avatar | VARCHAR(255) NULL | 頭像圖片路徑 |
| gender | TINYINT(1) NULL | 性別：1=男, 2=女, 0=不提供 |
| birthday | DATE NULL | 生日 |
| bio | TEXT NULL | 個人簡介 |
| language | VARCHAR(10) NULL | 偏好語言（如 zh-TW, en）|
| timezone | VARCHAR(50) NULL | 時區（如 Asia/Taipei）|
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

---

### 4. `member_addresses` — 會員地址

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| label | VARCHAR(50) NULL | 地址標籤（如：家、公司）|
| recipient_name | VARCHAR(100) | 收件人姓名 |
| recipient_phone | VARCHAR(20) | 收件人電話 |
| country | VARCHAR(10) | 國家代碼（如 TW）|
| zip_code | VARCHAR(10) NULL | 郵遞區號 |
| city | VARCHAR(50) | 縣市 |
| district | VARCHAR(50) NULL | 區域 |
| address | VARCHAR(255) | 詳細地址 |
| is_default | TINYINT(1) | 是否為預設地址 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

---

### 5. `member_verifications` — 驗證 OTP 記錄

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| type | VARCHAR(20) | 驗證類型：email / phone / password_reset |
| token | VARCHAR(10) | OTP 驗證碼 |
| expires_at | TIMESTAMP | 到期時間 |
| verified_at | TIMESTAMP NULL | 驗證成功時間 |
| created_at | TIMESTAMP | 建立時間 |

---

### 6. `member_login_histories` — 登入歷程

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| ip_address | VARCHAR(45) NULL | 登入 IP（支援 IPv6）|
| user_agent | VARCHAR(512) NULL | 瀏覽器 / 裝置資訊 |
| platform | VARCHAR(50) NULL | 平台：web / ios / android |
| login_method | VARCHAR(30) | 登入方式：email / phone / google / line …|
| status | TINYINT(1) | 結果：1=成功, 0=失敗 |
| created_at | TIMESTAMP | 登入時間 |

---

### 7. `member_devices` — 裝置推播 Token

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| platform | VARCHAR(20) | 平台：ios / android / web |
| device_token | VARCHAR(512) | 推播 Token |
| is_active | TINYINT(1) | 是否啟用 |
| last_used_at | TIMESTAMP NULL | 最後使用時間 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

> - 複合唯一索引：`(member_id, device_token)`

---

### 8. `member_groups` — 會員分群

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| name | VARCHAR(100) | 分群名稱（如：一般、銀牌、金牌、VIP）|
| description | TEXT NULL | 分群說明 |
| sort_order | INT UNSIGNED | 排序 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

---

### 9. `tags` — 標籤定義

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT UNSIGNED PK | 主鍵 |
| name | VARCHAR(100) UNIQUE | 標籤名稱（如：潛力客、流失風險）|
| color | VARCHAR(7) NULL | 標籤顏色（如：#FF5733）|
| description | TEXT NULL | 說明 |
| created_at | TIMESTAMP | 建立時間 |
| updated_at | TIMESTAMP | 更新時間 |

---

### 10. `member_tag` — 會員標籤 Pivot

| 欄位 | 型別 | 說明 |
|---|---|---|
| member_id | BIGINT UNSIGNED FK | 關聯 members.id |
| tag_id | BIGINT UNSIGNED FK | 關聯 tags.id |
| created_at | TIMESTAMP | 打標時間 |

> - 複合主鍵：`(member_id, tag_id)`

---

## FK 關聯總表

| 子表 | 欄位 | 參照 | 刪除策略 |
|---|---|---|---|
| member_sns | member_id | members.id | CASCADE |
| member_profiles | member_id | members.id | CASCADE |
| member_addresses | member_id | members.id | CASCADE |
| member_verifications | member_id | members.id | CASCADE |
| member_login_histories | member_id | members.id | CASCADE |
| member_devices | member_id | members.id | CASCADE |
| members | member_group_id | member_groups.id | SET NULL |
| member_tag | member_id | members.id | CASCADE |
| member_tag | tag_id | tags.id | CASCADE |

---

## 尚未規劃（未來擴充）

- 金流 / 訂單 / 交易
- 點數 / 儲值
- 優惠券 / 折扣
- 會員推薦（Referral）
