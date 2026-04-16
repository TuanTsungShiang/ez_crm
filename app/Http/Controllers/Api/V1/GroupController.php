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

    public function index()
    {
        $groups = MemberGroup::withCount('members')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(GroupResource::collection($groups));
    }

    public function store(GroupCreateRequest $request)
    {
        $group = MemberGroup::create($request->validated());

        return $this->created(new GroupResource($group->loadCount('members')));
    }

    public function show(MemberGroup $group)
    {
        return $this->success(new GroupResource($group->loadCount('members')));
    }

    public function update(GroupUpdateRequest $request, MemberGroup $group)
    {
        $group->update($request->validated());

        return $this->success(new GroupResource($group->fresh()->loadCount('members')));
    }

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
