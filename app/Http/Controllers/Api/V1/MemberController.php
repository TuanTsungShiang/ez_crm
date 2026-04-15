<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MemberCreateRequest;
use App\Http\Requests\Api\V1\MemberSearchRequest;
use App\Http\Requests\Api\V1\MemberUpdateRequest;
use App\Http\Resources\Api\V1\MemberCollection;
use App\Http\Resources\Api\V1\MemberDetailResource;
use App\Http\Resources\Api\V1\MemberResource;
use App\Http\Traits\ApiResponse;
use App\Models\Member;
use App\Services\MemberCreateService;
use App\Services\MemberSearchService;
use App\Services\MemberUpdateService;

class MemberController extends Controller
{
    use ApiResponse;

    public function __construct(
        private MemberSearchService $searchService,
        private MemberCreateService $createService,
        private MemberUpdateService $updateService,
    ) {}

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
     *             @OA\Property(property="code",    type="string",  example="S200"),
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

    /**
     * @OA\Post(
     *     path="/members",
     *     operationId="createMember",
     *     tags={"Members"},
     *     summary="建立會員",
     *     description="CRM 管理員手動新增會員，email 與 phone 至少填一個",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name",             type="string",  example="王小明", description="真實姓名，必填"),
     *             @OA\Property(property="nickname",         type="string",  example="Ming", description="暱稱"),
     *             @OA\Property(property="email",            type="string",  format="email", example="ming@example.com", description="Email，與 phone 至少填一個"),
     *             @OA\Property(property="phone",            type="string",  example="0912345678", description="手機，與 email 至少填一個"),
     *             @OA\Property(property="password",         type="string",  example="password123", description="密碼，min 8，不填則不設密碼"),
     *             @OA\Property(property="status",           type="integer", example=1, description="0=停用 1=正常 2=待驗證，預設 1"),
     *             @OA\Property(property="group_id",         type="integer", example=1, description="會員群組 ID"),
     *             @OA\Property(property="tag_ids",          type="array",   @OA\Items(type="integer"), example={1,2}, description="標籤 ID 陣列"),
     *             @OA\Property(property="profile",          type="object",
     *                 @OA\Property(property="gender",   type="integer", example=1, description="0=不提供 1=男 2=女"),
     *                 @OA\Property(property="birthday", type="string",  format="date", example="1990-05-15", description="生日 Y-m-d")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="建立成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S201"),
     *             @OA\Property(property="data",    type="object",
     *                 @OA\Property(property="uuid",         type="string",  example="550e8400-e29b-41d4-a716-446655440000"),
     *                 @OA\Property(property="name",         type="string",  example="王小明"),
     *                 @OA\Property(property="nickname",     type="string",  example="Ming"),
     *                 @OA\Property(property="email",        type="string",  example="ming@example.com"),
     *                 @OA\Property(property="phone",        type="string",  example="0912345678"),
     *                 @OA\Property(property="status",       type="integer", example=1),
     *                 @OA\Property(property="group",        type="object",
     *                     @OA\Property(property="name", type="string", example="一般會員")
     *                 ),
     *                 @OA\Property(property="tags", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="name",  type="string", example="潛力客"),
     *                         @OA\Property(property="color", type="string", example="#3B82F6")
     *                     )
     *                 ),
     *                 @OA\Property(property="has_sns",       type="boolean", example=false),
     *                 @OA\Property(property="last_login_at", type="string",  nullable=true, example=null),
     *                 @OA\Property(property="created_at",    type="string",  format="date-time", example="2026-04-14T10:00:00+00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="未認證",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code",    type="string",  example="A001"),
     *             @OA\Property(property="message", type="string",  example="Unauthenticated.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="驗證失敗",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code",    type="string",  example="V001"),
     *             @OA\Property(property="message", type="string",  example="Validation failed"),
     *             @OA\Property(property="errors",  type="object")
     *         )
     *     )
     * )
     */
    public function store(MemberCreateRequest $request)
    {
        $member = $this->createService->create($request->validated());

        return $this->created(new MemberResource($member));
    }

    /**
     * @OA\Get(
     *     path="/members/{uuid}",
     *     operationId="showMember",
     *     tags={"Members"},
     *     summary="查看單一會員",
     *     description="取得單一會員詳細資料，含 profile、tags、sns、驗證狀態等",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="uuid", in="path", required=true, description="會員 UUID", @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="查詢成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200"),
     *             @OA\Property(property="data",    type="object",
     *                 @OA\Property(property="uuid",              type="string",  example="550e8400-e29b-41d4-a716-446655440000"),
     *                 @OA\Property(property="name",              type="string",  example="王小明"),
     *                 @OA\Property(property="nickname",          type="string",  example="Ming"),
     *                 @OA\Property(property="email",             type="string",  example="ming@example.com"),
     *                 @OA\Property(property="phone",             type="string",  example="0912345678"),
     *                 @OA\Property(property="status",            type="integer", example=1),
     *                 @OA\Property(property="email_verified_at", type="string",  nullable=true, format="date-time"),
     *                 @OA\Property(property="phone_verified_at", type="string",  nullable=true, format="date-time"),
     *                 @OA\Property(property="group", type="object",
     *                     @OA\Property(property="name", type="string", example="一般會員")
     *                 ),
     *                 @OA\Property(property="tags", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="name",  type="string", example="潛力客"),
     *                         @OA\Property(property="color", type="string", example="#3B82F6")
     *                     )
     *                 ),
     *                 @OA\Property(property="profile", type="object",
     *                     @OA\Property(property="avatar",   type="string",  nullable=true),
     *                     @OA\Property(property="gender",   type="integer", nullable=true, example=1),
     *                     @OA\Property(property="birthday", type="string",  nullable=true, format="date", example="1990-05-15"),
     *                     @OA\Property(property="bio",      type="string",  nullable=true),
     *                     @OA\Property(property="language", type="string",  nullable=true, example="zh-TW"),
     *                     @OA\Property(property="timezone", type="string",  nullable=true, example="Asia/Taipei")
     *                 ),
     *                 @OA\Property(property="sns", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="provider", type="string", example="google")
     *                     )
     *                 ),
     *                 @OA\Property(property="has_sns",       type="boolean", example=true),
     *                 @OA\Property(property="last_login_at", type="string",  nullable=true, format="date-time"),
     *                 @OA\Property(property="created_at",    type="string",  format="date-time"),
     *                 @OA\Property(property="updated_at",    type="string",  format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="未認證",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code",    type="string",  example="A001"),
     *             @OA\Property(property="message", type="string",  example="Unauthenticated.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="會員不存在",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code",    type="string",  example="N001"),
     *             @OA\Property(property="message", type="string",  example="Resource not found")
     *         )
     *     )
     * )
     */
    public function show(Member $member)
    {
        $member->load(['group', 'tags', 'profile', 'sns'])->loadCount('sns');

        return $this->success(new MemberDetailResource($member));
    }

    /**
     * @OA\Put(
     *     path="/members/{uuid}",
     *     operationId="updateMember",
     *     tags={"Members"},
     *     summary="更新會員",
     *     description="部分更新會員資料（沒傳的欄位不動）。更新 email/phone 會自動清空對應 verified_at。tag_ids 採 sync 語意。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="uuid", in="path", required=true, description="會員 UUID", @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name",             type="string",  example="王小明"),
     *             @OA\Property(property="nickname",         type="string",  nullable=true, example="Ming"),
     *             @OA\Property(property="email",            type="string",  format="email", nullable=true, example="ming@example.com"),
     *             @OA\Property(property="phone",            type="string",  nullable=true, example="0912345678"),
     *             @OA\Property(property="password",         type="string",  nullable=true, example="newpassword123"),
     *             @OA\Property(property="status",           type="integer", example=1),
     *             @OA\Property(property="group_id",         type="integer", nullable=true, example=1),
     *             @OA\Property(property="tag_ids",          type="array",   @OA\Items(type="integer"), example={1,2}, description="sync 語意：傳了就完整覆蓋，不傳不動，[] 代表清空"),
     *             @OA\Property(property="profile",          type="object",
     *                 @OA\Property(property="gender",   type="integer", nullable=true),
     *                 @OA\Property(property="birthday", type="string",  nullable=true, format="date"),
     *                 @OA\Property(property="bio",      type="string",  nullable=true),
     *                 @OA\Property(property="avatar",   type="string",  nullable=true),
     *                 @OA\Property(property="language", type="string",  nullable=true),
     *                 @OA\Property(property="timezone", type="string",  nullable=true)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="更新成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200"),
     *             @OA\Property(property="data",    type="object", description="MemberDetailResource")
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="會員不存在"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function update(MemberUpdateRequest $request, Member $member)
    {
        $member = $this->updateService->update($member, $request->validated());

        return $this->success(new MemberDetailResource($member));
    }

    /**
     * @OA\Delete(
     *     path="/members/{uuid}",
     *     operationId="deleteMember",
     *     tags={"Members"},
     *     summary="刪除會員",
     *     description="軟刪除會員。被刪除的會員不會出現在 Search / Show，關聯資料保留不動。",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="uuid", in="path", required=true, description="會員 UUID", @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="刪除成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code",    type="string",  example="S200"),
     *             @OA\Property(property="data",    type="object",
     *                 @OA\Property(property="uuid",       type="string", example="550e8400-e29b-41d4-a716-446655440000"),
     *                 @OA\Property(property="deleted_at", type="string", format="date-time", example="2026-04-15T10:00:00+00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="會員不存在")
     * )
     */
    public function destroy(Member $member)
    {
        $member->delete();

        return $this->success([
            'uuid'       => $member->uuid,
            'deleted_at' => $member->deleted_at->toIso8601String(),
        ]);
    }
}
