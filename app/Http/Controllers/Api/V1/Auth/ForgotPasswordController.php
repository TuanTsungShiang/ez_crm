<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;

class ForgotPasswordController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/password/forgot",
     *     operationId="forgotPassword",
     *     tags={"Auth"},
     *     summary="忘記密碼（寄送重設 OTP）",
     *     description="為避免洩漏 Email 是否註冊，永遠回 200。若 Email 存在且非冷卻中才實際發信。",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email"},
     *             @OA\Property(property="email", type="string", format="email")
     *         )
     *     ),
     *     @OA\Response(response=200, description="已寄出（或假裝已寄出）")
     * )
     */
    public function __invoke(ForgotPasswordRequest $request)
    {
        $member = Member::where('email', $request->email)->first();

        if ($member && ! $this->otpService->isThrottled($member, MemberVerification::TYPE_PASSWORD_RESET)) {
            $verification = $this->otpService->generate($member, MemberVerification::TYPE_PASSWORD_RESET);
            $member->notify(new SendOtpNotification(
                $verification->token,
                MemberVerification::TYPE_PASSWORD_RESET,
                OtpService::OTP_EXPIRE_MINS,
            ));
        }

        return $this->success([
            'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
        ]);
    }
}
