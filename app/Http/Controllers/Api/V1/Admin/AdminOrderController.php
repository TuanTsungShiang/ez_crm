<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiCode;
use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Exceptions\Order\RefundAmountExceedsPaidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminRefundRequest;
use App\Http\Requests\Api\V1\Admin\CreateAdminOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Payments\ECPay\ECPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-facing order management endpoints.
 *
 * Auth:  auth:sanctum (User guard)
 * RBAC:  per-action permission checks using Spatie can()
 */
class AdminOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderService $orderService,
        private ECPayService $ecpayService,
    ) {}

    /**
     * @OA\Get(
     *     path="/admin/orders",
     *     operationId="adminListOrders",
     *     tags={"Admin - Orders"},
     *     summary="訂單列表（支援多欄 filter）",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="status",     in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="member_uuid",in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="date_from",  in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to",    in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="page",       in="query", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(response=200, description="分頁訂單列表"),
     *     @OA\Response(response=403, description="需要 order.view_any 權限")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('order.view_any'), 403);

        $query = Order::with(['member'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->member_uuid, function ($q, $uuid) {
                $q->whereHas('member', fn ($m) => $m->where('uuid', $uuid));
            })
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest();

        $orders = $query->paginate(20);

        return $this->success([
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/admin/orders/{id}",
     *     operationId="adminShowOrder",
     *     tags={"Admin - Orders"},
     *     summary="訂單詳情",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="訂單詳情"),
     *     @OA\Response(response=403, description="需要 order.view 權限"),
     *     @OA\Response(response=404, description="不存在")
     * )
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->can('order.view'), 403);

        $order->load(['items', 'shippingAddress', 'coupons', 'member', 'statusHistories', 'refunds']);

        return $this->success(new OrderResource($order));
    }

    /**
     * @OA\Post(
     *     path="/admin/orders",
     *     operationId="adminCreateOrder",
     *     tags={"Admin - Orders"},
     *     summary="代下單",
     *     description="為指定會員代下單。mark_as_paid_offline=true 直接標記付款，略過 ECPay 流程。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="Idempotency-Key", in="header", required=true,
     *
     *         @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"member_uuid","items","shipping_address"},
     *
     *         @OA\Property(property="member_uuid",          type="string", format="uuid"),
     *         @OA\Property(property="mark_as_paid_offline", type="boolean", example=false),
     *         @OA\Property(property="items", type="array", @OA\Items(type="object"))
     *     )),
     *
     *     @OA\Response(response=201, description="訂單建立成功"),
     *     @OA\Response(response=403, description="需要 order.create 權限")
     * )
     */
    public function store(CreateAdminOrderRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('order.create'), 403);

        $idempotencyKey = $request->idempotencyKey();
        if ($idempotencyKey === '') {
            return $this->error(ApiCode::MISSING_FIELD, 'Idempotency-Key header is required', 422);
        }

        $member = Member::where('uuid', $request->validated('member_uuid'))->firstOrFail();

        $data = $request->except(['member_uuid', 'mark_as_paid_offline']);

        try {
            $order = $this->orderService->create($member, $data, $idempotencyKey);
        } catch (CouponExpiredException $e) {
            return $this->error(ApiCode::COUPON_EXPIRED, $e->getMessage(), 422);
        } catch (InvalidCouponStateException $e) {
            return $this->error(ApiCode::INVALID_COUPON_STATE, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        // Admin shortcut: mark as paid offline immediately
        if ($request->boolean('mark_as_paid_offline')) {
            $order = $this->orderService->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        }

        $order->load(['items', 'shippingAddress', 'coupons', 'member']);
        $responseData = ['order' => new OrderResource($order)];

        // Only attach ECPay form if still pending (not already marked paid)
        if ($order->status === Order::STATUS_PENDING) {
            $responseData['ecpay_payment_html'] = $this->ecpayService->createPaymentForm($order);
        }

        return $this->created($responseData);
    }

    /**
     * @OA\Post(
     *     path="/admin/orders/{id}/ship",
     *     operationId="adminShipOrder",
     *     tags={"Admin - Orders"},
     *     summary="出貨（paid → shipped）",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="已出貨"),
     *     @OA\Response(response=422, description="D001 狀態不允許"),
     *     @OA\Response(response=403, description="需要 order.update 權限")
     * )
     */
    public function ship(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->can('order.update'), 403);

        try {
            $shipped = $this->orderService->ship($order, $request->user());
        } catch (InvalidOrderStateTransitionException $e) {
            return $this->error(ApiCode::INVALID_ORDER_STATE_TRANSITION, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        return $this->success(new OrderResource($shipped));
    }

    /**
     * @OA\Post(
     *     path="/admin/orders/{id}/complete",
     *     operationId="adminCompleteOrder",
     *     tags={"Admin - Orders"},
     *     summary="完成訂單（shipped/paid → completed，觸發加點）",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="已完成，points_earned 已寫入"),
     *     @OA\Response(response=422, description="D001 狀態不允許"),
     *     @OA\Response(response=403, description="需要 order.update 權限")
     * )
     */
    public function complete(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->can('order.update'), 403);

        try {
            $completed = $this->orderService->complete($order, $request->user());
        } catch (InvalidOrderStateTransitionException $e) {
            return $this->error(ApiCode::INVALID_ORDER_STATE_TRANSITION, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        return $this->success(new OrderResource($completed));
    }

    /**
     * @OA\Post(
     *     path="/admin/orders/{id}/cancel",
     *     operationId="adminCancelOrder",
     *     tags={"Admin - Orders"},
     *     summary="取消訂單（T2/T4/T7）",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *
     *         @OA\Property(property="reason", type="string", example="客服協助取消")
     *     )),
     *
     *     @OA\Response(response=200, description="已取消"),
     *     @OA\Response(response=422, description="D001 狀態不允許"),
     *     @OA\Response(response=403, description="需要 order.cancel 權限")
     * )
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->can('order.cancel'), 403);

        $reason = $request->string('reason', '')->toString();

        try {
            $cancelled = $this->orderService->cancel($order, $request->user(), $reason);
        } catch (InvalidOrderStateTransitionException $e) {
            return $this->error(ApiCode::INVALID_ORDER_STATE_TRANSITION, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        return $this->success(new OrderResource($cancelled));
    }

    /**
     * @OA\Post(
     *     path="/admin/orders/{id}/refund",
     *     operationId="adminRefundOrder",
     *     tags={"Admin - Orders"},
     *     summary="退款（全額或部分）",
     *     description="反向 Points 比例退。需要 order.refund 權限（限 admin+）。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"amount","reason"},
     *
     *         @OA\Property(property="amount", type="integer", example=500),
     *         @OA\Property(property="reason", type="string",  example="客服補償")
     *     )),
     *
     *     @OA\Response(response=200, description="退款完成"),
     *     @OA\Response(response=422, description="D001 狀態不允許 / D004 超額"),
     *     @OA\Response(response=403, description="需要 order.refund 權限")
     * )
     */
    public function refund(AdminRefundRequest $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->can('order.refund'), 403);

        try {
            $refund = $this->orderService->refund(
                $order,
                $request->integer('amount'),
                $request->string('reason')->toString(),
                $request->user(),
            );
        } catch (InvalidOrderStateTransitionException $e) {
            return $this->error(ApiCode::INVALID_ORDER_STATE_TRANSITION, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        } catch (RefundAmountExceedsPaidException $e) {
            return $this->error(ApiCode::REFUND_AMOUNT_EXCEEDS_PAID, $e->getMessage(), 422);
        }

        $order->refresh();

        return $this->success([
            'order' => new OrderResource($order),
            'refund' => [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'type' => $refund->type,
                'reason' => $refund->reason,
            ],
        ]);
    }
}
