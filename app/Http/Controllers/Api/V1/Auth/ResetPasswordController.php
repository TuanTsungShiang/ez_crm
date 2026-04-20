<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Services\Auth\OtpService;

class ResetPasswordController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/password/reset",
     *     operationId="resetPassword",
     *     tags={"Auth"},
     *     summary="重設密碼（OTP 驗證後更新）",
     *     description="成功後撤銷所有既有 token，強制重新登入。",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","code","password","password_confirmation"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="code", type="string", example="123456"),
     *             @OA\Property(property="password", type="string", format="password"),
     *             @OA\Property(property="password_confirmation", type="string", format="password")
     *         )
     *     ),
     *     @OA\Response(response=200, description="密碼已重設"),
     *     @OA\Response(response=422, description="驗證碼錯誤或已過期")
     * )
     */
    public function __invoke(ResetPasswordRequest $request)
    {
        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_PASSWORD_RESET,
            $request->code,
        );

        if (! $verification) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $member->update(['password' => $request->password]);
        $member->tokens()->delete();

        return $this->success(null);
    }
}
