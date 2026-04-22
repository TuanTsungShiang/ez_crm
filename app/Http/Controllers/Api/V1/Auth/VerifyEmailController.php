<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Events\Webhooks\MemberLoggedIn;
use App\Events\Webhooks\MemberVerifiedEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberVerification;
use App\Services\Auth\OtpService;

class VerifyEmailController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/verify/email",
     *     operationId="verifyEmail",
     *     tags={"Auth"},
     *     summary="驗證 Email OTP（成功即發 token 自動登入）",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","code"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="code", type="string", example="123456", minLength=6, maxLength=6)
     *         )
     *     ),
     *     @OA\Response(response=200, description="驗證成功，回傳 Sanctum token",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="member", type="object",
     *                     @OA\Property(property="uuid", type="string"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="email", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="驗證碼錯誤或過期")
     * )
     */
    public function __invoke(VerifyEmailRequest $request)
    {
        $member = Member::where('email', $request->email)->first();

        if (! $member) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $verification = $this->otpService->verify(
            $member,
            MemberVerification::TYPE_EMAIL,
            $request->code,
        );

        if (! $verification) {
            return $this->error(ApiCode::INVALID_CODE, '驗證碼錯誤或已過期', 422);
        }

        $member->markEmailAsVerified();
        $member->update(['status' => Member::STATUS_ACTIVE]);

        // Webhooks:先 email verified,再 logged_in
        event(new MemberVerifiedEmail($member->fresh()));
        event(new MemberLoggedIn($member, 'email'));

        $token = $member->createToken('member-web')->plainTextToken;

        return $this->success([
            'token'  => $token,
            'member' => [
                'uuid'  => $member->uuid,
                'name'  => $member->name,
                'email' => $member->email,
            ],
        ]);
    }
}
