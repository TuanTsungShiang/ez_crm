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
