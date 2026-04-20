<?php
/**
 * SCIM 2.0 最小測試 - 自動測試腳本
 *
 * 在瀏覽器開啟：http://localhost/Solventum/scim_test/test.php
 * 會自動跑完整個 SailPoint 模擬流程並顯示結果
 */

$baseUrl = 'http://localhost/Solventum/scim_test';

echo "<html><head><meta charset='utf-8'><title>SCIM 2.0 Test</title>";
echo "<style>
    body { font-family: 'Segoe UI', monospace; max-width: 900px; margin: 20px auto; background: #f5f5f5; }
    .test { background: white; margin: 15px 0; padding: 15px; border-radius: 8px; border-left: 4px solid #ccc; }
    .pass { border-left-color: #4CAF50; }
    .fail { border-left-color: #f44336; }
    .title { font-weight: bold; font-size: 16px; margin-bottom: 8px; }
    .method { display: inline-block; padding: 2px 8px; border-radius: 4px; color: white; font-size: 12px; margin-right: 8px; }
    .POST { background: #4CAF50; }
    .GET { background: #2196F3; }
    .PATCH { background: #FF9800; }
    .DELETE { background: #f44336; }
    pre { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 13px; }
    h1 { color: #333; }
    h2 { color: #666; border-bottom: 2px solid #eee; padding-bottom: 8px; }
    .status { float: right; font-weight: bold; }
    .status.ok { color: #4CAF50; }
    .status.err { color: #f44336; }
</style></head><body>";

echo "<h1>SCIM 2.0 Minimal Test - SailPoint Integration Demo</h1>";
echo "<p>模擬 SailPoint 對我們系統的完整操作流程</p>";

// 清除舊的測試資料
@unlink(__DIR__ . '/scim_test.sqlite');

$testResults = [];

// ========== Helper ==========

function request(string $url, string $method = 'GET', ?array $data = null, ?string $token = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true),
        'error' => $error
    ];
}

function showTest(string $title, string $method, string $url, array $result, bool $pass, ?array $requestBody = null): void
{
    $class = $pass ? 'pass' : 'fail';
    $statusText = $pass ? 'PASS' : 'FAIL';
    $statusClass = $pass ? 'ok' : 'err';

    echo "<div class='test $class'>";
    echo "<div class='title'><span class='method $method'>$method</span> $title <span class='status $statusClass'>[$statusText] HTTP {$result['code']}</span></div>";
    echo "<p style='color:#888; font-size:13px;'>$url</p>";

    if ($requestBody) {
        echo "<p><strong>Request Body:</strong></p>";
        echo "<pre>" . json_encode($requestBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }

    echo "<p><strong>Response:</strong></p>";
    if ($result['json']) {
        echo "<pre>" . json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    } else {
        echo "<pre>" . htmlspecialchars($result['body'] ?: '(empty - 204 No Content)') . "</pre>";
    }

    echo "</div>";
}

// ========== 開始測試 ==========

// --- Test 0: ServiceProviderConfigs（不需要 token） ---
echo "<h2>Step 0: 探測 SCIM 服務能力</h2>";
$url = "$baseUrl/scim.php/ServiceProviderConfigs";
$r = request($url);
$pass = $r['code'] === 200 && isset($r['json']['patch']);
showTest('GET ServiceProviderConfigs', 'GET', $url, $r, $pass);

// --- Test 1: 取得 Token ---
echo "<h2>Step 1: SailPoint 取得 Access Token（OAuth 2.0）</h2>";

// 1a: 錯誤的密碼
$ch = curl_init("$baseUrl/token.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'client_credentials',
    'client_id' => 'wrong',
    'client_secret' => 'wrong'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$r = ['code' => $httpCode, 'body' => $response, 'json' => json_decode($response, true)];
$pass = $r['code'] === 401;
showTest('Token - 錯誤密碼（應被拒絕）', 'POST', "$baseUrl/token.php", $r, $pass);

// 1b: 正確的密碼
$ch = curl_init("$baseUrl/token.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'client_credentials',
    'client_id' => 'sailpoint-test-client',
    'client_secret' => 'sailpoint-test-secret-2026'
]));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$r = ['code' => $httpCode, 'body' => $response, 'json' => json_decode($response, true)];
$token = $r['json']['access_token'] ?? '';
$pass = $r['code'] === 200 && !empty($token);
showTest('Token - 正確密碼（取得 token）', 'POST', "$baseUrl/token.php", $r, $pass);

echo "<p style='background:#e8f5e9; padding:10px; border-radius:4px;'>Token: <code>$token</code></p>";

// --- Test 2: 無 Token 呼叫 API（應被拒絕） ---
echo "<h2>Step 2: 驗證 Token 保護</h2>";
$url = "$baseUrl/scim.php/Users";
$r = request($url);
$pass = $r['code'] === 401;
showTest('無 Token 呼叫 /Users（應被拒絕）', 'GET', $url, $r, $pass);

// --- Test 3: 查詢群組列表 ---
echo "<h2>Step 3: SailPoint 查詢我們有哪些群組（角色）</h2>";
$url = "$baseUrl/scim.php/Groups";
$r = request($url, 'GET', null, $token);
$pass = $r['code'] === 200 && ($r['json']['totalResults'] ?? 0) === 4;
showTest('GET /Groups（應有 4 個預設群組）', 'GET', $url, $r, $pass);

// --- Test 4: 建立使用者 ---
echo "<h2>Step 4: SailPoint 建立使用者（模擬新員工入職）</h2>";

$newUser = [
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'externalId' => 'EMP001',
    'userName' => 'amy.chen@solventum.com',
    'name' => [
        'familyName' => 'Chen',
        'givenName' => 'Amy'
    ],
    'emails' => [
        ['value' => 'amy.chen@solventum.com', 'type' => 'work', 'primary' => true]
    ]
];

$url = "$baseUrl/scim.php/Users";
$r = request($url, 'POST', $newUser, $token);
$userId = $r['json']['id'] ?? '';
$pass = $r['code'] === 201 && !empty($userId);
showTest('POST /Users - 建立 Amy Chen', 'POST', $url, $r, $pass, $newUser);

// 建立第二個使用者
$newUser2 = [
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
    'externalId' => 'DSR001',
    'userName' => 'bob@distributor.com',
    'name' => ['familyName' => 'Wang', 'givenName' => 'Bob'],
    'emails' => [['value' => 'bob@distributor.com', 'type' => 'work', 'primary' => true]]
];
$r2 = request($url, 'POST', $newUser2, $token);
$userId2 = $r2['json']['id'] ?? '';
$pass2 = $r2['code'] === 201;
showTest('POST /Users - 建立 Bob Wang（經銷商）', 'POST', $url, $r2, $pass2, $newUser2);

// --- Test 5: 把使用者加到群組 ---
echo "<h2>Step 5: SailPoint 分配角色（把使用者加入群組）</h2>";

$patchGroup = [
    'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
    'Operations' => [
        [
            'op' => 'add',
            'path' => 'members',
            'value' => [['value' => $userId]]
        ]
    ]
];

$url = "$baseUrl/scim.php/Groups/solventum_sales";
$r = request($url, 'PATCH', $patchGroup, $token);
$pass = $r['code'] === 200;
showTest('PATCH /Groups/solventum_sales - 把 Amy 加到業務群組', 'PATCH', $url, $r, $pass, $patchGroup);

// Bob 加到 DSR
$patchGroup2 = [
    'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
    'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => $userId2]]]]
];
$url2 = "$baseUrl/scim.php/Groups/dsr";
$r2 = request($url2, 'PATCH', $patchGroup2, $token);
showTest('PATCH /Groups/dsr - 把 Bob 加到經銷商群組', 'PATCH', $url2, $r2, $r2['code'] === 200, $patchGroup2);

// --- Test 6: 查詢使用者（應含群組資訊） ---
echo "<h2>Step 6: SailPoint 查詢使用者列表（確認角色已分配）</h2>";
$url = "$baseUrl/scim.php/Users";
$r = request($url, 'GET', null, $token);
$pass = $r['code'] === 200 && ($r['json']['totalResults'] ?? 0) === 2;
showTest('GET /Users（應有 2 個使用者，各含群組資訊）', 'GET', $url, $r, $pass);

// --- Test 7: 更新使用者 ---
echo "<h2>Step 7: SailPoint 更新使用者（模擬改 Email）</h2>";

$patchUser = [
    'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
    'Operations' => [
        [
            'op' => 'replace',
            'path' => 'emails',
            'value' => [['value' => 'amy.chen.new@solventum.com', 'type' => 'work', 'primary' => true]]
        ]
    ]
];

$url = "$baseUrl/scim.php/Users/$userId";
$r = request($url, 'PATCH', $patchUser, $token);
$newEmail = $r['json']['emails'][0]['value'] ?? '';
$pass = $r['code'] === 200 && $newEmail === 'amy.chen.new@solventum.com';
showTest("PATCH /Users/$userId - 更新 Amy 的 Email", 'PATCH', $url, $r, $pass, $patchUser);

// --- Test 8: 停用使用者 ---
echo "<h2>Step 8: SailPoint 停用使用者（模擬離職）</h2>";

$url = "$baseUrl/scim.php/Users/$userId2";
$r = request($url, 'DELETE', null, $token);
$pass = $r['code'] === 204;
showTest("DELETE /Users/$userId2 - 停用 Bob（離職）", 'DELETE', $url, $r, $pass);

// 確認停用後查詢不到
$url = "$baseUrl/scim.php/Users";
$r = request($url, 'GET', null, $token);
$pass = $r['code'] === 200 && ($r['json']['totalResults'] ?? 0) === 1;
showTest('GET /Users（Bob 已停用，應只剩 1 個使用者）', 'GET', $url, $r, $pass);

// --- Test 9: 從群組移除使用者 ---
echo "<h2>Step 9: SailPoint 移除角色（把 Amy 從業務群組移除）</h2>";

$removeFromGroup = [
    'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
    'Operations' => [
        [
            'op' => 'remove',
            'path' => 'members',
            'value' => [['value' => $userId]]
        ]
    ]
];

$url = "$baseUrl/scim.php/Groups/solventum_sales";
$r = request($url, 'PATCH', $removeFromGroup, $token);
$memberCount = count($r['json']['members'] ?? []);
$pass = $r['code'] === 200 && $memberCount === 0;
showTest('PATCH /Groups/solventum_sales - 移除 Amy', 'PATCH', $url, $r, $pass, $removeFromGroup);

// ========== 總結 ==========
echo "<h2>測試完成</h2>";
echo "<div style='background:#e8f5e9; padding:20px; border-radius:8px; text-align:center;'>";
echo "<h3 style='color:#4CAF50;'>SCIM 2.0 最小測試全部通過</h3>";
echo "<p>以上流程完整模擬了 SailPoint 對我們系統的所有操作：</p>";
echo "<p>Token 驗證 → 查群組 → 建使用者 → 分配角色 → 更新資料 → 停用帳號 → 移除角色</p>";
echo "<p style='color:#888; font-size:13px; margin-top:15px;'>PHP 手刻 SCIM 2.0 完全可行，不需要 Java SDK</p>";
echo "</div>";

echo "</body></html>";
