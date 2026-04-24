<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SendPhoneOtpRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Models\NotificationDelivery;
use App\Services\Auth\OtpService;
use App\Services\Sms\SmsManager;
use App\Services\Sms\SmsMessage;

class SendPhoneOtpController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OtpService $otpService,
        private readonly SmsManager $sms,
    ) {}

    /**
     * @OA\Post(
     *     path="/auth/verify/phone/send",
     *     operationId="sendPhoneOtp",
     *     tags={"Auth"},
     *     summary="寄 OTP 到手機",
     *     description="跟 /auth/verify/email/send 對稱;dev 用 LogDriver 不會真的發。",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"phone"},
     *             @OA\Property(property="phone", type="string", example="0912345678")
     *         )
     *     ),
     *     @OA\Response(response=200, description="已寄出(或假裝已寄出,不透露 phone 是否存在)"),
     *     @OA\Response(response=409, description="該手機已驗證過"),
     *     @OA\Response(response=429, description="發送過於頻繁")
     * )
     */
    public function __invoke(SendPhoneOtpRequest $request)
    {
        $phone = $this->normalizePhone($request->phone);
        $member = Member::where('phone', $phone)->first();

        if (! $member) {
            // 安全考量:不透露 phone 是否存在
            return $this->success([
                'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
            ]);
        }

        if (! is_null($member->phone_verified_at)) {
            return $this->error(ApiCode::ALREADY_VERIFIED, '此手機已驗證過', 409);
        }

        if ($this->otpService->isThrottled($member, MemberVerification::TYPE_PHONE)) {
            return $this->error(ApiCode::THROTTLED, '請稍候再試', 429);
        }

        $verification = $this->otpService->generate($member, MemberVerification::TYPE_PHONE);

        $content = strtr(config('sms.otp.template'), [
            '{code}'    => $verification->token,
            '{minutes}' => (string) OtpService::OTP_EXPIRE_MINS,
        ]);

        $this->sms->send(new SmsMessage(
            to: $phone,
            content: $content,
            purpose: NotificationDelivery::PURPOSE_OTP_VERIFY,
            memberId: $member->id,
        ));

        return $this->success([
            'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
        ]);
    }

    /**
     * 非嚴謹 normalize — 只去空白跟 dash。真正的 E.164 放到 Phase 8.5 的 MitakeDriver 做。
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s\-]/', '', $phone);
    }
}
