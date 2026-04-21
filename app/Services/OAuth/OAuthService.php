<?php

namespace App\Services\OAuth;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\MemberSns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class OAuthService
{
    const ALLOWED_PROVIDERS = ['google', 'github', 'line', 'discord'];

    /**
     * 處理 OAuth 登入 / 註冊。
     *
     * 三情境：
     *   1. 此 SNS 已綁定某會員   → 直接登入該會員、更新 token
     *   2. OAuth email 已存在    → 把新 SNS 掛到該會員（自動綁定）
     *   3. 全新使用者            → 建新 member + profile + 綁 SNS
     *
     * @return array{member: Member, is_new: bool, newly_bound: bool}
     */
    public function handleLogin(string $provider, SocialiteUser $socialUser): array
    {
        if (! $this->isProviderAllowed($provider)) {
            abort(422, "Provider {$provider} not supported");
        }

        return DB::transaction(function () use ($provider, $socialUser) {

            // 情境 1：此 SNS 已綁定
            $sns = MemberSns::where('provider', $provider)
                ->where('provider_user_id', $socialUser->getId())
                ->first();

            if ($sns) {
                $this->refreshSnsTokens($sns, $socialUser);
                return [
                    'member'      => $sns->member,
                    'is_new'      => false,
                    'newly_bound' => false,
                ];
            }

            // 情境 2：email 已存在 → 自動綁定
            if ($socialUser->getEmail()) {
                $existing = Member::where('email', $socialUser->getEmail())->first();
                if ($existing) {
                    $this->bindSns($existing, $provider, $socialUser);
                    return [
                        'member'      => $existing,
                        'is_new'      => false,
                        'newly_bound' => true,
                    ];
                }
            }

            // 情境 3：全新使用者
            $member = $this->createMemberFromOAuth($provider, $socialUser);
            $this->bindSns($member, $provider, $socialUser);

            return [
                'member'      => $member,
                'is_new'      => true,
                'newly_bound' => true,
            ];
        });
    }

    private function createMemberFromOAuth(string $provider, SocialiteUser $socialUser): Member
    {
        // OAuth provider 有時不回 email（LINE 老版 / Discord 未勾選），用 placeholder
        $email = $socialUser->getEmail()
            ?? "{$provider}_{$socialUser->getId()}@oauth.local";

        $member = Member::create([
            'uuid'              => (string) Str::uuid(),
            'name'              => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
            'nickname'          => $socialUser->getNickname(),
            'email'             => $email,
            // OAuth 提供的 email 視同已驗證；placeholder email 則未驗證
            'email_verified_at' => $socialUser->getEmail() ? now() : null,
            'password'          => Str::random(60), // 隨機佔位，要改需走忘記密碼
            'status'            => Member::STATUS_ACTIVE,
        ]);

        MemberProfile::create([
            'member_id' => $member->id,
            'avatar'    => $socialUser->getAvatar(),
            'language'  => 'zh-TW',
            'timezone'  => 'Asia/Taipei',
        ]);

        return $member;
    }

    private function bindSns(Member $member, string $provider, SocialiteUser $socialUser): MemberSns
    {
        return MemberSns::create([
            'member_id'        => $member->id,
            'provider'         => $provider,
            'provider_user_id' => $socialUser->getId(),
            'access_token'     => $socialUser->token ?? null,
            'refresh_token'    => $socialUser->refreshToken ?? null,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn)
                : null,
        ]);
    }

    private function refreshSnsTokens(MemberSns $sns, SocialiteUser $socialUser): void
    {
        $sns->update([
            'access_token'     => $socialUser->token ?? $sns->access_token,
            'refresh_token'    => $socialUser->refreshToken ?? $sns->refresh_token,
            'token_expires_at' => isset($socialUser->expiresIn)
                ? now()->addSeconds($socialUser->expiresIn)
                : $sns->token_expires_at,
        ]);
    }

    public function isProviderAllowed(string $provider): bool
    {
        return in_array($provider, self::ALLOWED_PROVIDERS, true);
    }
}
