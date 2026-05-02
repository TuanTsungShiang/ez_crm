<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentCallback;
use App\Services\Payments\ECPay\ECPayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives inbound ECPay payment-result webhooks.
 *
 * ECPay protocol:
 *   - POST with application/x-www-form-urlencoded body
 *   - Expects plain-text response body:
 *       "1|OK"  → acknowledged, stop retrying
 *       "0|Err" → error, ECPay will retry
 *
 * We ALWAYS return "1|OK" (200) regardless of internal processing outcome.
 * Returning a non-OK causes ECPay to retry the same payload indefinitely,
 * which we don't want for signature failures or duplicate callbacks.
 *
 * Three-layer security (per ORDER_INTEGRATION_PLAN §9):
 *   1. EcpayIpWhitelist middleware    — IP whitelist (production only)
 *   2. ECPayService::verifyCallback() — CheckMacValue SHA256 signature
 *   3. DB UNIQUE + app replay guard   — idempotent duplicate handling
 */
class EcpayWebhookController extends Controller
{
    public function __construct(private ECPayService $ecpay) {}

    /**
     * @OA\Post(
     *     path="/webhooks/ecpay/payment",
     *     operationId="ecpayPaymentWebhook",
     *     tags={"Webhooks"},
     *     summary="ECPay 付款結果通知 (Server-to-Server)",
     *     description="ECPay 付款完成後 POST 至此端點。三層防護：IP 白名單 (prod) + CheckMacValue 驗簽 + 重放防禦。永遠回傳 200 1|OK 避免 ECPay 重試。",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="ECPay AIO 付款通知參數（application/x-www-form-urlencoded）",
     *
     *         @OA\MediaType(
     *             mediaType="application/x-www-form-urlencoded",
     *
     *             @OA\Schema(
     *                 required={"MerchantID","MerchantTradeNo","TradeNo","RtnCode","RtnMsg","TradeAmt","PaymentDate","CheckMacValue"},
     *
     *                 @OA\Property(property="MerchantID",       type="string", example="2000132"),
     *                 @OA\Property(property="MerchantTradeNo",  type="string", example="EZ-20260502-0001"),
     *                 @OA\Property(property="TradeNo",          type="string", example="2026050204396522"),
     *                 @OA\Property(property="RtnCode",          type="string", example="1", description="1=成功"),
     *                 @OA\Property(property="RtnMsg",           type="string", example="交易成功"),
     *                 @OA\Property(property="TradeAmt",         type="integer", example=2000),
     *                 @OA\Property(property="PaymentDate",      type="string", example="2026/05/02 14:39:55"),
     *                 @OA\Property(property="PaymentType",      type="string", example="Credit_CreditCard"),
     *                 @OA\Property(property="CheckMacValue",    type="string", example="A3F9...")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="已收到（1|OK）",
     *
     *         @OA\MediaType(mediaType="text/plain", @OA\Schema(type="string", example="1|OK"))
     *     ),
     *
     *     @OA\Response(response=403, description="IP 不在白名單（僅 production 環境）")
     * )
     */
    public function payment(Request $request): Response
    {
        $payload = $request->all();

        // Delegate all processing to ECPayService — controller stays thin
        $cb = $this->ecpay->handleCallback($payload);

        // Log noteworthy outcomes without breaking the response contract
        if (in_array($cb->status, [PaymentCallback::STATUS_FAILED, PaymentCallback::STATUS_DUPLICATE], true)) {
            logger()->info('ECPay webhook outcome', [
                'status' => $cb->status,
                'trade_no' => $cb->trade_no ?? ($payload['MerchantTradeNo'] ?? ''),
                'cb_id' => $cb->exists ? $cb->id : null,
            ]);
        }

        // ECPay protocol: ALWAYS 200 "1|OK" — any other response triggers retry
        return response('1|OK', 200)->header('Content-Type', 'text/plain');
    }
}
