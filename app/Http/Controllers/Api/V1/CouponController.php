<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiCode;
use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateCouponBatchRequest;
use App\Http\Requests\Api\V1\RedeemCouponRequest;
use App\Http\Resources\Api\V1\CouponBatchResource;
use App\Http\Resources\Api\V1\CouponResource;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Services\Coupon\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    use ApiResponse;

    public function __construct(private CouponService $couponService) {}

    /**
     * @OA\Post(
     *     path="/coupons",
     *     operationId="createCouponBatch",
     *     tags={"Coupons"},
     *     summary="建立優惠券批次",
     *     description="建立一個批次並自動產生指定數量的優惠券代碼。需要 coupon.manage 權限。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true,
     *
     *         @OA\JsonContent(required={"name","type","value","quantity"},
     *
     *             @OA\Property(property="name",        type="string",  example="五一感謝券"),
     *             @OA\Property(property="type",        type="string",  example="discount_amount", description="discount_amount / discount_percent / points"),
     *             @OA\Property(property="value",       type="integer", example=100,  description="NT元 / 折扣% / 點數"),
     *             @OA\Property(property="quantity",    type="integer", example=500),
     *             @OA\Property(property="description", type="string",  nullable=true),
     *             @OA\Property(property="expires_at",  type="string",  format="date-time", nullable=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="建立成功"),
     *     @OA\Response(response=403, description="需要 coupon.manage 權限"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function store(CreateCouponBatchRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('coupon.manage'), 403);

        $batch = $this->couponService->createBatch(
            $request->validated(),
            $request->user(),
        );

        return $this->created(new CouponBatchResource($batch));
    }

    /**
     * @OA\Post(
     *     path="/coupons/{code}/verify",
     *     operationId="verifyCoupon",
     *     tags={"Coupons"},
     *     summary="驗證優惠券",
     *     description="確認代碼是否有效（不改狀態）。需要 coupon.view 權限。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string", example="EZCRM-A3F7-K9P2")),
     *
     *     @OA\Response(response=200, description="有效"),
     *     @OA\Response(response=422, description="C001 狀態不允許 / C002 已過期"),
     *     @OA\Response(response=404, description="代碼不存在")
     * )
     */
    public function verify(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can('coupon.view'), 403);

        try {
            $coupon = $this->couponService->verify($code);
        } catch (CouponExpiredException $e) {
            return $this->error(ApiCode::COUPON_EXPIRED, $e->getMessage(), 422);
        } catch (InvalidCouponStateException $e) {
            return $this->error(ApiCode::INVALID_COUPON_STATE, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        return $this->success(new CouponResource($coupon));
    }

    /**
     * @OA\Post(
     *     path="/coupons/{code}/redeem",
     *     operationId="redeemCoupon",
     *     tags={"Coupons"},
     *     summary="核銷優惠券",
     *     description="原子性核銷（lockForUpdate 防併發重複核銷）。需要 coupon.manage 權限。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"member_uuid"},
     *
     *         @OA\Property(property="member_uuid", type="string", format="uuid")
     *     )),
     *
     *     @OA\Response(response=200, description="核銷成功"),
     *     @OA\Response(response=422, description="C001 狀態不允許 / C002 已過期"),
     *     @OA\Response(response=404, description="代碼不存在")
     * )
     */
    public function redeem(RedeemCouponRequest $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can('coupon.manage'), 403);

        $member = Member::where('uuid', $request->validated('member_uuid'))->firstOrFail();

        try {
            $coupon = $this->couponService->redeem($code, $member, $request->user());
        } catch (CouponExpiredException $e) {
            return $this->error(ApiCode::COUPON_EXPIRED, $e->getMessage(), 422);
        } catch (InvalidCouponStateException $e) {
            return $this->error(ApiCode::INVALID_COUPON_STATE, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        $data = (new CouponResource($coupon))->toArray($request);

        if ($coupon->batch->type === 'points') {
            $data['points_awarded'] = $coupon->batch->value;
        }

        return $this->success($data);
    }

    /**
     * @OA\Post(
     *     path="/coupons/{code}/cancel",
     *     operationId="cancelCoupon",
     *     tags={"Coupons"},
     *     summary="取消核銷",
     *     description="將 redeemed 券退回 cancelled。points 類型自動退回點數。需要 coupon.manage 權限。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="code", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="取消成功"),
     *     @OA\Response(response=422, description="C001 狀態不允許"),
     *     @OA\Response(response=404, description="代碼不存在")
     * )
     */
    public function cancel(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can('coupon.manage'), 403);

        try {
            $coupon = $this->couponService->cancel($code, $request->user());
        } catch (InvalidCouponStateException $e) {
            return $this->error(ApiCode::INVALID_COUPON_STATE, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        $data = (new CouponResource($coupon))->toArray($request);

        if ($coupon->batch->type === 'points') {
            $data['points_refunded'] = $coupon->batch->value;
        }

        return $this->success($data);
    }
}
