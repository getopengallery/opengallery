<?php

namespace App\Models;

use App\Support\RequestMemo;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A simple content page (imprint, privacy policy, about us...). Linked from
 * the header or footer through the active theme's settings.
 *
 * Published pages are public even when public albums are turned off, so
 * legally required pages stay reachable; "members only" pages need a login.
 */
#[Fillable(['title', 'slug', 'body', 'is_published', 'members_only'])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'members_only' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Page $page) {
            $page->slug = filled($page->slug) ? Str::slug($page->slug) : Str::slug($page->title);
        });

        static::saved(fn () => RequestMemo::instance()->forget(self::LINKABLE_MEMO));
        static::deleted(fn () => RequestMemo::instance()->forget(self::LINKABLE_MEMO));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @param Builder<Page> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Ids of photos placed in this page with the Photo and Gallery blocks.
     * Placing a photo on a page publishes it there, even if its albums are
     * private (see PageController::photo).
     *
     * @return list<int>
     */
    public function embeddedPhotoIds(): array
    {
        return static::photoIdsIn($this->body);
    }

    /**
     * Ids of photos in Photo and Gallery blocks of page content (HTML as stored).
     *
     * @return list<int>
     */
    public static function photoIdsIn(?string $body): array
    {
        if (blank($body) || ! str_contains($body, 'customBlock')) {
            return [];
        }

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><div>'.$body.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

        $ids = [];
        foreach ((new \DOMXPath($document))->query('//*[@data-type="customBlock"]') as $node) {
            $config = json_decode((string) $node->getAttribute('data-config'), true) ?: [];
            array_push($ids, ...array_map('intval', array_filter([$config['photo_id'] ?? null, ...(array) ($config['photo_ids'] ?? [])])));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Ids of photos placed on any page (published or not).
     *
     * @return list<int>
     */
    public static function usedPhotoIds(): array
    {
        return static::query()->pluck('body')
            ->flatMap(fn (?string $body) => static::photoIdsIn($body))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Photos in $body that $user may not place on a page: photos they cannot
     * see (Photo::visibleTo()) that are not already on the saved $page. Photos
     * placed earlier (e.g. by staff) may stay when someone else edits the page.
     *
     * @return list<int>
     */
    public static function unusablePhotoIds(?string $body, User $user, ?Page $page = null): array
    {
        $new = array_values(array_diff(static::photoIdsIn($body), $page?->embeddedPhotoIds() ?? []));

        if ($new === []) {
            return [];
        }

        $usable = Photo::query()->visibleTo($user)->whereKey($new)->pluck('id')->all();

        return array_values(array_diff($new, $usable));
    }

    public function isVisibleTo(?User $user): bool
    {
        return $this->is_published && (! $this->members_only || $user !== null);
    }

    /** RequestMemo key prefix of linkable(); forgotten whenever a page is saved or deleted. */
    public const LINKABLE_MEMO = 'linkable-pages.';

    /**
     * Published pages with the given ids, in that order, that $user may see,
     * for links in menus: without their content (`body`). The header and the
     * footer ask several times per page view, so the published pages are
     * read once per request (RequestMemo).
     *
     * @param  list<int|string>  $ids
     * @return Collection<int, Page>
     */
    public static function linkable(array $ids, ?User $user): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        $pages = RequestMemo::instance()->remember(self::LINKABLE_MEMO.($user ? 'members' : 'guests'), fn () => static::published()
            ->when(! $user, fn (Builder $q) => $q->where('members_only', false))
            ->get(['id', 'title', 'slug', 'is_published', 'members_only'])
            ->keyBy('id'));

        return collect($ids)
            ->unique()
            ->map(fn (int $id) => $pages->get($id))
            ->filter()
            ->values();
    }
}
