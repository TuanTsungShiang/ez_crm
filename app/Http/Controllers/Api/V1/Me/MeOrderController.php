<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiCode;
use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\CreateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Payments\ECPay\ECPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member-facing order endpoints.
 *
 * All routes guarded by auth:member — $request->user() is a Member instance.
 * Authorization is ownership-based: members can only see/act on their own orders.
 */
class MeOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderService $orderService,
        private ECPayService $ecpayService,
    ) {}

    /**
     * @OA\Post(
     *     path="/me/orders",
     *     operationId="memberCreateOrder",
     *     tags={"Me - Orders"},
     *     summary="下單",
     *     description="建立訂單並取得 ECPay 付款表單。需要 Idempotency-Key header（UUID v4）。",
     *     security={{"memberAuth":{}}},
     *
     *     @OA\Parameter(name="Idempotency-Key", in="header", required=true,
     *
     *         @OA\Schema(type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"items","shipping_address"},
     *
     *         @OA\Property(property="items", type="array", @OA\Items(
     *             required={"product_sku","product_name","unit_price","quantity"},
     *             @OA\Property(property="product_sku",  type="string",  example="SKU-001"),
     *             @OA\Property(property="product_name", type="string",  example="商品 A"),
     *             @OA\Property(property="unit_price",   type="integer", example=1000),
     *             @OA\Property(property="quantity",     type="integer", example=2)
     *         )),
     *         @OA\Property(property="shipping_address", type="object",
     *             @OA\Property(property="recipient_name", type="string",  example="王小明"),
     *             @OA\Property(property="phone",          type="string",  example="0912345678"),
     *             @OA\Property(property="postal_code",    type="string",  example="100"),
     *             @OA\Property(property="city",           type="string",  example="台北市"),
     *             @OA\Property(property="district",       type="string",  example="中正區"),
     *             @OA\Property(property="address_line",   type="string",  example="測試路 1 號")
     *         ),
     *         @OA\Property(property="coupon_codes", type="array", nullable=true, @OA\Items(type="string"))
     *     )),
     *
     *     @OA\Response(response=201, description="訂單建立成功，含 ECPay form HTML"),
     *     @OA\Response(response=422, description="V001 缺欄 / C001 券狀態無效 / C002 券過期 / D006 低於最低金額"),
     *     @OA\Response(response=401, description="未登入")
     * )
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->idempotencyKey();
        if ($idempotencyKey === '') {
            return $this->error(ApiCode::MISSING_FIELD, 'Idempotency-Key header is required', 422);
        }

        $member = $request->user();

        try {
            $order = $this->orderService->create(
                $member,
                $request->validated(),
                $idempotencyKey,
            );
        } catch (CouponExpiredException $e) {
            return $this->error(ApiCode::COUPON_EXPIRED, $e->getMessage(), 422);
        } catch (InvalidCouponStateException $e) {
            return $this->error(ApiCode::INVALID_COUPON_STATE, $e->getMessage(), 422, [
                'current_status' => $e->currentStatus,
            ]);
        }

        $order->load(['items', 'shippingAddress', 'coupons']);
        $ecpayHtml = $this->ecpayService->createPaymentForm($order);

        return $this->created([
            'order' => new OrderResource($order),
            'ecpay_payment_html' => $ecpayHtml,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/me/orders",
     *     operationId="memberListOrders",
     *     tags={"Me - Orders"},
     *     summary="查自己的訂單列表",
     *     security={{"memberAuth":{}}},
     *
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(response=200, description="分頁訂單列表"),
     *     @OA\Response(response=401, description="未登入")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('member_id', $request->user()->id)
            ->latest()
            ->paginate(20);

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
     *     path="/me/orders/{order_no}",
     *     operationId="memberShowOrder",
     *     tags={"Me - Orders"},
     *     summary="查單筆訂單詳情",
     *     security={{"memberAuth":{}}},
     *
     *     @OA\Parameter(name="order_no", in="path", required=true,
     *
     *         @OA\Schema(type="string", example="EZ-20260502-0001")),
     *
     *     @OA\Response(response=200, description="訂單詳情"),
     *     @OA\Response(response=404, description="訂單不存在或不屬於此會員")
     * )
     */
    public function show(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)
            ->where('member_id', $request->user()->id)
            ->with(['items', 'shippingAddress', 'coupons'])
            ->firstOrFail();

        return $this->success(new OrderResource($order));
    }

    /**
     * @OA\Post(
     *     path="/me/orders/{order_no}/repay",
     *     operationId="memberRepayOrder",
     *     tags={"Me - Orders"},
     *     summary="重付（重新取得 ECPay 付款表單）",
     *     description="只允許 pending 狀態的訂單重新取得付款表單。",
     *     security={{"memberAuth":{}}},
     *
     *     @OA\Parameter(name="order_no", in="path", required=true,
     *
     *         @OA\Schema(type="string", example="EZ-20260502-0001")),
     *
     *     @OA\Response(response=200, description="ECPay form HTML"),
     *     @OA\Response(response=422, description="D001 訂單狀態不允許重付"),
     *     @OA\Response(response=404, description="訂單不存在")
     * )
     */
    public function repay(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)
            ->where('member_id', $request->user()->id)
            ->with('items')
            ->firstOrFail();

        if ($order->status !== Order::STATUS_PENDING) {
            return $this->error(
                ApiCode::INVALID_ORDER_STATE_TRANSITION,
                "Cannot repay order with status '{$order->status}'",
                422,
                ['current_status' => $order->status],
            );
        }

        $ecpayHtml = $this->ecpayService->createPaymentForm($order);

        return $this->success([
            'order' => new OrderResource($order),
            'ecpay_payment_html' => $ecpayHtml,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/me/orders/{order_no}/cancel",
     *     operationId="memberCancelOrder",
     *     tags={"Me - Orders"},
     *     summary="取消訂單",
     *     description="只允許 pending 狀態。paid / shipped 取消需走 Admin API。",
     *     security={{"memberAuth":{}}},
     *
     *     @OA\Parameter(name="order_no", in="path", required=true,
     *
     *         @OA\Schema(type="string", example="EZ-20260502-0001")),
     *
     *     @OA\Response(response=200, description="訂單已取消"),
     *     @OA\Response(response=422, description="D001 訂單狀態不允許取消"),
     *     @OA\Response(response=404, description="訂單不存在")
     * )
     */
    public function cancel(Request $request, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)
            ->where('member_id', $request->user()->id)
            ->firstOrFail();

        // Member can only cancel pending orders (T2).
        // Paid / shipped cancellations require admin review (T4 / T7).
        if ($order->status !== Order::STATUS_PENDING) {
            return $this->error(
                ApiCode::INVALID_ORDER_STATE_TRANSITION,
                "Cannot cancel order with status '{$order->status}'",
                422,
                ['current_status' => $order->status],
            );
        }

        try {
            $cancelled = $this->orderService->cancel($order, $request->user());
        } catch (InvalidOrderStateTransitionException $e) {
            return $this->error(
                ApiCode::INVALID_ORDER_STATE_TRANSITION,
                $e->getMessage(),
                422,
                ['current_status' => $e->currentStatus],
            );
        }

        return $this->success(new OrderResource($cancelled));
    }
}
