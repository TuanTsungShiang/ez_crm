<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GroupCreateRequest;
use App\Http\Requests\Api\V1\GroupUpdateRequest;
use App\Http\Resources\Api\V1\GroupResource;
use App\Http\Traits\ApiResponse;
use App\Models\MemberGroup;

class GroupController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/groups",
     *     operationId="listGroups",
     *     tags={"Groups"},
     *     summary="群組列表",
     *     description="取得所有會員群組，依 sort_order 排序，含各群組會員數",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="一般會員"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="sort_order", type="integer", example=1),
     *                 @OA\Property(property="member_count", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證")
     * )
     */
    public function index()
    {
        $groups = MemberGroup::withCount('members')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(GroupResource::collection($groups));
    }

    /**
     * @OA\Post(
     *     path="/groups",
     *     operationId="createGroup",
     *     tags={"Groups"},
     *     summary="建立群組",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"name"},
     *             @OA\Property(property="name", type="string", example="VIP", description="群組名稱，unique"),
     *             @OA\Property(property="description", type="string", nullable=true, example="高消費客群"),
     *             @OA\Property(property="sort_order", type="integer", example=1, description="排序，預設 0")
     *         )
     *     ),
     *     @OA\Response(response=201, description="建立成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S201"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="VIP"),
     *                 @OA\Property(property="description", type="string", nullable=true, example="高消費客群"),
     *                 @OA\Property(property="sort_order", type="integer", example=1),
     *                 @OA\Property(property="member_count", type="integer", example=0),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function store(GroupCreateRequest $request)
    {
        $group = MemberGroup::create($request->validated());

        return $this->created(new GroupResource($group->loadCount('members')));
    }

    /**
     * @OA\Get(
     *     path="/groups/{id}",
     *     operationId="showGroup",
     *     tags={"Groups"},
     *     summary="查看單一群組",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="一般會員"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="sort_order", type="integer", example=1),
     *                 @OA\Property(property="member_count", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="群組不存在")
     * )
     */
    public function show(MemberGroup $group)
    {
        return $this->success(new GroupResource($group->loadCount('members')));
    }

    /**
     * @OA\Put(
     *     path="/groups/{id}",
     *     operationId="updateGroup",
     *     tags={"Groups"},
     *     summary="更新群組",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="金牌會員"),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="sort_order", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="更新成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="金牌會員"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="sort_order", type="integer", example=1),
     *                 @OA\Property(property="member_count", type="integer", example=5),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="群組不存在"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function update(GroupUpdateRequest $request, MemberGroup $group)
    {
        $group->update($request->validated());

        return $this->success(new GroupResource($group->fresh()->loadCount('members')));
    }

    /**
     * @OA\Delete(
     *     path="/groups/{id}",
     *     operationId="deleteGroup",
     *     tags={"Groups"},
     *     summary="刪除群組",
     *     description="僅可刪除無會員的群組，有會員使用中會回 422",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="刪除成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="VIP")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="群組不存在"),
     *     @OA\Response(response=422, description="群組下仍有會員，無法刪除",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code", type="string", example="V005"),
     *             @OA\Property(property="message", type="string", example="此群組下仍有 3 位會員，無法刪除")
     *         )
     *     )
     * )
     */
    public function destroy(MemberGroup $group)
    {
        $memberCount = $group->members()->count();

        if ($memberCount > 0) {
            return $this->error(
                ApiCode::INVALID_RELATION,
                "此群組下仍有 {$memberCount} 位會員，無法刪除",
                422
            );
        }

        $group->delete();

        return $this->success(['id' => $group->id, 'name' => $group->name]);
    }
}
