<laravel-boost-guidelines>
=== .ai/documentation rules ===

# Documentation (`/docs`)

`/docs` is a living, in-repo documentation set and part of the deliverable for every change — not optional. This overrides the general rule to create documentation files only when asked: pages under `/docs` are always kept up to date (new ones included); outside `/docs`, create documentation files only when asked. After implementing and testing any behavior change, proactively update `/docs` before finishing. The docs are rendered in-app from these Markdown files, so they must be clean, valid Markdown with sane syntax.

## Audience split (two folders only)
- **`/docs/user/`** — everything an end user or system admin does through the running application: installing/upgrading, Admin → Settings, albums, photos, sharing (unlisted links, passwords, expiry), profiles/avatars, themes as seen in the UI, pages, permissions as configured in the admin, activity log settings. Plain language, no code, no internals. Admin-level operations belong here, not in `dev/`.
- **`/docs/dev/`** — everything a developer touches to customize or extend the system: plugin and theme authoring (`plugin.json`, `theme.json`, extension points, events, `LoginOptions`, `PluginSettings`), frontend build (`public/build`, Vite, Node version), architecture of the access model, extension contract, upgrade notes for developers. Code-level detail is fine here.

Do not create a third audience folder. If something is unclear, decide by *who acts on it*: an admin clicking through the UI → `user/`; a developer writing code or JSON → `dev/`.

## Structure and order
- Inside `user/` and `dev/`, pages live in topic sub-folders (e.g. `user/content/albums.md`, `dev/extending/themes.md`). Each sub-folder has a `README.md`: its `#` heading is the folder's label in the navigation and it lists the folder's pages for readers.
- Order is never hard-coded and never part of a file name. Every page except `docs/README.md` starts with front matter, before the `#` heading:
  ```markdown
  ---
  order: 20
  ---
  ```
  Pages sort by `order` within their folder (steps of 10, so new pages fit in between); a folder sorts by its README's `order`.
- Add a sub-folder only for a group of related pages a reader would look for together; keep to one level inside each guide.

## Markdown & rendering rules
The docs are rendered by the application, so:
- Write **clean, valid CommonMark Markdown**. One `#` H1 per file (the title), then `##`/`###` for structure. Do not skip heading levels.
- **No raw HTML** unless truly unavoidable; prefer Markdown. If you must use HTML, keep it minimal and well-formed.
- Use **fenced code blocks with a language tag** (```bash, ```php, ```json, ```blade). No indented code blocks.
- Use **relative links between docs** (e.g. `../administration/settings.md#albums`), not absolute URLs, so they work in-app. `DocumentationTest` fails on broken links, anchors and images; when you move a page or rename a heading, fix its inbound links in the same change.
- Keep tables simple (no nested block content inside cells). Use lists for anything complex.
- Don't rely on the frontend build for docs: no `<script>`, no inline styles, no `<style>` blocks.
- Prefer plain, unicode-safe text; avoid exotic characters that may not render in every font.

## Screenshots
Screenshots are encouraged to illustrate UI steps, especially in `user/` docs.
- Store images under **`/docs/assets/`** (shared) — e.g. `docs/assets/albums-upload.png`. Create the folder if missing.
- Reference with Markdown, relative to the current doc: from `docs/user/content/albums.md` that is `![Uploading photos](../../assets/albums-upload.png)`.
- Use descriptive alt text (what the user should see / click). Alt text is what appears if the image fails to render.
- Filenames: lowercase, hyphenated, `<area>-<action>.png`. Never spaces, never non-ASCII.
- You cannot take screenshots yourself. When a screenshot would clearly help but none exists, **insert a placeholder** and flag it in your summary:
  `![Upload dialog](../../assets/albums-upload.png) <!-- TODO: screenshot of the upload dialog -->` (only images with such a TODO on the same line may point to a missing file)
  Do not invent image files or reference remote URLs.
- Keep screenshots up to date: if a UI change makes a screenshot misleading, flag the affected image(s) in your summary for re-capture rather than silently leaving it.

## When to update
- User/admin-visible change (settings, permissions, routes, share links, UI, install steps) → update `docs/user/`.
- Developer-facing change (new enum value, new extension point, new required config, changed access logic, new plugin hook, build/queue requirement, `theme.json`/`plugin.json` field) → update `docs/dev/`.
- Both kinds in one change → update both.
- Bug fix with no behavior/doc impact → no doc change; say so in your summary.
- Pure copy/styling change → usually no doc change; use judgement, but flag stale screenshots.

## How to update
- First read `docs/README.md` and the README of the sub-folder for the area you touched, then the pages it links.
- Prefer editing existing files in place. Create a new file only when there is no sensible home; give it an `order` and link it from its folder's `README.md` (and from `docs/README.md` when you add a sub-folder).
- Match the tone/structure of neighbouring docs. `user/` docs must stay free of class names, file paths, and internals.
- Keep docs accurate: rewrite or remove stale statements rather than appending contradictory notes. Update examples (config keys, commands, UI labels) that no longer match.
- Reference the real source of truth (`App\Enums\Permission`, `SiteSettings` keys, `config/gallery.*`, route names, `theme.json`/`plugin.json` fields) so devs can trace it.
- Do not invent features. Only document what exists in the codebase after your change.

## Reporting
In your final summary, list:
- which doc files you added/updated and why;
- any screenshot placeholders you added (`<!-- TODO: screenshot ... -->`) that need real captures;
- any existing screenshots made stale by the change;
- if you skipped a doc update, why (e.g. "no user- or developer-facing change").

=== .ai/opengallery rules ===

# OpenGallery project guidelines

Self-hosted photo gallery (Laravel 13, Filament 5, Spatie Permission + Media Library, Blade/Alpine/Tailwind v3).

## Hard constraints

- Must run on plain shared PHP hosting: PHP 8.3+, MySQL/MariaDB, local filesystem. No Docker, Redis, Node at runtime, or required queue worker / websockets. Anything heavier must be optional (config flag) or a plugin.
- Built frontend assets (`public/build`) ship with releases; end users never run npm. Release zips come from `php artisan gallery:release` (run by `.github/workflows/release.yml` on `v*` tags); dev-only files go in `.gitattributes` `export-ignore`. The root `VERSION` file is the only version source (`App\Support\AppVersion`); the release command stamps it. `config.platform.php` keeps the lock file installable on PHP 8.3. Update checks (`App\Support\UpdateCheck`, Admin → System → Updates, channels in `App\Enums\UpdateChannel`) report new GitHub releases, are off until an admin chooses them and send nothing about the gallery. One-click updates (`App\Support\Updates`, standalone `resources/updater/runner.php`, developer reference `docs/dev/internals/updates.md`) install only releases with a valid channel signature; the runner must stay dependency-free, and paths that must survive an update belong in `ReleasePlan`. Vite 8 needs Node ^20.19 or >=22.12 (see `.nvmrc`).

## Access model (read before touching albums/photos/media)

- Site-wide capabilities = Spatie roles/permissions, listed in `App\Enums\Permission` and seeded by `RolesAndPermissionsSeeder` (idempotent; add new permissions to the enum, incl. `grouped()` / `label()` for the role form, and re-seed). `super-admin` bypasses all checks via `Gate::before`.
- Per-album access = `Album::$visibility` (`App\Enums\Visibility`) + explicit shares (`album_user`, `album_group`). Logic lives in `Album::isVisibleTo()` / `Album::scopeListableFor()` / `AlbumPolicy`. Keep those three consistent. Photos inherit access from their album.
- Unlisted albums: `/s/{share_token}` unlocks the album for the session (`App\Support\AlbumAccess`).
- Albums: tree order via `albums.tree_path` (maintained in `Album::refreshTreePath()`, cascades to sub-albums; never set by hand). Album order among siblings (`sort_order`) is changed only by drag and drop in the albums list (`ListAlbums::reorderTable`, siblings only, staff with "edit any album"). Photo order per album: `albums.photo_order` (`App\Enums\PhotoOrder`), applied in `Album::photos()`. Access: optional `password` (hashed) and `access_expires_at`; `Album::hasDirectAccess()` (owner, staff, direct shares) bypasses both. Visitors go through `App\Support\AlbumAccess` (session unlocks; a password change invalidates them). Keep `isVisibleTo`, `scopeListableFor`, `AlbumAccess` and `MediaController` consistent.
- Photo files live on the private `gallery` disk and MUST only be served via `MediaController` (`route('media.show', [$media, 'thumb'|'preview'])`). Never expose them through `storage:link` or public URLs.
- Photos ↔ albums is many-to-many (`album_photo`, pivot `App\Models\Pivots\AlbumPhoto`, order in `sort_order`). A photo is visible if any of its non-trashed albums is; add with `Album::addPhotos()` (no log; uploads are logged per batch) or `$photo->albums()->attach()` (logged as "Albums: +X"). Covers are kept valid by the pivot and `Photo` events. Every photo stays in at least one album: delete albums permanently only with `App\Support\AlbumDeletion::forceDelete()` and users with `App\Support\AccountDeletion` (`albums.owner_id` is `nullOnDelete`).
- Media library: `App\Filament\Resources\Photos` (slug `media`, list/grid layout in `ListPhotos::$mediaLayout`). Always scope photo queries for staff with `Photo::visibleTo($user)`; `PhotoPolicy`: edit = uploader or manager of any album, trash = uploader or manager of all its albums. Photos soft-delete (trash); `Photo::albums()` includes trashed albums. Album covers are kept valid by `Photo` model events (delete/restore/move); use `updateQuietly` for automatic cover changes. `MediaController` only serves trashed photos (or photos in trashed albums) to users who may update them.
- Choosing photos anywhere in the admin goes through ONE media pool picker: `App\Livewire\MediaPicker` (selection only; dispatches browser event `media-picked` {key, items}), opened by `App\Filament\Actions\PickMediaAction` (config: pickerKey, multiple, confirmLabel, scopeAlbum(id, 'only'|'exclude'), uploadTo('album:{id}'|'inbox'|'page-images'), onPicked(js with `ids`/`items`)). In forms use `App\Filament\Forms\Components\MediaPickerField` (state: id or list of ids). Never build another photo chooser. Uploads made in the picker land in that album or the uploader's own private system albums "Unsorted uploads" (`Album::inbox`, photos leave it when added to any other album, via `AlbumPhoto::created`) / "Page images" (`Album::pageImages`), marked by `albums.system_album` (never by slug). Consumers must re-check picked ids with `Photo::visibleTo()` on the server; adding photos to an album or using them as a cover also needs `Photo::reusableBy()` (only photos the person may edit).
- Lists of users on a record (group members, role holders) use `App\Filament\RelationManagers\UsersRelationManager` (searchable, paginated, attach by search; never preload all users or use a multi-select for members) and, in tables, `App\Filament\Tables\Columns\UserStackColumn` with `UserStackColumn::withUsers($query)` (loads only the first few users per row).
- Filament closures receive values by parameter *name*: use `$query`, `$data`, `$record`, `$state`, `$get`. A differently named Builder parameter (e.g. `$q`) gets a new, unrelated query and the filter silently does nothing.
- Single sign-on lives in the `identity-providers` plugin, not in core. Core only keeps `social_accounts` (linked identities), the unlink route and the `LoginOptions` registry. An identity provider login must never take over an existing account by e-mail unless that provider is explicitly configured to, and confirms the address is verified.

## Security
- Reference: `docs/dev/architecture/security.md`. Links use `APP_URL` for unknown hosts (`UseConfiguredSiteAddress`), every response gets `AddSecurityHeaders`, and new password or e-mail forms need a named `throttle:` limiter (`AppServiceProvider::configureRateLimiting()`) and must not reveal whether an account exists. Photo files for people who may not edit them go out without GPS data (`PhotoLocation`, never change the stored file).

## Site settings
- Admin → Settings (`App\Filament\Clusters\Settings`: one `SettingsPage` per topic, a new topic is a new page class) stores values via `App\Support\SiteSettings`; `SiteSettings::apply()` copies them into `config('app.name')`, `config('gallery.registration_enabled')`, `config('gallery.public_albums_enabled')`, `config('gallery.max_upload_kb')` (+ `media-library.max_file_size`), `config('gallery.accepted_mime_types')` and `config('mail.*')` at boot. Read those config keys (or `SiteSettings`), never `env()`. `.env` values are only initial defaults (`SiteSettings::original()` keeps them for fallback). E-mail: `App\Enums\MailDelivery` + `SiteSettings::mailConfig()`; the SMTP password is stored encrypted (`SiteSettings::encrypt()`). Selectable upload types are limited to `PhotoUploads::processableTypes()`.
- With public albums off, `Visibility::Public` means "any logged-in user" and guests get the landing block (content/layout from the theme settings) or the login page at `/`. Unlisted share links still work.
- Branding uploads live on the private `local` disk under `branding/` and are served by `BrandingController` with a restrictive CSP.
- Profile pictures: `users.avatar_path` (hidden, so changes are logged without values) on the private `local` disk under `avatars/`, always written through `App\Support\Avatars::store()` (re-encoded square WebP, no SVG) and served by `AvatarController` to logged-in users. The `User` model deletes replaced files. Users without one get local initials (`InitialsAvatarProvider`, `<x-avatar>`); never use external avatar services.
- Activity log: `App\Support\ActivityLog` records logins (Login event) and album views (`AlbumController@show`) into `activity_events`. Never store IPs or user agents; guests get a daily-rotating HMAC (`visitor`). Detail level: `App\Enums\ActivityLevel` (off / changes / logins / all) via `SiteSettings::activityLevel()`; `ActivityLog` checks it per kind (changes, logins, album views). Retention: `SiteSettings::activityRetentionDays()` (Admin → Settings, default 365), pruned by `model:prune` in the background jobs (`App\Support\BackgroundJobs`: one runner for periodic tasks, started by AJAX page views, a webcron URL or cron as chosen in `SiteSettings::backgroundJobs()`; add new periodic tasks to `BackgroundJobs::tasks()`, not to `Schedule`). New albums take their photo order from `SiteSettings::defaultPhotoOrder()`.
- Dashboard: `App\Filament\Pages\Dashboard` shows the widgets registered in `App\Extensions\DashboardWidgets` (stable keys; plugins register theirs in `register()`). Widgets extend `App\Filament\Widgets\DashboardWidget`; each widget's `canView()` (built on `viewer()` / `viewerCan()`) gates it by the capability it is about. Each person's arrangement (order, hidden, size) is `users.dashboard_layout`, read and written only through `App\Support\Dashboard\DashboardLayout` (keys only; permissions always win; not logged). Queries live in `App\Support\DashboardStats` and `App\Support\Dashboard\*`, scoped to the viewer (`Album::listableFor()` / `manageableBy()`, `Photo::visibleTo()` / `trashableBy()`), site-wide totals cached briefly. Developer reference: `docs/dev/extending/dashboard-widgets.md`.
- Change log: `App\Observers\ActivityObserver` (albums, users, groups, roles) + custom pivot models in `App\Models\Pivots` (album shares, group members, user roles, role permissions) feed `ActivityLog`. Any new many-to-many relation that should be logged must use `->using(<Pivot with RecordsPivotActivity>)` on every side. Hidden attributes are logged as "changed" without values. Changes to one subject by one person within `ActivityLog::MERGE_SECONDS` merge into a single entry. Use `updateQuietly()` for automatic, non-user changes (e.g. album cover on upload). Log bulk operations once (`ActivityLog::photosUploaded`), not per item.

## Languages
- Core owns every application text: `__('English text')` with the English text as key, translated in `lang/{locale}.json` (+ `lang/{locale}/{auth,passwords,pagination,validation}.php`). English is the source and has no JSON file. Every user-facing string in PHP, Blade and Filament goes through `__()` / `trans_choice()` with `:placeholders`, never concatenation; JS texts are passed from PHP. Add each new key to every `lang/*.json`; `tests/Feature/TranslationFilesTest.php` checks keys, placeholders and renders the admin + site in German.
- Plugins and themes never add core keys. Their own texts are namespaced: plugins `plugins/{slug}/lang/{locale}/{group}.php` as `__('{slug}::group.key')` (registered by `PluginManager`), themes `themes/{slug}/lang/{locale}/{group}.php` as `__('theme-{slug}::group.key')` (registered by `ThemeManager`). Manifest texts (theme.json / plugin.json names, descriptions, settings labels, options, palettes) go through `Manifest::text()` / `label()` / `summary()`: a namespaced key is translated, plain text is shown as written. Theme `lang/{locale}.json` overrides core copy and `lang/vendor/{ns}/...` a plugin's or parent's texts (`App\Extensions\TranslationLoader` override paths; theme lang dirs are never normal lookup paths).
- Language choice: site default `SiteSettings::LOCALE` (Admin → Settings, `config('gallery.default_locale')`), per user `users.locale` (profile / user form, null = default), applied by `App\Http\Middleware\SetLocale` (web + panel, also `Number::useLocale`). `App\Support\Locales` lists languages (English + core `lang/*.json`). `User` implements `HasLocalePreference`, so mail goes out in the recipient's language. Filament: static label props cannot call `__()`; override `getModelLabel()` / `getPluralModelLabel()` / `getNavigationLabel()` / `getTitle()`; navigation groups are `App\Filament\Navigation\NavigationGroup` (lazy labels). Stored texts (activity log fields, option values) are stored in English and translated when shown. Developer reference: `docs/dev/extending/translations.md`.

## Extensions

- Themes: `/themes/{slug}/theme.json` (+ optional `views/`, `lang/`, `assets/`). Every visitor-facing surface is themeable through these seams (wired in `ThemeManager::boot()`, child theme before parent before core); use them, don't add new mechanisms: `views/` overrides any `resources/views` file, `views/errors/*` error pages, `views/vendor/{namespace}/*` package views, `views/vendor/mail/*` e-mail components, `lang/{locale}.json` any core `__()` copy, `lang/{locale}/*.php` the theme's own `theme-{slug}::` texts (see Languages). Developer reference: `docs/dev/extending/themes.md`.
- Tokens: CSS variables `--og-*` (hex in theme.json, converted to RGB channels; defaults in `resources/css/app.css` and `themes/default/theme.json`) mapped to Tailwind `canvas, surface, ink, muted, line, primary, on-primary, danger, on-danger`, plus `--og-radius` (`rounded-og`) and `--og-font-sans` (`font-sans`). Header colours `--og-header-{canvas,surface,ink,muted,line}` default to the page tokens; every header layout's `<header>` carries `og-header`, which swaps the page tokens for them, so header markup keeps using the normal token classes. `variables_dark` applies under `html.dark`; palettes may set any token. Use these tokens and the `og-*` component classes (`og-container`, `og-btn-primary`, `og-input`, ...) in frontend views, never raw `gray-*`/`red-*`/hex. A new colour need gets a new token (app.css + tailwind.config.js + Default theme), not a hard-coded value.
- Theme settings: themes declare a settings form in theme.json `settings` (field types text/textarea/toggle/select/layout/image/pages/palette/colors/css; `block`, `css_variable`, `visible_if`, `default`). A child theme's settings merge over its parent's (sections by `key`/`label`, fields by `key`, `options` added, `"remove": true` drops). Values live in Setting `theme.options.{slug}`; read them with `App\Support\Theme::option()/image()/pages()`. The admin form is generic (`App\Filament\Pages\ThemeSettings`); do not hard-code theme options in core. Theme images are on the private `local` disk under `themes/{slug}` and served by `ThemeAssetController::upload`. Custom CSS must stay escaped (`ThemeManager::customCss`).
- Pages (`App\Models\Page`, `/pages/{slug}`): published pages are public even with public albums off; `members_only` needs login. Render bodies only through `RichContentRenderer` (sanitizes).
- Page content blocks: `App\Filament\RichEditor\PhotoBlock` / `GalleryBlock` (custom rich editor blocks; config `photo_id` / `photo_ids`). On the site they render through the themeable blocks `page-photo` / `page-gallery` with the lightbox, and images use `route('pages.photo', [page, photo, conversion])`, which only serves photos that `Page::embeddedPhotoIds()` finds in a page the visitor may see. Placing a photo on a published page publishes it there only. Photos are chosen with `MediaPickerField` (uploads go into the private album `Album::pageImages()`).
- Page building blocks are rendered with `<x-block name="..." :prop="..." />` (views in `resources/views/blocks`). `ThemeManager::blockView()` resolves: settings field with `"block"` > theme.json `blocks` > `blocks/{name}/{variant}` if it exists > `blocks/{name}`. Core ships one plain fallback per block; extra layouts belong to themes (the Default theme ships its header/footer/landing/login/album/photo variants in `themes/default/views/blocks`). Pagination (`->links()`) renders the `pagination` block. Put new reusable page sections in a block, not inline in a page.
- Dark mode uses the Filament storage key `filament:theme:mode` and the `.dark` class, shared by site and admin. The admin follows the theme settings too: Filament's primary and gray palettes come from `App\Support\AdminTheme` (resolved tokens incl. palette and custom colours), and corners/dark-mode primary buttons from `resources/views/filament/partials/theme-styles.blade.php`. Never hard-code admin colours.
- Plugins: `/plugins/{slug}/plugin.json` with `autoload` + `provider`; loaded by `App\Extensions\PluginManager` only while enabled. Plugins hook in via events/service providers and must not require core edits. If you need a new extension point, add an event or config hook to core rather than special-casing a plugin.
- Plugins are loaded in the register phase (`PluginManager::register()` from `AppServiceProvider::register()`); their `boot()` may use Eloquent, their `register()` must not. Extension points: plugin.json `settings` (page extending `App\Filament\Pages\PluginSettingsPage`, slug `plugins/{slug}/settings`), `App\Extensions\PluginSettings` (encrypt secrets), `App\Extensions\LoginOptions` (login buttons / default login; `/login?local=1` must always show the password form). `GALLERY_PLUGINS` force-enables plugins; tests enable `identity-providers` via phpunit.xml.
- Blade: never mix inline `@php(...)` with `@php ... @endphp` blocks in one view; Blade then swallows the markup in between.
- Runtime settings (active theme, enabled plugins) are in the `settings` table via `App\Models\Setting::get()/put()`.

## Conventions

- Pest feature tests for every behaviour change; roles are seeded automatically in feature tests (`tests/Pest.php`).
- Run `vendor/bin/pint` before finishing.
- Docs (`/docs`, shown in Admin → Help → Documentation): `docs/user/` and `docs/dev/` hold topic sub-folders. Nothing about their order is hard-coded: every page except `docs/README.md` starts with front matter `---\norder: 20\n---` (steps of 10), a folder takes its place from its `README.md`'s `order` and its label from that README's `#` heading (`App\Support\Documentation`). `DocumentationTest` checks orders, links, anchors and images. Developer reference: `docs/dev/contributing/documentation.md`.
- Image processing uses GD by default (Imagick optional); conversions run synchronously unless `GALLERY_QUEUE_CONVERSIONS=true`.

## Releases

- `main` is protected: every change goes through a branch and a pull request with the five CI checks. Merging never creates a release; tag one only when the maintainer asks.
- Next version: bug or security fix = patch (`0.2.1`). New feature, database change, updater change or, during 0.x, a breaking theme/plugin change = at least the next minor (`0.3.0`). `1.0.0` only when the project is promised as stable. The highest kind of change in a release decides.
- Publish a test version (`-rc.N` or `-dev.N`) first for everything except plain fixes that touch neither the database nor the updater. Updater changes need a real update test, because the installed version's runner performs the update.
- Never create a GitHub release by hand, change a published release or reuse a tag. Stable releases are signed only on the maintainer's machine; the stable secret key never goes into GitHub.
- Steps and full rules: `docs/dev/contributing/releases.md`.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
