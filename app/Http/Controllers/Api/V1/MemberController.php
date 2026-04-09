<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MemberSearchRequest;
use App\Http\Resources\Api\V1\MemberCollection;
use App\Services\MemberSearchService;

class MemberController extends Controller
{
    public function __construct(private MemberSearchService $searchService) {}

    /**
     * @OA\Get(
     *     path="/members",
     *     operationId="searchMembers",
     *     tags={"Members"},
     *     summary="搜尋會員",
     *     description="根據關鍵字、狀態、群組、標籤等條件搜尋會員，支援分頁與排序",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="keyword",      in="query", description="搜尋關鍵字（姓名、Email、電話）", required=false, @OA\Schema(type="string", maxLength=100)),
     *     @OA\Parameter(name="status",       in="query", description="會員狀態（0=停用, 1=啟用, 2=黑名單）", required=false, @OA\Schema(type="integer", enum={0,1,2})),
     *     @OA\Parameter(name="group_id",     in="query", description="會員群組 ID", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tag_ids[]",    in="query", description="標籤 ID 陣列", required=false, @OA\Schema(type="array", @OA\Items(type="integer"))),
     *     @OA\Parameter(name="gender",       in="query", description="性別（0=未知, 1=男, 2=女）", required=false, @OA\Schema(type="integer", enum={0,1,2})),
     *     @OA\Parameter(name="has_sns",      in="query", description="是否有社群帳號", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="created_from", in="query", description="建立日期起（Y-m-d）", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to",   in="query", description="建立日期迄（Y-m-d）", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page",     in="query", description="每頁筆數（1-100，預設 15）", required=false, @OA\Schema(type="integer", minimum=1, maximum=100, default=15)),
     *     @OA\Parameter(name="page",         in="query", description="頁碼", required=false, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="sort_by",      in="query", description="排序欄位", required=false, @OA\Schema(type="string", enum={"created_at","last_login_at","name"})),
     *     @OA\Parameter(name="sort_dir",     in="query", description="排序方向", required=false, @OA\Schema(type="string", enum={"asc","desc"})),
     *
     *     @OA\Response(
     *         response=200,
     *         description="搜尋成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="items", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="uuid", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
     *                         @OA\Property(property="name", type="string", example="王小明"),
     *                         @OA\Property(property="nickname", type="string", example="小明"),
     *                         @OA\Property(property="email", type="string", example="ming@example.com"),
     *                         @OA\Property(property="phone", type="string", example="0912345678"),
     *                         @OA\Property(property="status", type="integer", example=1),
     *                         @OA\Property(property="group", type="object",
     *                             @OA\Property(property="name", type="string", example="VIP")
     *                         ),
     *                         @OA\Property(property="tags", type="array",
     *                             @OA\Items(
     *                                 @OA\Property(property="name", type="string", example="活躍"),
     *                                 @OA\Property(property="color", type="string", example="#FF5733")
     *                             )
     *                         ),
     *                         @OA\Property(property="has_sns", type="boolean", example=true),
     *                         @OA\Property(property="last_login_at", type="string", format="date-time", example="2026-01-15T08:30:00+08:00"),
     *                         @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-01T10:00:00+08:00")
     *                     )
     *                 ),
     *                 @OA\Property(property="pagination", type="object",
     *                     @OA\Property(property="total", type="integer", example=50),
     *                     @OA\Property(property="per_page", type="integer", example=15),
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="last_page", type="integer", example=4)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="未認證",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="驗證失敗",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function search(MemberSearchRequest $request): MemberCollection
    {
        $result = $this->searchService->search($request->validated());

        return new MemberCollection($result);
    }
}
