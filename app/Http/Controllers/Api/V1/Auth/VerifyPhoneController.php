<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyPhoneRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Services\Auth\OtpService;

class VerifyPhoneController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/verify/phone",
     *     operationId="verifyPhone",
     *     tags={"Auth"},
     *     summary="驗證手機 OTP(僅 mark phone_verified_at,不發 token)",
     *     description="Phone 驗證不等於登入 — 只把 phone_verified_at 打開。想用 phone 登入等 Phase 8.x 再擴。",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"phone","code"},
     *             @OA\Property(property="phone", type="string", example="0912345678"),
     *             @OA\Property(property="code", type="string", example="123456", minLength=6, maxLength=6)
     *         )
     *     ),
     *     @OA\Response(response=200, description="驗證成功"),
     *     @OA\Response(response=422, description="驗證碼錯誤或過期")
     * )
     */
    public function __invoke(VerifyPhoneRequest $request)
    {
        $phone = preg_replace('/[\s\-]/', '', $request->phone);
        $member = Member::where('phone', $phone)->first();

        if (! $member) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_PHONE,
            $request->code,
        );

        if (! $verification) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $member->update(['phone_verified_at' => now()]);

        return $this->success([
            'phone'              => $member->phone,
            'phone_verified_at'  => $member->phone_verified_at?->toIso8601String(),
        ]);
    }
}
