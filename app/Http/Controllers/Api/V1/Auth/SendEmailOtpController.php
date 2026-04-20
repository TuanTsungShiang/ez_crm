<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SendEmailOtpRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;

class SendEmailOtpController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/verify/email/send",
     *     operationId="sendEmailOtp",
     *     tags={"Auth"},
     *     summary="重發 Email 驗證碼",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email"},
     *             @OA\Property(property="email", type="string", format="email")
     *         )
     *     ),
     *     @OA\Response(response=200, description="已寄出（或假裝已寄出，不透露 Email 是否存在）"),
     *     @OA\Response(response=409, description="Email 已驗證過"),
     *     @OA\Response(response=429, description="發送過於頻繁")
     * )
     */
    public function __invoke(SendEmailOtpRequest $request)
    {
        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            // 安全考量：不透露 email 是否存在，但仍回成功
            return $this->success([
                'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
            ]);
        }

        if ($member->hasVerifiedEmail()) {
            return $this->error(ApiCode::ALREADY_VERIFIED, '此 Email 已驗證過', 409);
        }

        if ($this->otpService->isThrottled($member, MemberVerification::TYPE_EMAIL)) {
            return $this->error(ApiCode::THROTTLED, '請稍候再試', 429);
        }

        $verification = $this->otpService->generate($member, MemberVerification::TYPE_EMAIL);
        $member->notify(new SendOtpNotification(
            $verification->token,
            MemberVerification::TYPE_EMAIL,
            OtpService::OTP_EXPIRE_MINS,
        ));

        return $this->success([
            'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
        ]);
    }
}
