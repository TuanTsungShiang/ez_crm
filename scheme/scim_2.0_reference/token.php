<?php
/**
 * OAuth 2.0 Token Endpoint
 * POST /scim_test/token.php
 *
 * SailPoint 用 client_id + client_secret 來換取 access_token
 *
 * 流程（白話）：
 * SailPoint：「我是 sailpoint-test-client，密碼是 xxx，給我通行證」
 * 我們：「驗證通過，這是你的通行證（token），1 小時內有效」
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// 只接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// 取得 client credentials
$clientId = $_POST['client_id'] ?? '';
$clientSecret = $_POST['client_secret'] ?? '';
$grantType = $_POST['grant_type'] ?? '';

// 驗證 grant_type
if ($grantType !== 'client_credentials') {
    http_response_code(400);
    echo json_encode([
        'error' => 'unsupported_grant_type',
        'error_description' => 'Only client_credentials is supported'
    ]);
    exit;
}

// 驗證帳密
if ($clientId !== SCIM_CLIENT_ID || $clientSecret !== SCIM_CLIENT_SECRET) {
    http_response_code(401);
    echo json_encode([
        'error' => 'invalid_client',
        'error_description' => 'Client authentication failed'
    ]);
    exit;
}

// 產生 token
$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);

// 存入 DB
$db = getDB();
$stmt = $db->prepare("INSERT INTO tokens (access_token, expires_at) VALUES (?, ?)");
$stmt->execute([$token, $expiresAt]);

// 回傳（標準 OAuth 2.0 格式）
echo json_encode([
    'access_token' => $token,
    'token_type' => 'Bearer',
    'expires_in' => TOKEN_EXPIRY
]);
