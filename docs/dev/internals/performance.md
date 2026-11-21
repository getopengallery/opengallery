---
order: 70
---

# Speed

OpenGallery runs on shared hosting, so speed comes from doing less per request, not from extra servers. This page explains the mechanisms and the rules for new code.

## Work once per request

- **Settings**: `Setting::get()` reads all settings from the cache store once per request (`Cache::memo()`), see [Site settings internals](../architecture/site-settings.md).
- **`App\Support\RequestMemo`**: a scoped instance for values that are worked out once and reused during the request: the albums shared with a person (`Album::idsSharedWith()`), trash counts (`TrashActions::count()`), the pages linked in menus (`Page::linkable()`) and the order of images on the page (`ImageLoading`). It starts empty for every HTTP request (`RequestHandled`), queued job and test, and is never written to the cache, so it may hold access decisions. Whoever changes what a value depends on calls `forget($prefix)`; `AppServiceProvider` and the models do this for shares, group members, the trash and pages.

Rules: never cache an access decision across requests (a revoked share must count at once), and never use `once()` for request state (it survives between queued jobs).

## Photo files

Photos, profile pictures, branding and theme images are served by the routes in `routes/files.php`, not by the `web` stack (`bootstrap/app.php`):

| Group | Middleware | Routes |
| --- | --- | --- |
| `files` | `EncryptCookies`, `ReadOnlySession`, `SubstituteBindings`, `SetLocale`, `EnsureAccountIsActive` | `media.show`, `pages.photo`, `avatars.show`, `docs.asset` |
| `static` | `SubstituteBindings` | `site.asset`, `theme.upload`, `theme.asset`, `web-app.*`, `search.robots`, `search.sitemap`, `albums.shared.cover`, `albums.cover` |

`App\Http\Middleware\ReadOnlySession` reads the visitor's session (who is logged in, which albums they unlocked with a share link or password) but never saves it: no session row is written or created and no cookie or CSRF token is sent. A "remember me" cookie is ignored there; the next page view signs the person in again. It sits in the middleware priority list after `StartSession`, so `auth` on these routes sees the session. Access checks stay in the controllers.

With `config('gallery.sendfile')` set (`GALLERY_SENDFILE=apache|nginx`), `PhotoFileResponse` returns an empty response with `X-Sendfile` (absolute path) or `X-Accel-Redirect` (`gallery.sendfile_nginx_prefix` + path below `storage/`) instead of streaming, only for files inside `storage/`. `App\Support\PhotoFileResponse` sends the file after those checks: `Cache-Control: private, max-age=86400`, `ETag` and `Last-Modified`, and `304 Not Modified` for an unchanged file. See [Media library](media-library.md#serving-photos).

## Photo sizes

Each photo has three WebP conversions (`Photo::CONVERSION_SIZES`): `preview` (1920 px), `thumb` (720 px) and `small` (360 px). `preview` is made first and `App\Support\ConversionsFromPreview` (the media library's image generator) starts `thumb` and `small` from it, so the original is decoded once per upload. Blocks offer `small` and `thumb` in a `srcset` (`App\Support\Srcset`), see [Blocks](../extending/blocks.md#loading-photos). Photos uploaded before `small` existed get it from the background jobs (`App\Support\SmallThumbnails`, made from their `thumb`).

## Production caches

Live sites (`APP_ENV=production`) keep Laravel's configuration, route and event caches and Filament's component and icon caches built without anyone running a command (`App\Support\ProductionCaches`, run by the global `KeepProductionCachesFresh` middleware):

- A request without caches builds them after the response is sent. While building fails (for example because `bootstrap/cache` is not writable), it is tried at most once an hour and **Site health** reports it.
- `config('gallery.code_fingerprint')` records the dates of `.env`, `bootstrap/app.php`, the route and configuration files, the installed packages, the admin page folders and the plugins when the configuration cache is built. A request whose live fingerprint differs drops the caches at once and rebuilds them, so uploading a new release or editing `.env` needs no command.
- Enabling or disabling a plugin (`PluginManager::enable()` / `disable()`) drops the caches and rebuilds Laravel's after the response. Filament's are cached by the next request, because the admin panel of the current request still has the old plugins.
- The configuration cache holds only what the files say. While it is built, `AppServiceProvider::boot()` skips `SiteSettings::apply()` and the theme's view paths (`ProductionCaches::buildingConfigCache()`), so no decrypted secret and no theme folder is written into `bootstrap/cache/config.php`. Stored settings are applied on every request as before.
- Compiled views are left to Blade (compiled on first use and kept).

`GALLERY_OPTIMIZE=false` turns the automatic caches off. Development (`APP_ENV=local`) never builds them; caches you build by hand there are still dropped when the files change. Filament's and Blade Icons' Artisan commands only exist on the command line, so `ProductionCaches` calls their APIs directly, and it puts the request's application back after `config:cache` (which boots a fresh one).

Rules: no `env()` outside `config/`, no closures in configuration files, and nothing in a plugin's `register()` or `boot()` that only works without the route cache.

## SQLite

MySQL and MariaDB index every foreign key by themselves; on SQLite the migration `add_foreign_key_indexes_for_sqlite` adds the indexes the gallery looks things up by (`photos.uploader_id`, `albums.parent_id`, `albums.cover_photo_id`, `activity_events.user_id` and `album_id`). It does nothing on other databases.

SQLite allows one writer at a time, and every request writes its session. `config/database.php` therefore sets `busy_timeout` to 10 seconds and `transaction_mode` to `IMMEDIATE`, so requests wait for the lock instead of failing with "database is locked". Keep slow work such as image processing out of transactions (see [Photo uploads](uploads.md#making-a-photo)). The journal mode stays at SQLite's default: WAL is not safe on the network file systems some shared hosts use.

## Loading pages

The first `ImageLoading::EAGER` (6) photos of a page load straight away, the first with `fetchpriority="high"`; the rest load lazily. `public/.htaccess` compresses text and lets browsers keep the hashed files in `public/build/assets` for a year (`immutable`) and Filament's files for a week. See [Speed](../../user/getting-started/installation.md#speed) in the installation guide.
