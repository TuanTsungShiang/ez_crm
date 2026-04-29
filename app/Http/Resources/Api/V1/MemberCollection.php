<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin LengthAwarePaginator
 */
class MemberCollection extends ResourceCollection
{
    public function withResponse($request, $response)
    {
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

    public function paginationInformation($request, $paginated, $default): array
    {
        return [];
    }

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public $collects = MemberResource::class;

    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'code'    => \App\Enums\ApiCode::OK,
            'data'    => [
                'items'      => $this->collection,
                'pagination' => [
                    'total'        => $this->total(),
                    'per_page'     => $this->perPage(),
                    'current_page' => $this->currentPage(),
                    'last_page'    => $this->lastPage(),
                ],
            ],
        ];
    }
}
