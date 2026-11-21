---
order: 90
---

# Search engines and link previews

What the site tells search engines and the apps that build link previews (messengers, chat apps, social networks). Admins control it under **Search engines** in Admin → Settings (see [Site settings](../architecture/site-settings.md#keys)).

## Pieces

| Piece | Where | What |
| --- | --- | --- |
| Page details | `App\Support\PageMeta` | Title, description, preview image, canonical address, `indexable`, `og:type` for one page |
| Head tags | `resources/views/partials/page-meta.blade.php` | `description`, `robots`, `canonical`, Open Graph (`og:*`), `twitter:card` and the Google / Bing verification tags. Included by `layouts.app` and `layouts.guest` |
| robots.txt | `/robots.txt` (`search.robots`, `SearchEngineController@robots`) | Built by `SearchEngines::robotsTxt()` |
| Sitemap | `/sitemap.xml` (`search.sitemap`, view `search.sitemap`) | `SearchEngines::sitemapEntries()`; 404 while indexing is off |
| Preview cards | `App\Support\AlbumPreviewImage` | Blurred cover with a link or lock badge for unlisted and password-protected albums |
| Card routes | `/s/{token}/cover` (`albums.shared.cover`, `AlbumController@sharedCover`), `/albums/{album}/cover` (`albums.cover`, `@protectedCover`) | The card of an unlisted album's share link (link badge, or lock with a password), and of a password-protected album guests could open with the password |

All these routes are in the `static` middleware group (`routes/files.php`): no session, the same answer for every visitor. There is no static `public/robots.txt`; `gallery:install` removes the one older releases shipped (`SearchEngines::removeLegacyRobotsFile()`), unless it was edited.

## Page details

Views hand a `PageMeta` to the layout component: `<x-app-layout :meta="$meta">` or `<x-guest-layout :meta="$meta">` (`AppLayout` / `GuestLayout` take it as a constructor argument). Without one, the app layout describes the site (`PageMeta::site()`: site description, indexable) and the guest layout is hidden from search engines (`PageMeta::hidden()`), so login, password and error pages are never indexed.

| Builder | Used by | Indexable |
| --- | --- | --- |
| `site()` | Start page, landing page and app-layout pages without their own | Yes |
| `album($album, $photoCount, $shareToken)` | `AlbumController@show` and `@shared` | Only albums guests may open without a password, and not at a share link |
| `protectedAlbum($album, $shareToken)` | The password form | No |
| `page($page)` | `PageController@show` | Unless members only |
| `hidden()` | Profile, "My albums", search results, guest layout default | No |

`allowsIndexing()` combines the page's own answer with `SiteSettings::searchIndexing()`: with indexing off, every page says `noindex, nofollow`. Indexable pages say `max-image-preview:large` and get a canonical link (with `?page=N` from the second page of a list on).

Descriptions come from `PageMeta::excerpt()`: plain text of the album description or page body, cut at `PageMeta::DESCRIPTION_LENGTH` (200) characters on a word boundary. Without one, albums say how many photos they have and pages use the site description.

## Preview images

Preview apps fetch the image from their own servers, without the visitor's cookies. A preview image is therefore only an address that works for anyone holding the page's link, and never reveals more than the link itself would:

- an album guests may open without a password: `media.show` of its cover (`PageMeta::coverOf()`: the cover, else the first photo);
- an unlisted album opened at its share link: `albums.shared.cover`, a preview card with a link badge (a lock if it has a password). The token is the key, as for the page; the route refuses albums past their end date;
- a password-protected album guests could open with the password (`isVisibleTo(null)`): `albums.cover`, a preview card with a lock badge;
- a page: `pages.photo` of the first photo placed on it (`Page::embeddedPhotoIds()`), which only works while the page is visible to guests;
- anything else: the 512 px app icon (`WebApp::iconUrl(512)`), with a small `summary` card instead of `summary_large_image`.

Preview cards (`AlbumPreviewImage::path($album, $badge)`) are 1200 × 630 JPEGs made with GD from the cover's `thumb` conversion: the middle of the cover in that shape, shrunk to `BLUR_WIDTH` pixels (48 for the link badge, 12 for the lock, so a protected album only shows colours) and enlarged again in steps with a Gaussian blur at each, darkened slightly, with a soft shadow and the badge. The badge copies the password page: a circle in the theme's `--og-surface` with the icon in `--og-ink` (light-mode values from `ThemeManager::resolvedVariables()`), the icons as 20-unit outline paths with a 1.6 stroke (`AlbumPreviewImage::ICONS`, drawn by stamping a round brush along the path at twice the size, then scaled down). Cards are kept on the private `local` disk under `link-previews/`, named after the album, the badge and a fingerprint of the cover file, the theme colours and `VERSION`; making a new card removes the album's older ones, and `AlbumDeletion::forceDelete()` calls `AlbumPreviewImage::forget()`. Raise `VERSION` after changing how cards look.

Photos use the `thumb` conversion (`PageMeta::IMAGE_CONVERSION`, WebP, at most 720 px), small enough for messengers that refuse large images. Width and height are worked out from the photo's stored size.

## Share links

`/s/{token}` unlocks the album for the session (`AlbumAccess::unlock()`) and renders the album page at that address (`AlbumController@shared` calls `show()` with the token) instead of redirecting to `/albums/{slug}`. Preview apps that do not keep cookies across redirects then still see the album's tags, and the address bar keeps an address that works for others. Pagination on that page stays on `/s/{token}?page=N`; the password form still posts to `albums.unlock` and continues at `/albums/{slug}`, where the session unlock applies.

## Sitemap and robots.txt

The sitemap lists the start page (while public albums are on or guests get the landing page), albums from `Album::listableFor(null)` without a password and not system albums, and published pages that are not members only, each with `lastmod` from `updated_at`, at most `SearchEngines::SITEMAP_LIMIT` (50,000) addresses. Unlisted albums never appear.

`SearchEngines::robotsTxt(?bool $indexing, ?bool $blockAi, ?string $extra)` writes the file from the saved settings, or from the values given (the settings page shows a live preview that way). With indexing on, it disallows everything for the crawlers in `SearchEngines::AI_CRAWLERS` while `SiteSettings::blocksAiCrawlers()` is on (default), disallows `SearchEngines::PRIVATE_PATHS` (admin, profile, "My albums", uploads, background jobs) for everyone else and names the sitemap; indexable pages then also say `noai, noimageai` in their `robots` meta tag. Share links are not disallowed: some preview apps obey robots.txt, and unlisted pages already say `noindex`. With indexing off, it disallows everything for `*` but allows the crawlers in `SearchEngines::PREVIEW_CRAWLERS`, so link previews keep working. The admin's own lines (`SiteSettings::robotsExtra()`) come after the generated groups, before the sitemap.

## Themes

A theme that overrides a page view should keep passing `:meta` to the layout (`albums/show`, `albums/password`, `pages/show`), or that page falls back to the site's details. A theme that replaces a whole layout should include `partials.page-meta` in its `<head>`, like core's layouts do.
