<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TagCreateRequest;
use App\Http\Requests\Api\V1\TagUpdateRequest;
use App\Http\Resources\Api\V1\TagResource;
use App\Http\Traits\ApiResponse;
use App\Models\Tag;

class TagController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/tags",
     *     operationId="listTags",
     *     tags={"Tags"},
     *     summary="標籤列表",
     *     description="取得所有標籤，依名稱排序，含各標籤會員數",
     *     security={{"sanctum":{}}},
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="潛力客"),
     *                 @OA\Property(property="color", type="string", example="#3B82F6"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="member_count", type="integer", example=3),
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
        $tags = Tag::withCount('members')
            ->orderBy('name')
            ->get();

        return $this->success(TagResource::collection($tags));
    }

    /**
     * @OA\Post(
     *     path="/tags",
     *     operationId="createTag",
     *     tags={"Tags"},
     *     summary="建立標籤",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"name"},
     *             @OA\Property(property="name", type="string", example="潛力客", description="標籤名稱，unique"),
     *             @OA\Property(property="color", type="string", example="#3B82F6", description="hex 色碼，格式 #RRGGBB"),
     *             @OA\Property(property="description", type="string", nullable=true, example="有轉換潛力的客戶")
     *         )
     *     ),
     *     @OA\Response(response=201, description="建立成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S201"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="潛力客"),
     *                 @OA\Property(property="color", type="string", example="#3B82F6"),
     *                 @OA\Property(property="description", type="string", nullable=true, example="有轉換潛力的客戶"),
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
    public function store(TagCreateRequest $request)
    {
        $tag = Tag::create($request->validated());

        return $this->created(new TagResource($tag->loadCount('members')));
    }

    /**
     * @OA\Get(
     *     path="/tags/{id}",
     *     operationId="showTag",
     *     tags={"Tags"},
     *     summary="查看單一標籤",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="潛力客"),
     *                 @OA\Property(property="color", type="string", example="#3B82F6"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="member_count", type="integer", example=3),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="標籤不存在")
     * )
     */
    public function show(Tag $tag)
    {
        return $this->success(new TagResource($tag->loadCount('members')));
    }

    /**
     * @OA\Put(
     *     path="/tags/{id}",
     *     operationId="updateTag",
     *     tags={"Tags"},
     *     summary="更新標籤",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="VIP客戶"),
     *             @OA\Property(property="color", type="string", example="#FF5733"),
     *             @OA\Property(property="description", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="更新成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="VIP客戶"),
     *                 @OA\Property(property="color", type="string", example="#FF5733"),
     *                 @OA\Property(property="description", type="string", nullable=true),
     *                 @OA\Property(property="member_count", type="integer", example=3),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="標籤不存在"),
     *     @OA\Response(response=422, description="驗證失敗")
     * )
     */
    public function update(TagUpdateRequest $request, Tag $tag)
    {
        $tag->update($request->validated());

        return $this->success(new TagResource($tag->fresh()->loadCount('members')));
    }

    /**
     * @OA\Delete(
     *     path="/tags/{id}",
     *     operationId="deleteTag",
     *     tags={"Tags"},
     *     summary="刪除標籤",
     *     description="僅可刪除無會員使用的標籤，有會員使用中會回 422",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="刪除成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="S200"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="潛力客")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="未認證"),
     *     @OA\Response(response=404, description="標籤不存在"),
     *     @OA\Response(response=422, description="標籤仍有會員使用中，無法刪除",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="code", type="string", example="V005"),
     *             @OA\Property(property="message", type="string", example="此標籤仍有 3 位會員使用中，無法刪除")
     *         )
     *     )
     * )
     */
    public function destroy(Tag $tag)
    {
        $memberCount = $tag->members()->count();

        if ($memberCount > 0) {
            return $this->error(
                ApiCode::INVALID_RELATION,
                "此標籤仍有 {$memberCount} 位會員使用中，無法刪除",
                422
            );
        }

        $tag->delete();

        return $this->success(['id' => $tag->id, 'name' => $tag->name]);
    }
}
