<?php

namespace App\Support\Dashboard;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queries behind the pages widget (people with "manage pages").
 */
class PageStats
{
    /**
     * @return array{published: int, drafts: int, membersOnly: int, recent: Collection<int, Page>}
     */
    public static function overview(int $limit = 5): array
    {
        $counts = Page::query()
            ->selectRaw('SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published')
            ->selectRaw('SUM(CASE WHEN is_published = 1 THEN 0 ELSE 1 END) as drafts')
            ->selectRaw('SUM(CASE WHEN is_published = 1 AND members_only = 1 THEN 1 ELSE 0 END) as members_only')
            ->toBase()
            ->first();

        return [
            'published' => (int) $counts->published,
            'drafts' => (int) $counts->drafts,
            'membersOnly' => (int) $counts->members_only,
            'recent' => Page::query()->latest('updated_at')->latest('id')->limit($limit)->get(['id', 'title', 'slug', 'is_published', 'members_only', 'updated_at']),
        ];
    }
}
