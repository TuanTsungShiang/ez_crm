<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiCode;
use App\Events\Webhooks\MemberDeleted;
use App\Events\Webhooks\MemberUpdated;
use App\Events\Webhooks\OAuthUnbound;
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

        // 先 fill、不 save,抓 diff
        $member->fill($request->validated());
        $changes = [];
        foreach ($member->getDirty() as $field => $newValue) {
            $changes[$field] = [
                'from' => $member->getOriginal($field),
                'to'   => $newValue,
            ];
        }
        $member->save();

        // 只有真的有欄位變動才派 webhook(避免「送一樣的值」也發事件)
        if (! empty($changes)) {
            event(new MemberUpdated($member->fresh(), $changes));
        }

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

    /**
     * @OA\Post(
     *     path="/me/logout",
     *     operationId="logout",
     *     tags={"Me"},
     *     summary="登出目前裝置",
     *     description="只撤銷當前 request 使用的 token，其他裝置不受影響。",
     *     security={{"member":{}}},
     *     @OA\Response(response=200, description="登出成功"),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null);
    }

    /**
     * @OA\Post(
     *     path="/me/logout-all",
     *     operationId="logoutAll",
     *     tags={"Me"},
     *     summary="登出所有裝置",
     *     description="撤銷此會員名下所有 token（含當前）。",
     *     security={{"member":{}}},
     *     @OA\Response(response=200, description="已登出所有裝置"),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return $this->success(null);
    }

    /**
     * @OA\Delete(
     *     path="/me",
     *     operationId="destroyMe",
     *     tags={"Me"},
     *     summary="註銷自己的帳號",
     *     description="會員自助刪除：軟刪除 + 撤銷所有 token。被註銷的會員 email/phone 仍佔用（避免立即被搶註），如需完全釋放由 admin 處理。",
     *     security={{"member":{}}},
     *     @OA\Response(response=200, description="帳號已註銷"),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function destroy(Request $request)
    {
        $member = $request->user();
        $member->tokens()->delete();
        $member->delete(); // soft delete,deleted_at 會寫到 $member 這個 instance 的記憶體中

        // Webhook:通知下游服務會員已註銷(snapshot 送 deleted_at)
        // 用 $member 直接送,不用 fresh()——SoftDeletes scope 會讓 fresh() 查不到。
        event(new MemberDeleted($member));

        return $this->success(null);
    }

    /**
     * @OA\Delete(
     *     path="/me/sns/{provider}",
     *     operationId="unbindOAuthProvider",
     *     tags={"Me"},
     *     summary="解除綁定第三方登入",
     *     description="擋下「最後一個登入方式」的解綁:若這是最後一個 SNS 且 email 未驗證過,禁止解綁(A012 LAST_LOGIN_METHOD)—— 避免使用者把自己鎖在外面。有驗證過的 email 可以透過忘記密碼救回,所以允許解綁。",
     *     security={{"member":{}}},
     *     @OA\Parameter(
     *         name="provider", in="path", required=true,
     *         description="google / github / line / discord",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="解綁成功"),
     *     @OA\Response(response=404, description="未綁定此 provider"),
     *     @OA\Response(response=409, description="A012 — 最後登入方式,不可解綁")
     * )
     */
    public function unbindSns(Request $request, string $provider)
    {
        $member = $request->user();
        $sns = $member->sns()->where('provider', $provider)->first();

        if (! $sns) {
            return $this->error(ApiCode::NOT_FOUND, "未綁定 {$provider}", 404);
        }

        // 擋「最後登入方式」:當此 SNS 是最後一個,且 email 未驗證(→ 無法用忘記密碼救回)
        $remainingSnsCount = $member->sns()->where('id', '!=', $sns->id)->count();
        if ($remainingSnsCount === 0 && ! $member->hasVerifiedEmail()) {
            return $this->error(
                ApiCode::LAST_LOGIN_METHOD,
                '這是你最後一個登入方式,解綁前請先驗證 email 或另外綁定一個登入方式',
                409,
                ['provider' => [$provider]],
            );
        }

        $providerUserId = $sns->provider_user_id;
        $sns->delete();

        // Webhook:通知下游服務 SNS 已解綁
        event(new OAuthUnbound($member, $provider, $providerUserId));

        return $this->success([
            'provider' => $provider,
        ]);
    }
}
