<?php
/**
 * SCIM 2.0 最小測試 - 設定檔
 *
 * 這是一個本機測試用的 SCIM 2.0 Server
 * 用 SQLite 存資料，不需要額外設定 MySQL
 */

// OAuth 2.0 Client Credentials（模擬 SailPoint 的帳密）
define('SCIM_CLIENT_ID', 'sailpoint-test-client');
define('SCIM_CLIENT_SECRET', 'sailpoint-test-secret-2026');

// Token 有效時間（秒）
define('TOKEN_EXPIRY', 3600);

// SQLite 資料庫路徑
define('DB_PATH', __DIR__ . '/scim_test.sqlite');

// 初始化 SQLite 資料庫
function getDB(): PDO
{
    $isNew = !file_exists(DB_PATH);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($isNew) {
        initDB($db);
    }

    return $db;
}

function initDB(PDO $db): void
{
    // 使用者表
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY,
        external_id TEXT,
        user_name TEXT UNIQUE NOT NULL,
        family_name TEXT,
        given_name TEXT,
        email TEXT,
        active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now'))
    )");

    // 群組表
    $db->exec("CREATE TABLE IF NOT EXISTS groups_tbl (
        id TEXT PRIMARY KEY,
        display_name TEXT UNIQUE NOT NULL
    )");

    // 群組成員表
    $db->exec("CREATE TABLE IF NOT EXISTS group_members (
        group_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        PRIMARY KEY (group_id, user_id),
        FOREIGN KEY (group_id) REFERENCES groups_tbl(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // Token 表
    $db->exec("CREATE TABLE IF NOT EXISTS tokens (
        access_token TEXT PRIMARY KEY,
        expires_at TEXT NOT NULL
    )");

    // 預設群組（對應 4 種角色）
    $groups = [
        ['twpw_admin', 'TWPW Admin'],
        ['solventum_sales', 'Solventum Sales'],
        ['dsr', 'DSR'],
        ['hospital_engineer', 'Hospital Engineer'],
    ];

    $stmt = $db->prepare("INSERT OR IGNORE INTO groups_tbl (id, display_name) VALUES (?, ?)");
    foreach ($groups as $g) {
        $stmt->execute($g);
    }
}
