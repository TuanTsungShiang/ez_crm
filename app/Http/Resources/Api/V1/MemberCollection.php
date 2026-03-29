<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MemberCollection extends ResourceCollection
{
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
