<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MemberSearchRequest;
use App\Http\Resources\Api\V1\MemberCollection;
use App\Services\MemberSearchService;

class MemberController extends Controller
{
    public function __construct(private MemberSearchService $searchService) {}

    public function search(MemberSearchRequest $request): MemberCollection
    {
        $result = $this->searchService->search($request->validated());

        return new MemberCollection($result);
    }
}
