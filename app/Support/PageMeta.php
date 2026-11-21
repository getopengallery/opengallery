<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Page;
use App\Models\Photo;
use Illuminate\Support\Str;

/**
 * What a site page tells search engines and link previews (messengers,
 * social networks, chat apps): title, description, preview image, canonical
 * address and whether it may be indexed. Rendered by
 * resources/views/partials/page-meta.blade.php in both site layouts; views
 * pass it as `<x-app-layout :meta="$meta">`. Without one, a page describes
 * the site itself (site()).
 *
 * Link previews are fetched by servers without the visitor's cookies, so a
 * preview image only uses addresses that work for anyone holding the link:
 * the cover of an album guests may open (media.show), a blurred card of the
 * cover for unlisted and password-protected albums (albums.shared.cover,
 * albums.cover; AlbumPreviewImage) and photos placed on a public page
 * (pages.photo). Everything else falls back to the app icon, so a preview
 * never reveals a photo the link alone would not show.
 */
final class PageMeta
{
    /** Longest description, in characters (search results cut off around here). */
    public const DESCRIPTION_LENGTH = 200;

    /** Conversion used as preview image: large enough for big link cards, small enough for messengers. */
    public const IMAGE_CONVERSION = 'thumb';

    /**
     * @param  array{url: string, width: ?int, height: ?int, type: string, alt: string}|null  $image  null: the app icon
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?array $image = null,
        public readonly ?string $url = null,
        public readonly bool $indexable = true,
        public readonly string $type = 'website',
    ) {}

    /** The site itself (start page, landing page and every page without its own). */
    public static function site(): self
    {
        return new self(description: SiteSettings::description());
    }

    /**
     * An album's page, opened directly or through its share link ($shareToken).
     * Albums guests may open show their cover; an unlisted album at its share
     * link shows the blurred cover with a link badge (AlbumPreviewImage).
     * Only albums guests may open are indexable: an unlisted album's page
     * stays out of search engines.
     */
    public static function album(Album $album, int $photoCount, ?string $shareToken = null): self
    {
        $openToGuests = ! $album->hasPassword() && $album->isVisibleTo(null);
        $image = null;

        if ($openToGuests) {
            $cover = self::coverOf($album);
            $media = $cover?->original();

            if ($media && $media->hasGeneratedConversion(self::IMAGE_CONVERSION)) {
                $image = self::image(route('media.show', [$media, self::IMAGE_CONVERSION]), $cover, $album->title);
            }
        } elseif (self::validShareToken($album, $shareToken)) {
            $image = self::card(route('albums.shared.cover', $shareToken), $album);
        }

        return new self(
            title: $album->title,
            description: self::excerpt($album->description) ?? trans_choice(':count photo|:count photos', $photoCount),
            image: $image,
            url: $shareToken !== null ? route('albums.shared', $shareToken) : route('albums.show', $album),
            indexable: $openToGuests && $shareToken === null,
        );
    }

    /**
     * The password form of a protected album: its title and, for visitors
     * who could open it with the password, its cover blurred beyond
     * recognition with a lock badge (AlbumPreviewImage).
     */
    public static function protectedAlbum(Album $album, ?string $shareToken = null): self
    {
        $image = match (true) {
            self::validShareToken($album, $shareToken) => self::card(route('albums.shared.cover', $shareToken), $album),
            $album->isVisibleTo(null) => self::card(route('albums.cover', $album), $album),
            default => null,
        };

        return new self(
            title: $album->title,
            description: __('This album is protected. Enter its password to see the photos.'),
            image: $image,
            url: $shareToken !== null ? route('albums.shared', $shareToken) : route('albums.show', $album),
            indexable: false,
        );
    }

    /** A page (`/pages/{slug}`): its text and the first photo placed on it. */
    public static function page(Page $page): self
    {
        $image = null;

        foreach ($page->embeddedPhotoIds() as $photoId) {
            $photo = Photo::query()->with('media')->find($photoId);
            $media = $photo?->original();

            if ($media && $media->hasGeneratedConversion(self::IMAGE_CONVERSION)) {
                $image = self::image(route('pages.photo', [$page, $photo, self::IMAGE_CONVERSION]), $photo, $page->title);

                break;
            }
        }

        return new self(
            title: $page->title,
            description: self::excerpt($page->body) ?? SiteSettings::description(),
            image: $image,
            url: route('pages.show', $page),
            indexable: ! $page->members_only,
            type: 'article',
        );
    }

    /** A page nobody should find through a search engine (errors, expired links, no access). */
    public static function hidden(): self
    {
        return new self(description: SiteSettings::description(), indexable: false);
    }

    /** Whether search engines may index the page: the page allows it and the site does (Admin → Settings). */
    public function allowsIndexing(): bool
    {
        return $this->indexable && SiteSettings::searchIndexing();
    }

    /** Plain text from a description or page body, cut at DESCRIPTION_LENGTH; null if there is none. */
    public static function excerpt(?string $text): ?string
    {
        // A space after line breaks and block ends, so paragraphs don't run into each other.
        $spaced = (string) preg_replace('~<(?:br|/(?:p|div|li|h[1-6]|blockquote|figcaption|td|th))\b[^>]*>~i', '$0 ', (string) $text);
        $plain = Str::of(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5))->squish();

        return $plain->isEmpty() ? null : $plain->limit(self::DESCRIPTION_LENGTH, '…', preserveWords: true)->toString();
    }

    private static function validShareToken(Album $album, ?string $shareToken): bool
    {
        return $shareToken !== null && $album->share_token === $shareToken && ! $album->accessExpired();
    }

    /**
     * A preview card (AlbumPreviewImage) at $url, if the album has a cover to make it from.
     *
     * @return array{url: string, width: ?int, height: ?int, type: string, alt: string}|null
     */
    private static function card(string $url, Album $album): ?array
    {
        $media = self::coverOf($album)?->original();

        if (! $media || ! $media->hasGeneratedConversion(self::IMAGE_CONVERSION)) {
            return null;
        }

        return ['url' => $url, 'width' => AlbumPreviewImage::WIDTH, 'height' => AlbumPreviewImage::HEIGHT, 'type' => 'image/jpeg', 'alt' => $album->title];
    }

    /** The album's cover, or its first photo when it has none. */
    public static function coverOf(Album $album): ?Photo
    {
        return $album->coverPhoto()->with('media')->first() ?? $album->photos()->with('media')->first();
    }

    /**
     * @return array{url: string, width: ?int, height: ?int, type: string, alt: string}
     */
    private static function image(string $url, Photo $photo, string $alt): array
    {
        $width = $height = null;

        if ($photo->width && $photo->height) {
            // Conversions fit within the size and are never enlarged (see Photo::registerMediaConversions()).
            $scale = min(1, Photo::CONVERSION_SIZES[self::IMAGE_CONVERSION] / max($photo->width, $photo->height));
            $width = (int) round($photo->width * $scale);
            $height = (int) round($photo->height * $scale);
        }

        return ['url' => $url, 'width' => $width, 'height' => $height, 'type' => 'image/webp', 'alt' => $photo->title ?: $alt];
    }
}
