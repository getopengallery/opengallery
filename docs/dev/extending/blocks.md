---
order: 30
---

# Blocks

Every visitor-facing page is built from blocks: small, named Blade views such as the header, an album grid or the photo viewer. Themes replace blocks or add layouts (variants) for them, so a theme can change a whole section of the site without copying page views, and keeps working when core pages change.

## Using a block

Render a block with the `<x-block>` component (`App\View\Components\Block`):

```blade
<x-block name="album-grid" :albums="$albums" />

<x-block name="empty-state" :message="__('This album has no photos yet.')">
    <a href="{{ route('home') }}" class="og-link">{{ __('Back to albums') }}</a>
</x-block>
```

Every attribute is passed to the block's view as a camel-cased variable (`on-home` becomes `$onHome`), and the component's content as `$slot`. Text props arrive as the original, unescaped text, so print them with `{{ $message }}`, never with `{!! !!}`. The component asks `ThemeManager::blockView()` which view to render.

## The blocks

| Block | Props | Where |
| --- | --- | --- |
| `site-header` | none | Every site page |
| `site-footer` | none | Every site page |
| `page-header` | slot | Title area of a page |
| `landing` | `onHome` (optional) | Home page for guests while public albums are off; with `onHome` above the album list (buttons lead to `#albums`) |
| `auth-shell` | slot | Frame around login, registration and password pages |
| `album-grid` | `albums` | Album lists |
| `album-search` | `query` | Search form above the album list (`GET /?q=`) |
| `album-tile` | `album` | One album in a grid |
| `photo-grid` | `photos` | Photos of an album (use `App\Support\PhotoGridItems::from($photos)`) |
| `lightbox` | none | Full-screen photo viewer, inside an `ogLightbox` Alpine scope |
| `empty-state` | `message`, slot | Empty lists |
| `pagination` | `paginator`, `elements` | Every `->links()` call |
| `page-content` | `page` | Body of a content page |
| `page-photo` | `src`, `alt`, `caption`, `wide`, `ratio` | Photo in page content |
| `page-gallery` | `items`, `layout`, `columns`, `caption`, `wide` | Gallery in page content |

Shared pieces used by the header layouts live in `resources/views/blocks/partials` (`account`, `menu-button`, `menu-panel`, `landing-copy`). They can be overridden like any view. The links themselves come from `App\Support\Navigation::links()`; the admin link is not among them but in the account menu (`account`, `menu-panel`) under Profile, via `Navigation::adminUrl()` (null for people who may not open the admin).

## Variants

`ThemeManager::blockView()` decides which view renders a block:

1. A settings field with `"block": "{name}"` picks the variant (its saved value or default).
2. Otherwise `blocks.{name}` in `theme.json` picks it.
3. If `views/blocks/{name}/{variant}.blade.php` exists (in the theme, its parent or core), it is used.
4. Otherwise `blocks/{name}.blade.php` is used: the theme's own copy if it has one, else the core fallback.
5. If there is no `blocks/{name}.blade.php` anywhere, `blocks/{name}/default.blade.php` is used.

Views are looked up through the theme chain like every other view: the active theme first, then its parents, then `resources/views`. A theme therefore replaces a block by shipping `views/blocks/{name}.blade.php`, and adds a variant by shipping `views/blocks/{name}/{variant}.blade.php`.

An unknown or missing variant therefore falls back to the default layout instead of breaking the page. Core ships one plain layout per block. Every other layout belongs to a theme. The Default theme ships these:

| Block | Variants in the Default theme |
| --- | --- |
| `site-header` | `centered`, `minimal` |
| `site-footer` | `columns`, `none` |
| `landing` | `split`, `cover` |
| `auth-shell` | `split`, `cover` |
| `album-grid` | `large` |
| `photo-grid` | `square` |

A header layout of your own should keep the shared parts the core layouts use: `<x-notification-bell />` (the notifications bell for logged-in visitors, see [Notifications and e-mails](../internals/emails.md#notifications-in-the-gallery)), `<x-theme-mode-toggle />`, and the partials `blocks.partials.account`, `blocks.partials.menu-button` and `blocks.partials.menu-panel`.

A theme with a `parent` of `default` can use them all. A theme without that parent only has what it ships itself plus the core fallbacks. The Scrapbook theme has no parent: it replaces `site-header`, `site-footer`, `page-header`, `landing`, `auth-shell`, `album-grid`, `album-tile`, `photo-grid` and `empty-state` with its own `blocks/{name}.blade.php` files and adds one variant, `photo-grid/polaroids`.

The Default theme also overrides one page view: `views/albums/index.blade.php` puts the `landing` block (with `onHome`) above the album list on its first page, controlled by its `landing_on_home` setting (`never`, `guests`, `everyone`). Child themes of `default` inherit it; a theme that ships its own `albums/index.blade.php` replaces it.

## Pagination

`ThemeManager::boot()` sets `Paginator::defaultView()` to the view `blockView('pagination')` returns, so every `->links()` call on the site renders the `pagination` block of the active theme. It receives Laravel's usual `$paginator` and `$elements`.

## Photo grids

The `photo-grid` block expects the items `App\Support\PhotoGridItems::from($photos)` builds, not photo models. Each item is an array with the photo's aspect `ratio`, the `thumb` URL and a `lightbox` entry, all URLs built with `route('media.show', ...)`. The `lightbox` entry holds `id`, `src` (the `preview` URL), `title`, `description`, `taken` (the date taken, formatted for the current language, or null) and `download` (the original's download URL when the photos come from an album with `allow_downloads`, read from the pivot's `pivotParent`; otherwise null). For a paginated list it also has `position` and `total` (the photo's place in the whole list), `page` (the address of the page the photo is on), the first item `previousPage` and the last item `nextPage` (page addresses ending in `#photo-last` / `#photo-first`). For an album's photos (`Album::photos()`, read from the pivot's `pivotParent`) the first / last item also have `previousItems` / `nextItems`: the address of the neighbouring page's `lightbox` entries as JSON (route `albums.photos`, same access checks as the album page, not logged as an album view). Pass the whole `lightbox` entries to `ogLightbox(...)` unchanged: the lightbox uses them for the counter, the caption, links to single photos (`#photo-{id}`) and going on to the neighbouring page. It loads `previousItems` / `nextItems` in the background and adds them to the open viewer, so it never reloads while stepping through an album; only without them (or when loading fails) it opens `previousPage` / `nextPage` instead. Closing the viewer on a photo of another page opens that page. Photos without a file are left out. Keep it that way in theme layouts: never link to storage paths.

### Loading photos

Each item also carries `loading`, `fetchpriority` and `decoding`: the `<img>` attributes for the photo's place on the page. Print them as they are:

```blade
<img src="{{ $item['thumb'] }}" alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}">
```

Items also carry `srcset`: the `small` (360 px) and `thumb` (720 px) addresses with their real widths, or null while `small` is not made yet. Print it with a `sizes` that describes how wide the photo is shown in your layout, so phones get the small file:

```blade
<img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 1024px) 17vw, (min-width: 640px) 25vw, 34vw" @endif alt="...">
```

`album-tile` builds the same for the cover with `App\Support\Srcset::for($album->coverPhoto, [...])`, and `page-photo` gets a `srcset` prop with `thumb` and `preview`.

The loading attributes come from `App\Support\ImageLoading::next()`, which counts the photos of a request in the order they are rendered: the first `ImageLoading::EAGER` (6) load straight away (`eager`), the very first with `fetchpriority="high"` because it is usually the largest element on screen, and the rest load when scrolled to (`lazy`, decoded with `async`). Blocks that show other photos ask for the next attributes themselves, once per image and only for images they actually print: `album-tile` (for a cover) and `page-photo` call `@php($image = \App\Support\ImageLoading::next())`, and `page-gallery` items carry them like grid items. A theme's own block that shows photos should do the same, so the first photos of every page appear without delay.

## Adding a block to core

Put any new reusable page section in a block instead of inline in a page:

1. Create `resources/views/blocks/{name}.blade.php` with a plain layout that works in every theme. Style it with the `og-*` classes and tokens only.
2. Render it with `<x-block name="{name}" ... />` and document its props in the table above.
3. If a theme should be able to choose between layouts, ship the extra layouts in a theme (`themes/default/views/blocks/{name}/{variant}.blade.php`) and add a `layout` field with `"block": "{name}"` to that theme's settings. Core ships only the plain fallback.
4. Check that the Scrapbook theme, which replaces most blocks and has no parent, still renders the page (`tests/Feature/ScrapbookThemeTest.php`, `tests/Feature/ThemeSeamsTest.php`).
