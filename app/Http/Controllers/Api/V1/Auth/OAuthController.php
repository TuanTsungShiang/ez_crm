<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
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
     *     description="目前回傳 JSON；整合 SPA 時會改回 HTML + postMessage。",
     *     @OA\Parameter(name="provider", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="登入成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="member", type="object"),
     *                 @OA\Property(property="is_new_account", type="boolean"),
     *                 @OA\Property(property="newly_bound", type="boolean")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="OAuth 授權失敗")
     * )
     */
    public function callback(string $provider, Request $request)
    {
        if (! $this->oauthService->isProviderAllowed($provider)) {
            return $this->error(ApiCode::PROVIDER_NOT_SUPPORTED, "Provider {$provider} not supported", 422);
        }

        $state = $request->input('state');
        if (! $state || ! Cache::pull("oauth_state_{$state}")) {
            return $this->error(ApiCode::OAUTH_FAILED, 'OAuth 授權失敗：state 無效或已過期', 400);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            \Log::error("OAuth {$provider} callback failed", [
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]);
            return $this->error(ApiCode::OAUTH_FAILED, 'OAuth 授權失敗', 400);
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

        return $this->success([
            'token'          => $token,
            'member'         => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
            'is_new_account' => $result['is_new'],
            'newly_bound'    => $result['newly_bound'],
        ]);
    }
}
