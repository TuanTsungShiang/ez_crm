<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
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

        $sortBy  = $this->resolveSortBy($params['sort_by'] ?? null);
        $sortDir = $this->resolveSortDir($params['sort_dir'] ?? null);
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($params['per_page'] ?? 15);
    }

    private function applyKeyword(Builder $query, ?string $keyword): void
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

    private function applyFilters(Builder $query, array $params): void
    {
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['group_id'])) {
            $query->where('member_group_id', $params['group_id']);
        }

        if (!empty($params['tag_ids'])) {
            $this->applyTagFilters($query, $params['tag_ids']);
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

    private function applyTagFilters(Builder $query, array $tagIds): void
    {
        $tagIds = array_values(array_unique($tagIds));

        $query->whereIn('members.id', function ($sub) use ($tagIds) {
            $sub->select('member_id')
                ->from('member_tag')
                ->whereIn('tag_id', $tagIds)
                ->groupBy('member_id')
                ->havingRaw('COUNT(DISTINCT tag_id) = ?', [count($tagIds)]);
        });
    }

    private function resolveSortBy(?string $sortBy): string
    {
        $allowedSorts = ['created_at', 'last_login_at', 'name'];

        return in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
    }

    private function resolveSortDir(?string $sortDir): string
    {
        return $sortDir === 'asc' ? 'asc' : 'desc';
    }
}
