<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberLoginHistory;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Post(
     *     path="/auth/login",
     *     operationId="memberLogin",
     *     tags={"Auth"},
     *     summary="會員登入（email + password）",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string", format="password"),
     *             @OA\Property(property="device_name", type="string", nullable=true, example="iPhone 15"),
     *             @OA\Property(property="platform", type="string", nullable=true, example="web")
     *         )
     *     ),
     *     @OA\Response(response=200, description="登入成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="member", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="帳號停用 / Email 未驗證"),
     *     @OA\Response(response=422, description="帳號或密碼錯誤")
     * )
     */
    public function __invoke(LoginRequest $request)
    {
        $member = Member::where('email', $request->email)->first();

        if (! $member || ! Hash::check($request->password, $member->password ?? '')) {
            $this->recordLoginAttempt($member, $request, false);
            return $this->error(ApiCode::INVALID_CREDENTIALS, '帳號或密碼錯誤', 422);
        }

        if ($member->status === Member::STATUS_INACTIVE) {
            $this->recordLoginAttempt($member, $request, false);
            return $this->error(ApiCode::ACCOUNT_SUSPENDED, '帳號已停用', 403);
        }

        if (! $member->hasVerifiedEmail()) {
            $this->recordLoginAttempt($member, $request, false);
            return $this->error(
                ApiCode::EMAIL_NOT_VERIFIED,
                'Email 尚未驗證',
                403,
                ['email' => [$member->email]]
            );
        }

        $token = $member->createToken($request->device_name ?? 'member-web')->plainTextToken;
        $member->update(['last_login_at' => now()]);
        $this->recordLoginAttempt($member, $request, true);

        return $this->success([
            'token'  => $token,
            'member' => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
        ]);
    }

    private function recordLoginAttempt(?Member $member, LoginRequest $request, bool $success): void
    {
        if (! $member) {
            return;
        }

        MemberLoginHistory::create([
            'member_id'    => $member->id,
            'ip_address'   => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 512),
            'platform'     => $request->input('platform', 'web'),
            'login_method' => 'email',
            'status'       => $success,
        ]);
    }
}
