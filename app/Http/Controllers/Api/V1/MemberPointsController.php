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
use Illuminate\Support\Str;

class MemberPointsController extends Controller
{
    use ApiResponse;

    public function __construct(private PointService $pointService) {}

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
                $tx       = PointTransaction::where('idempotency_key', $key)->firstOrFail();
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
