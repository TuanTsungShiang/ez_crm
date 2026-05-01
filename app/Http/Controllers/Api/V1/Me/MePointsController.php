<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Models\PointTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MePointsController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/me/points",
     *     operationId="getMePoints",
     *     tags={"Me"},
     *     summary="查看自己的點數",
     *     description="前台會員查看自己的點數餘額與交易明細（分頁）。",
     *     security={{"memberSanctum":{}}},
     *
     *     @OA\Parameter(name="page", in="query", required=false, description="頁碼", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="balance", type="integer", example=1250),
     *                 @OA\Property(property="transactions", type="object",
     *                     @OA\Property(property="data", type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id",            type="integer", example=42),
     *                             @OA\Property(property="amount",        type="integer", example=100),
     *                             @OA\Property(property="balance_after", type="integer", example=1250),
     *                             @OA\Property(property="type",          type="string",  example="earn"),
     *                             @OA\Property(property="reason",        type="string",  example="訂單完成加點"),
     *                             @OA\Property(property="created_at",    type="string",  format="date-time")
     *                         )
     *                     ),
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="last_page",    type="integer", example=2),
     *                     @OA\Property(property="total",        type="integer", example=18)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user()->fresh();

        $transactions = PointTransaction::where('member_id', $member->id)
            ->orderByDesc('id')
            ->paginate(15);

        return $this->success([
            'balance'      => (int) $member->points,
            'transactions' => [
                'data'         => PointTransactionResource::collection($transactions->items()),
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }
}
