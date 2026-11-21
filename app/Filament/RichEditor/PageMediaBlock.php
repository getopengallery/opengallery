<?php

namespace App\Filament\RichEditor;

use App\Extensions\ThemeManager;
use App\Models\Page;
use App\Models\Photo;
use App\Support\Srcset;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Illuminate\Support\Collection;

/**
 * Shared parts of the Photo and Gallery blocks for page content. Photos are
 * chosen with the shared media picker (MediaPickerField); uploads made there
 * go into the private "Page images" album. On the site, images use
 * page-scoped URLs (see PageController::photo).
 */
abstract class PageMediaBlock extends RichContentCustomBlock
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Photo> photos in the given order (missing / trashed ones dropped)
     */
    protected static function photos(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return Photo::with('media')->whereKey($ids)->get()
            ->sortBy(fn (Photo $photo) => array_search($photo->id, $ids, true))
            ->values();
    }

    /** Image URL on the site: scoped to the page, so private albums stay private elsewhere. */
    protected static function url(?Page $page, Photo $photo, string $conversion): ?string
    {
        if (! $page) {
            return $photo->mediaUrl($conversion);
        }

        return $photo->original()?->hasGeneratedConversion($conversion) ? route('pages.photo', [$page, $photo, $conversion]) : null;
    }

    /** @param  list<string>  $conversions */
    protected static function srcset(?Page $page, Photo $photo, array $conversions): ?string
    {
        return Srcset::for($photo, collect($conversions)->mapWithKeys(fn (string $conversion) => [$conversion => static::url($page, $photo, $conversion)])->all());
    }

    /** Renders a themeable block view (themes can override blocks/{name}). */
    protected static function render(string $block, array $data): string
    {
        return view(app(ThemeManager::class)->blockView($block), $data)->render();
    }

    /** Small thumbnails for the editor preview. @param list<int> $ids */
    protected static function previewThumbs(array $ids, int $limit = 8): string
    {
        $photos = static::photos($ids)->take($limit);

        if ($photos->isEmpty()) {
            return '<p class="og-block-preview-empty">'.e(__('No photos chosen yet.')).'</p>';
        }

        // Styles: og-block-preview in theme-styles.
        return '<div class="og-block-preview">'.$photos->map(fn (Photo $p) => ($url = static::url(null, $p, 'thumb'))
            ? '<img src="'.e($url).'" alt="">'
            : '')->implode('').'</div>';
    }
}
