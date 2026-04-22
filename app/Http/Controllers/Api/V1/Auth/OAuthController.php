<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Events\Webhooks\MemberLoggedIn;
use App\Events\Webhooks\OAuthBound;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\MemberLoginHistory;
use App\Services\OAuth\OAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    use ApiResponse;

    public function __construct(private OAuthService $oauthService) {}

    /**
     * @OA\Get(
     *     path="/auth/oauth/{provider}/redirect",
     *     operationId="oauthRedirect",
     *     tags={"Auth"},
     *     summary="取得 OAuth 授權 URL（SPA 呼叫後自行跳轉 / 開 popup）",
     *     @OA\Parameter(
     *         name="provider", in="path", required=true,
     *         description="google / github / line / discord",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="url", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Provider 不支援")
     * )
     */
    public function redirect(string $provider)
    {
        if (! $this->oauthService->isProviderAllowed($provider)) {
            return $this->error(ApiCode::PROVIDER_NOT_SUPPORTED, "Provider {$provider} not supported", 422);
        }

        $state = Str::random(40);
        Cache::put("oauth_state_{$state}", true, now()->addMinutes(10));

        $url = Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->success(['url' => $url]);
    }

    /**
     * @OA\Get(
     *     path="/auth/oauth/{provider}/callback",
     *     operationId="oauthCallback",
     *     tags={"Auth"},
     *     summary="OAuth provider callback（provider 在授權後導回這裡）",
     *     description="回傳 HTML + postMessage 給 popup 呼叫端（SPA 前端）。查詢時可加 ?format=json 得到原 JSON 行為。",
     *     @OA\Parameter(name="provider", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="format", in="query", required=false, description="json = 回 JSON（for tests/direct API）", @OA\Schema(type="string", enum={"json"})),
     *     @OA\Response(response=200, description="登入成功。format=json 時回 JSON;否則回 HTML（帶 postMessage 腳本）")
     * )
     */
    public function callback(string $provider, Request $request)
    {
        $wantsJson = $request->query('format') === 'json';

        if (! $this->oauthService->isProviderAllowed($provider)) {
            return $wantsJson
                ? $this->error(ApiCode::PROVIDER_NOT_SUPPORTED, "Provider {$provider} not supported", 422)
                : $this->renderCallbackHtml(['success' => false, 'code' => ApiCode::PROVIDER_NOT_SUPPORTED, 'message' => "Provider {$provider} not supported"]);
        }

        $state = $request->input('state');
        if (! $state || ! Cache::pull("oauth_state_{$state}")) {
            return $wantsJson
                ? $this->error(ApiCode::OAUTH_FAILED, 'OAuth 授權失敗：state 無效或已過期', 400)
                : $this->renderCallbackHtml(['success' => false, 'code' => ApiCode::OAUTH_FAILED, 'message' => 'OAuth 授權失敗：state 無效或已過期']);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            \Log::error("OAuth {$provider} callback failed", [
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $wantsJson
                ? $this->error(ApiCode::OAUTH_FAILED, 'OAuth 授權失敗', 400)
                : $this->renderCallbackHtml(['success' => false, 'code' => ApiCode::OAUTH_FAILED, 'message' => 'OAuth 授權失敗']);
        }

        $result = $this->oauthService->handleLogin($provider, $socialUser);
        $member = $result['member'];

        MemberLoginHistory::create([
            'member_id'    => $member->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 512),
            'platform'     => $request->input('platform', 'web'),
            'login_method' => $provider,
            'status'       => true,
        ]);

        $member->update(['last_login_at' => now()]);
        $token = $member->createToken("oauth-{$provider}")->plainTextToken;

        // Webhooks
        if ($result['newly_bound']) {
            // 剛綁定的 SNS(情境 2 或 3)
            $sns = $member->sns()->where('provider', $provider)->latest('id')->first();
            if ($sns) {
                event(new OAuthBound($member, $sns, $result['is_new']));
            }
        }
        event(new MemberLoggedIn($member, $provider));

        $data = [
            'token'          => $token,
            'member'         => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
            'is_new_account' => $result['is_new'],
            'newly_bound'    => $result['newly_bound'],
        ];

        return $wantsJson
            ? $this->success($data)
            : $this->renderCallbackHtml(['success' => true, 'code' => ApiCode::OK, 'data' => $data]);
    }

    /**
     * 回傳一個最小的 HTML 頁面,透過 postMessage 把結果送到開這個 popup 的父視窗,然後自動關閉。
     */
    private function renderCallbackHtml(array $message): \Illuminate\Http\Response
    {
        $targetOrigin = config('services.frontend.url', 'http://localhost:5173');
        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $status = ($message['success'] ?? false) ? 200 : 400;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>OAuth Callback</title>
</head>
<body style="font-family: system-ui, sans-serif; padding: 2rem; color: #334155;">
    <p id="msg">正在完成登入…</p>
    <script>
        (function () {
            var payload = {$messageJson};
            payload.type = 'ez_crm_oauth_result';
            if (window.opener) {
                window.opener.postMessage(payload, '{$targetOrigin}');
                document.getElementById('msg').textContent = payload.success ? '登入成功,視窗即將關閉…' : '登入失敗:' + (payload.message || '未知錯誤');
                setTimeout(function () { window.close(); }, payload.success ? 500 : 3000);
            } else {
                // 非 popup 情境(直接貼 callback URL 打開):導回前端首頁
                document.getElementById('msg').textContent = '此頁需從 popup 流程進入';
            }
        })();
    </script>
</body>
</html>
HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
