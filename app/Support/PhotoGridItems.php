<?php

namespace App\Support;

use App\Enums\LightboxFooter;
use App\Models\Album;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Normalises photos into the plain data every photo-grid block variant
 * needs, so theme authors only deal with arrays in their Blade files.
 *
 * `srcset` lists the `small` and `thumb` sizes (null until `small` exists;
 * App\Support\Srcset); blocks add a `sizes` that fits their layout.
 * `loading`, `fetchpriority` and `decoding` are the <img> attributes for the
 * photo's place on the page (App\Support\ImageLoading): the first photos load
 * straight away, the rest when scrolled to.
 *
 * The `lightbox` part is what the lightbox (resources/js/lightbox.js) shows,
 * including a `download` address when downloads are on (Settings → Photos)
 * and the album allows them, the heart (`favorite`: toggle address and
 * whether it is on; null for guests or with favourites off) and the camera
 * details for the "i" panel (`info`, App\Support\PhotoExif; null when that
 * panel is off).
 * `footer` is what the lightbox shows below the photo, as chosen in
 * Settings → Photos (App\Enums\LightboxFooter): a `text` line, a muted
 * `note` line and/or a `license`; null when the photo has nothing to show.
 * For a page of a paginated list it also carries the photo's place in the
 * whole list (`position` of `total`) and the address of its `page`, and the
 * first / last photo carry the address of the previous / next page, so the
 * lightbox can go on there. For an album's photos they also carry
 * `previousItems` / `nextItems`: the address of that page's items as JSON
 * (route albums.photos), which the lightbox loads instead of the page.
 */
class PhotoGridItems
{
    /**
     * @param  iterable<Photo>  $photos
     * @return Collection<int, array{ratio: float, thumb: string, srcset: ?string, loading: string, fetchpriority: string, decoding: string, lightbox: array{id: int, src: string, title: ?string, description: ?string, taken: ?string, download: ?string, favorite: ?array{url: string, active: bool}, info: ?list<array{label: string, value: string}>, license: ?array{name: string, title: ?string, url: ?string}, footer: ?array{text: ?string, note: ?string, license: ?array{name: string, title: ?string, url: ?string}}, position?: int, total?: int, page?: string, previousPage?: string, nextPage?: string, previousItems?: string, nextItems?: string}}>
     */
    public static function from(iterable $photos): Collection
    {
        $paginator = $photos instanceof AbstractPaginator ? $photos : null;

        $photos = collect($paginator ? $paginator->items() : $photos);

        $user = auth()->user();
        $favorites = Favorites::enabledFor($user);
        $info = SiteSettings::photoInfoEnabled();
        $licenses = $info && Licensing::enabled();
        $footer = SiteSettings::lightboxFooter();

        if ($licenses || ($footer === LightboxFooter::License && Licensing::enabled())) {
            (new EloquentCollection($photos->all()))->loadMissing('license');
        }

        $items = $photos
            ->map(function (Photo $photo) use ($user, $favorites, $info, $licenses, $footer) {
                $media = $photo->getFirstMedia(Photo::COLLECTION);

                if (! $media) {
                    return null;
                }

                return [
                    'ratio' => $photo->aspectRatio(),
                    'thumb' => route('media.show', [$media, 'thumb']),
                    'srcset' => Srcset::for($photo, [
                        'small' => $media->hasGeneratedConversion('small') ? route('media.show', [$media, 'small']) : null,
                        'thumb' => route('media.show', [$media, 'thumb']),
                    ]),
                    ...ImageLoading::next(),
                    'lightbox' => [
                        'id' => $photo->id,
                        'src' => route('media.show', [$media, 'preview']),
                        'title' => $photo->title,
                        'description' => $photo->description,
                        'taken' => DateFormat::date($photo->taken_at),
                        'download' => self::allowsDownloads($photo) ? route('media.show', ['media' => $media, 'download' => 1]) : null,
                        'favorite' => $favorites ? ['url' => route('favorites.toggle', $photo), 'active' => Favorites::has($user, $photo)] : null,
                        'info' => $info ? PhotoExif::details($photo) : null,
                        'license' => $licenses ? Licensing::displayFor($photo) : null,
                        'footer' => self::footer($footer, $photo, $media, $info),
                    ],
                ];
            })
            ->filter()
            ->values();

        return $paginator ? self::withPages($items, $paginator, self::albumOf($photos->first())) : $items;
    }

    /**
     * What the lightbox shows below the photo for the chosen setting. Camera
     * details follow the "i" panel switch and the license the licensing switch,
     * so the footer never shows more than the site has turned on.
     *
     * @return ?array{text: ?string, note: ?string, license: ?array{name: string, title: ?string, url: ?string}}
     */
    private static function footer(LightboxFooter $footer, Photo $photo, Media $media, bool $info): ?array
    {
        $taken = DateFormat::date($photo->taken_at);

        [$text, $note, $license] = match ($footer) {
            LightboxFooter::DescriptionAndTaken => [$photo->description, $taken ? __('Taken').' '.$taken : null, null],
            LightboxFooter::Title => [$photo->title ?: $media->file_name, null, null],
            LightboxFooter::FileName => [$media->file_name, null, null],
            LightboxFooter::Description => [$photo->description, null, null],
            LightboxFooter::Taken => [null, $taken ? __('Taken').' '.$taken : null, null],
            LightboxFooter::License => [null, null, Licensing::displayFor($photo)],
            LightboxFooter::Camera => [$info ? PhotoExif::summary($photo) : null, null, null],
            LightboxFooter::None => [null, null, null],
        };

        if (blank($text) && blank($note) && ! $license) {
            return null;
        }

        return ['text' => filled($text) ? $text : null, 'note' => $note, 'license' => $license];
    }

    /**
     * Photos listed through an album (Album::photos()) know that album from
     * the pivot; its "allow downloads" switch decides. The visitor is on the
     * album's page, so they may see it; MediaController checks again.
     */
    private static function allowsDownloads(Photo $photo): bool
    {
        return SiteSettings::downloadsEnabled() && (bool) self::albumOf($photo)?->allow_downloads;
    }

    private static function albumOf(?Photo $photo): ?Album
    {
        $album = $photo?->pivot?->pivotParent ?? null;

        return $album instanceof Album ? $album : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private static function withPages(Collection $items, AbstractPaginator $paginator, ?Album $album): Collection
    {
        $last = $items->count() - 1;
        $page = $paginator->currentPage();
        $pageUrl = $paginator->url($page);
        $itemsUrl = fn (int $page): ?string => $album ? route('albums.photos', ['album' => $album, 'page' => $page]) : null;

        return $items->map(function (array $item, int $i) use ($paginator, $last, $page, $pageUrl, $itemsUrl) {
            $item['lightbox']['page'] = $pageUrl;

            if ($paginator instanceof LengthAwarePaginator) {
                $item['lightbox']['position'] = ($paginator->firstItem() ?? 1) + $i;
                $item['lightbox']['total'] = $paginator->total();
            }

            if ($i === 0 && $paginator->previousPageUrl()) {
                $item['lightbox']['previousPage'] = $paginator->previousPageUrl().'#photo-last';
                $item['lightbox']['previousItems'] = $itemsUrl($page - 1);
            }

            if ($i === $last && $paginator->nextPageUrl()) {
                $item['lightbox']['nextPage'] = $paginator->nextPageUrl().'#photo-first';
                $item['lightbox']['nextItems'] = $itemsUrl($page + 1);
            }

            return $item;
        });
    }
}
