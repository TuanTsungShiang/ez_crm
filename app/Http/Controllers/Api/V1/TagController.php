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

    public function index()
    {
        $tags = Tag::withCount('members')
            ->orderBy('name')
            ->get();

        return $this->success(TagResource::collection($tags));
    }

    public function store(TagCreateRequest $request)
    {
        $tag = Tag::create($request->validated());

        return $this->created(new TagResource($tag->loadCount('members')));
    }

    public function show(Tag $tag)
    {
        return $this->success(new TagResource($tag->loadCount('members')));
    }

    public function update(TagUpdateRequest $request, Tag $tag)
    {
        $tag->update($request->validated());

        return $this->success(new TagResource($tag->fresh()->loadCount('members')));
    }

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
