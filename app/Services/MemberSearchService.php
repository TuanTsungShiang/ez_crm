<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Pagination\LengthAwarePaginator;

class MemberSearchService
{
    public function search(array $params): LengthAwarePaginator
    {
        $query = Member::query()
            ->with(['group', 'tags', 'profile'])
            ->withCount('sns');

        $this->applyKeyword($query, $params['keyword'] ?? null);
        $this->applyFilters($query, $params);

        $sortBy  = $params['sort_by'] ?? 'created_at';
        $sortDir = $params['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($params['per_page'] ?? 15);
    }

    private function applyKeyword($query, ?string $keyword): void
    {
        if (!$keyword) {
            return;
        }

        $query->where(function ($q) use ($keyword) {
            $q->where('name', 'like', "%{$keyword}%")
              ->orWhere('nickname', 'like', "%{$keyword}%")
              ->orWhere('email', 'like', "%{$keyword}%")
              ->orWhere('phone', 'like', "%{$keyword}%");
        });
    }

    private function applyFilters($query, array $params): void
    {
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['group_id'])) {
            $query->where('member_group_id', $params['group_id']);
        }

        if (!empty($params['tag_ids'])) {
            foreach ($params['tag_ids'] as $tagId) {
                $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
            }
        }

        if (isset($params['gender'])) {
            $query->whereHas('profile', fn($q) => $q->where('gender', $params['gender']));
        }

        if (isset($params['has_sns'])) {
            $params['has_sns']
                ? $query->whereHas('sns')
                : $query->whereDoesntHave('sns');
        }

        if (!empty($params['created_from'])) {
            $query->whereDate('created_at', '>=', $params['created_from']);
        }

        if (!empty($params['created_to'])) {
            $query->whereDate('created_at', '<=', $params['created_to']);
        }
    }
}
