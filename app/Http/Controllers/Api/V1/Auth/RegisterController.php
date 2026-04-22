<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiCode;
use App\Events\Webhooks\MemberCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\MemberVerification;
use App\Notifications\Member\SendOtpNotification;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    use ApiResponse;

    public function __construct(private OtpService $otpService) {}

    /**
     * @OA\Post(
     *     path="/auth/register",
     *     operationId="registerMember",
     *     tags={"Auth"},
     *     summary="會員註冊（送出後寄 OTP 驗證信）",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation","agree_terms"},
     *             @OA\Property(property="name", type="string", example="王小明"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string", format="password"),
     *             @OA\Property(property="password_confirmation", type="string", format="password"),
     *             @OA\Property(property="phone", type="string", nullable=true),
     *             @OA\Property(property="agree_terms", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="註冊成功，等待 OTP 驗證",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S201"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="member_uuid", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="otp_expires_in", type="integer", example=300)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function __invoke(RegisterRequest $request)
    {
        $member = DB::transaction(function () use ($request) {
            $member = Member::create([
                'uuid'     => (string) Str::uuid(),
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'password' => $request->password,
                'status'   => Member::STATUS_PENDING,
            ]);

            MemberProfile::create([
                'member_id' => $member->id,
                'language'  => 'zh-TW',
                'timezone'  => 'Asia/Taipei',
            ]);

            return $member;
        });

        $verification = $this->otpService->generate($member, MemberVerification::TYPE_EMAIL);
        $member->notify(new SendOtpNotification(
            $verification->token,
            MemberVerification::TYPE_EMAIL,
            OtpService::OTP_EXPIRE_MINS,
        ));

        // Webhook:通知下游服務有新會員註冊
        event(new MemberCreated($member));

        return $this->created([
            'member_uuid'    => $member->uuid,
            'email'          => $member->email,
            'otp_expires_in' => OtpService::OTP_EXPIRE_MINS * 60,
        ]);
    }
}
