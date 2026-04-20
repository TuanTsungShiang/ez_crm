<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateMeRequest;
use App\Http\Requests\Api\V1\Me\UpdatePasswordRequest;
use App\Http\Resources\Api\V1\MemberDetailResource;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MeController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/me",
     *     operationId="getMe",
     *     tags={"Me"},
     *     summary="取得目前登入會員資料",
     *     description="回傳自己的完整資料（含 profile / sns / tags / group）。需 member guard。",
     *     security={{"member":{}}},
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="uuid", type="string"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="nickname", type="string", nullable=true),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="phone", type="string", nullable=true),
     *                 @OA\Property(property="status", type="integer"),
     *                 @OA\Property(property="email_verified_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="group", type="object", nullable=true),
     *                 @OA\Property(property="profile", type="object", nullable=true),
     *                 @OA\Property(property="sns", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="tags", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function show(Request $request)
    {
        $member = $request->user()->load(['profile', 'sns', 'group', 'tags']);

        return $this->success(new MemberDetailResource($member));
    }

    /**
     * @OA\Put(
     *     path="/me",
     *     operationId="updateMe",
     *     tags={"Me"},
     *     summary="更新自己的基本資料（partial update）",
     *     description="只更新有傳入的欄位；傳 null 代表清空，不傳代表不動。",
     *     security={{"member":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=100),
     *             @OA\Property(property="nickname", type="string", nullable=true),
     *             @OA\Property(property="phone", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="更新成功"),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function update(UpdateMeRequest $request)
    {
        $member = $request->user();
        $member->update($request->validated());

        return $this->success(new MemberDetailResource($member->fresh()->load(['profile', 'sns', 'group', 'tags'])));
    }

    /**
     * @OA\Put(
     *     path="/me/password",
     *     operationId="updateMyPassword",
     *     tags={"Me"},
     *     summary="更新自己的密碼",
     *     description="需提供 current_password 驗證。成功後撤銷其他所有 token（僅保留當前使用中 token）。",
     *     security={{"member":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"current_password","password","password_confirmation"},
     *             @OA\Property(property="current_password", type="string", format="password"),
     *             @OA\Property(property="password", type="string", format="password"),
     *             @OA\Property(property="password_confirmation", type="string", format="password")
     *         )
     *     ),
     *     @OA\Response(response=200, description="密碼已更新"),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=422, description="目前密碼錯誤或新密碼不合規則")
     * )
     */
    public function updatePassword(UpdatePasswordRequest $request)
    {
        $member = $request->user();

        if (! Hash::check($request->current_password, $member->password)) {
            return $this->error(ApiCode::INVALID_CREDENTIALS, '目前密碼錯誤', 422);
        }

        $member->update(['password' => $request->password]);

        $currentTokenId = $request->user()->currentAccessToken()->id;
        $member->tokens()->where('id', '!=', $currentTokenId)->delete();

        return $this->success(null);
    }
}
