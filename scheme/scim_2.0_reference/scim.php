<?php
/**
 * SCIM 2.0 API 入口
 *
 * 處理所有 /scim/v2/ 開頭的請求
 * 透過 .htaccess 將請求導到這裡
 *
 * 支援的 endpoint：
 *   GET    /Users          → 列出所有使用者
 *   POST   /Users          → 建立使用者
 *   PATCH  /Users/{id}     → 更新使用者
 *   DELETE /Users/{id}     → 停用使用者
 *   GET    /Groups         → 列出所有群組
 *   PATCH  /Groups/{id}    → 修改群組成員
 *   GET    /ServiceProviderConfigs → SCIM 服務配置
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/scim+json');

// ========== 1. 驗證 Token ==========

function verifyToken(PDO $db): void
{
    // Apache 有時會吃掉 Authorization header，需要多種方式嘗試取得
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? apache_request_headers()['Authorization']
        ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid Authorization header']);
        exit;
    }

    $token = $matches[1];
    $stmt = $db->prepare("SELECT expires_at FROM tokens WHERE access_token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['expires_at'] < date('Y-m-d H:i:s')) {
        http_response_code(401);
        echo json_encode(['error' => 'Token expired or invalid']);
        exit;
    }
}

// ========== 2. 解析路由 ==========

// 取得 PATH_INFO（例如 /Users 或 /Users/abc123 或 /Groups）
$pathInfo = $_SERVER['PATH_INFO'] ?? $_GET['path'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 解析 resource 和 id
$parts = array_values(array_filter(explode('/', $pathInfo)));
$resource = $parts[0] ?? '';
$resourceId = $parts[1] ?? null;

// ========== 3. 取得 DB 並驗證 Token ==========

$db = getDB();

// ServiceProviderConfigs 不需要 token（讓 SailPoint 能先探測能力）
if ($resource !== 'ServiceProviderConfigs') {
    verifyToken($db);
}

// ========== 4. 讀取 request body ==========

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ========== 5. 路由分派 ==========

switch ($resource) {
    case 'Users':
        handleUsers($db, $method, $resourceId, $input);
        break;
    case 'Groups':
        handleGroups($db, $method, $resourceId, $input);
        break;
    case 'ServiceProviderConfigs':
        handleServiceProviderConfigs();
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => "Unknown resource: $resource"]);
}

// ========== Users Handler ==========

function handleUsers(PDO $db, string $method, ?string $id, array $input): void
{
    switch ($method) {
        case 'GET':
            if ($id) {
                getUser($db, $id);
            } else {
                listUsers($db);
            }
            break;
        case 'POST':
            createUser($db, $input);
            break;
        case 'PATCH':
            updateUser($db, $id, $input);
            break;
        case 'DELETE':
            deleteUser($db, $id);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

// --- GET /Users ---
function listUsers(PDO $db): void
{
    $stmt = $db->query("SELECT * FROM users WHERE active = 1");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resources = array_map(fn($u) => formatUser($db, $u), $users);

    echo json_encode([
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
        'totalResults' => count($resources),
        'Resources' => $resources
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// --- GET /Users/{id} ---
function getUser(PDO $db, string $id): void
{
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    echo json_encode(formatUser($db, $user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// --- POST /Users ---
function createUser(PDO $db, array $input): void
{
    $id = generateId();
    $userName = $input['userName'] ?? '';
    $externalId = $input['externalId'] ?? '';
    $familyName = $input['name']['familyName'] ?? '';
    $givenName = $input['name']['givenName'] ?? '';
    $email = '';
    if (!empty($input['emails'])) {
        $email = $input['emails'][0]['value'] ?? '';
    }

    if (empty($userName)) {
        http_response_code(400);
        echo json_encode(['error' => 'userName is required']);
        return;
    }

    // 檢查 userName 是否已存在
    $check = $db->prepare("SELECT id FROM users WHERE user_name = ?");
    $check->execute([$userName]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => "User with userName '$userName' already exists",
            'status' => '409'
        ]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO users (id, external_id, user_name, family_name, given_name, email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $externalId, $userName, $familyName, $givenName, $email]);

    // 處理 entitlements（群組）
    if (!empty($input['entitlements'])) {
        $gmStmt = $db->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)");
        foreach ($input['entitlements'] as $ent) {
            $groupId = $ent['value'] ?? '';
            if ($groupId) {
                $gmStmt->execute([$groupId, $id]);
            }
        }
    }

    http_response_code(201);
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(formatUser($db, $user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// --- PATCH /Users/{id} ---
function updateUser(PDO $db, ?string $id, array $input): void
{
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    // SCIM PATCH 使用 Operations 陣列
    $operations = $input['Operations'] ?? [];
    foreach ($operations as $op) {
        $operation = strtolower($op['op'] ?? '');
        $path = $op['path'] ?? '';
        $value = $op['value'] ?? null;

        if ($operation === 'replace') {
            switch ($path) {
                case 'userName':
                    $db->prepare("UPDATE users SET user_name = ?, updated_at = datetime('now') WHERE id = ?")->execute([$value, $id]);
                    break;
                case 'name.familyName':
                    $db->prepare("UPDATE users SET family_name = ?, updated_at = datetime('now') WHERE id = ?")->execute([$value, $id]);
                    break;
                case 'name.givenName':
                    $db->prepare("UPDATE users SET given_name = ?, updated_at = datetime('now') WHERE id = ?")->execute([$value, $id]);
                    break;
                case 'active':
                    $db->prepare("UPDATE users SET active = ?, updated_at = datetime('now') WHERE id = ?")->execute([$value ? 1 : 0, $id]);
                    break;
                case 'emails':
                    if (is_array($value) && !empty($value)) {
                        $email = $value[0]['value'] ?? '';
                        $db->prepare("UPDATE users SET email = ?, updated_at = datetime('now') WHERE id = ?")->execute([$email, $id]);
                    }
                    break;
            }
        }
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(formatUser($db, $user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// --- DELETE /Users/{id} ---
function deleteUser(PDO $db, ?string $id): void
{
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }

    // SCIM 的 DELETE 通常是停用而非真的刪除
    $stmt = $db->prepare("UPDATE users SET active = 0, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    http_response_code(204); // No Content
}

// ========== Groups Handler ==========

function handleGroups(PDO $db, string $method, ?string $id, array $input): void
{
    switch ($method) {
        case 'GET':
            listGroups($db);
            break;
        case 'PATCH':
            patchGroup($db, $id, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

// --- GET /Groups ---
function listGroups(PDO $db): void
{
    $stmt = $db->query("SELECT * FROM groups_tbl");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resources = [];
    foreach ($groups as $g) {
        // 取得群組成員
        $mStmt = $db->prepare("SELECT u.id, u.user_name FROM group_members gm JOIN users u ON gm.user_id = u.id WHERE gm.group_id = ?");
        $mStmt->execute([$g['id']]);
        $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        $resources[] = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
            'id' => $g['id'],
            'displayName' => $g['display_name'],
            'members' => array_map(fn($m) => [
                'value' => $m['id'],
                'display' => $m['user_name']
            ], $members)
        ];
    }

    echo json_encode([
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
        'totalResults' => count($resources),
        'Resources' => $resources
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// --- PATCH /Groups/{id} ---
function patchGroup(PDO $db, ?string $id, array $input): void
{
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Group ID is required']);
        return;
    }

    $operations = $input['Operations'] ?? [];

    foreach ($operations as $op) {
        $operation = strtolower($op['op'] ?? '');
        $path = $op['path'] ?? '';
        $values = $op['value'] ?? [];

        if ($path === 'members') {
            if ($operation === 'add') {
                $stmt = $db->prepare("INSERT OR IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)");
                foreach ($values as $v) {
                    $stmt->execute([$id, $v['value']]);
                }
            } elseif ($operation === 'remove') {
                $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
                foreach ($values as $v) {
                    $stmt->execute([$id, $v['value']]);
                }
            }
        }
    }

    http_response_code(200);
    // 回傳更新後的 group
    $stmt = $db->prepare("SELECT * FROM groups_tbl WHERE id = ?");
    $stmt->execute([$id]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);

    $mStmt = $db->prepare("SELECT u.id, u.user_name FROM group_members gm JOIN users u ON gm.user_id = u.id WHERE gm.group_id = ?");
    $mStmt->execute([$id]);
    $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
        'id' => $g['id'],
        'displayName' => $g['display_name'],
        'members' => array_map(fn($m) => [
            'value' => $m['id'],
            'display' => $m['user_name']
        ], $members)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// ========== ServiceProviderConfigs ==========

function handleServiceProviderConfigs(): void
{
    echo json_encode([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
        'patch' => ['supported' => true],
        'bulk' => ['supported' => false],
        'filter' => ['supported' => false],
        'changePassword' => ['supported' => false],
        'sort' => ['supported' => false],
        'etag' => ['supported' => false],
        'authenticationSchemes' => [
            [
                'type' => 'oauthbearertoken',
                'name' => 'OAuth Bearer Token',
                'description' => 'Authentication scheme using OAuth 2.0 Bearer Token'
            ]
        ]
    ], JSON_PRETTY_PRINT);
}

// ========== Helper ==========

function generateId(): string
{
    return sprintf('%s-%s-%s', substr(md5(uniqid()), 0, 8), substr(md5(random_bytes(4)), 0, 4), substr(md5(random_bytes(4)), 0, 8));
}

function formatUser(PDO $db, array $u): array
{
    // 取得此使用者所屬的群組
    $stmt = $db->prepare("SELECT g.id, g.display_name FROM group_members gm JOIN groups_tbl g ON gm.group_id = g.id WHERE gm.user_id = ?");
    $stmt->execute([$u['id']]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id' => $u['id'],
        'externalId' => $u['external_id'],
        'userName' => $u['user_name'],
        'name' => [
            'familyName' => $u['family_name'],
            'givenName' => $u['given_name'],
        ],
        'emails' => [
            [
                'value' => $u['email'],
                'type' => 'work',
                'primary' => true
            ]
        ],
        'active' => (bool)$u['active'],
        'groups' => array_map(fn($g) => [
            'value' => $g['id'],
            'display' => $g['display_name']
        ], $groups),
        'meta' => [
            'resourceType' => 'User',
            'created' => $u['created_at'],
            'lastModified' => $u['updated_at']
        ]
    ];
}
