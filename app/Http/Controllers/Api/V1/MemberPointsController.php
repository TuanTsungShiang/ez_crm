<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiCode;
use App\Exceptions\Points\InsufficientPointsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdjustPointsRequest;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\PointTransaction;
use App\Services\Points\PointService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class MemberPointsController extends Controller
{
    use ApiResponse;

    public function __construct(private PointService $pointService) {}

    /**
     * @OA\Get(
     *     path="/members/{uuid}/points",
     *     operationId="getMemberPoints",
     *     tags={"Points"},
     *     summary="查詢會員點數",
     *     description="取得會員當前點數餘額與交易明細（分頁）。需要 points.view 權限。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="uuid", in="path", required=true, description="會員 UUID", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="page", in="query", required=false, description="頁碼", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="member_uuid",  type="string",  example="550e8400-e29b-41d4-a716-446655440000"),
     *                 @OA\Property(property="balance",      type="integer", example=1250),
     *                 @OA\Property(property="transactions", type="object",
     *                     @OA\Property(property="data", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id",              type="integer", example=42),
     *                             @OA\Property(property="amount",          type="integer", example=100),
     *                             @OA\Property(property="balance_after",   type="integer", example=1250),
     *                             @OA\Property(property="type",            type="string",  example="earn"),
     *                             @OA\Property(property="reason",          type="string",  example="訂單 #1024 完成"),
     *                             @OA\Property(property="idempotency_key", type="string",  example="550e8400-..."),
     *                             @OA\Property(property="actor",           type="object",
     *                                 @OA\Property(property="id",   type="integer", nullable=true),
     *                                 @OA\Property(property="type", type="string",  example="user"),
     *                                 @OA\Property(property="name", type="string",  nullable=true)
     *                             ),
     *                             @OA\Property(property="created_at", type="string", format="date-time")
     *                         )
     *                     ),
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="last_page",    type="integer", example=3),
     *                     @OA\Property(property="total",        type="integer", example=42)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=403, description="權限不足（需要 points.view）"),
     *     @OA\Response(response=404, description="會員不存在")
     * )
     */
    public function show(Member $member): JsonResponse
    {
        $this->authorize('viewPoints', $member);

        $transactions = PointTransaction::where('member_id', $member->id)
            ->orderByDesc('id')
            ->paginate(15);

        return $this->success([
            'member_uuid'  => $member->uuid,
            'balance'      => (int) $member->points,
            'transactions' => [
                'data'         => PointTransactionResource::collection($transactions->items()),
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/members/{uuid}/points/adjust",
     *     operationId="adjustMemberPoints",
     *     tags={"Points"},
     *     summary="調整會員點數",
     *     description="對會員點數進行增減（earn / spend / adjust / refund）。需要 points.manage 權限。必須帶 Idempotency-Key header 防止重複送出。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="uuid",            in="path",   required=true, description="會員 UUID",       @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="Idempotency-Key", in="header", required=true, description="UUID v4 冪等 key", @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount","reason","type"},
     *             @OA\Property(property="amount", type="integer", example=100,       description="正數=加點 / 負數=扣點，不可為 0，絕對值 ≤ 1,000,000"),
     *             @OA\Property(property="reason", type="string",  example="生日禮贈點", description="人類可讀說明，max 200"),
     *             @OA\Property(property="type",   type="string",  example="earn",    description="earn / spend / adjust / refund（expire 不開放 API）")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="調整成功（或同 key 的 replay 回傳原結果）",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200", description="replay 時為 B002"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="transaction_id", type="integer", example=42),
     *                 @OA\Property(property="balance_before", type="integer", example=1150),
     *                 @OA\Property(property="balance_after",  type="integer", example=1250),
     *                 @OA\Property(property="amount",         type="integer", example=100),
     *                 @OA\Property(property="replayed",       type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=403, description="權限不足（需要 points.manage）"),
     *     @OA\Response(response=404, description="會員不存在"),
     *     @OA\Response(
     *         response=422,
     *         description="驗證失敗 / 點數不足（B001）/ 缺少 Idempotency-Key（V001）",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code",    type="string",  example="B001"),
     *             @OA\Property(property="message", type="string",  example="Insufficient points balance")
     *         )
     *     )
     * )
     */
    public function adjust(AdjustPointsRequest $request, Member $member): JsonResponse
    {
        $this->authorize('managePoints', $member);

        $key = $request->idempotencyKey();
        if ($key === '') {
            return $this->error(ApiCode::MISSING_FIELD, 'Idempotency-Key header is required', 422);
        }

        $balanceBefore = (int) $member->points;

        // Check before calling adjust so we can detect replay vs fresh on return.
        $wasReplay = PointTransaction::where('idempotency_key', $key)->exists();

        try {
            $tx = $this->pointService->adjust(
                $member,
                (int) $request->integer('amount'),
                $request->string('reason')->toString(),
                $request->string('type')->toString(),
                $key,
            );
        } catch (InsufficientPointsException) {
            return $this->error(ApiCode::INSUFFICIENT_POINTS, 'Insufficient points balance', 422);
        } catch (QueryException $e) {
            // TOCTOU: two concurrent requests with same key — the loser hits the DB unique constraint.
            // Treat it as a replay: look up the winner's row and return it.
            if (str_contains($e->getMessage(), '23000')) {
                $tx        = PointTransaction::where('idempotency_key', $key)->firstOrFail();
                $wasReplay = true;
            } else {
                throw $e;
            }
        }

        if ($wasReplay) {
            return $this->success([
                'transaction_id' => $tx->id,
                'balance_before' => (int) ($tx->balance_after - $tx->amount),
                'balance_after'  => (int) $tx->balance_after,
                'amount'         => (int) $tx->amount,
                'replayed'       => true,
            ], ApiCode::IDEMPOTENCY_REPLAY);
        }

        $member->refresh();

        return $this->success([
            'transaction_id' => $tx->id,
            'balance_before' => $balanceBefore,
            'balance_after'  => (int) $tx->balance_after,
            'amount'         => (int) $tx->amount,
            'replayed'       => false,
        ]);
    }
}
