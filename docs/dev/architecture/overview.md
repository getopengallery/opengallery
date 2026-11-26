---
order: 10
---

# Architecture overview

OpenGallery is a Laravel 13 application with a Filament 5 admin panel. Visitors see server-rendered Blade views (with Alpine.js and Tailwind CSS 3); administrators work in the Filament panel at `/admin`. Roles and permissions come from Spatie Laravel Permission, photo files and their conversions from Spatie Media Library.

## Constraints that shape the code

- **Plain shared hosting.** PHP 8.3+, MySQL or MariaDB and the local filesystem are all there is. No Docker, Redis, Node.js at runtime, websockets or required queue worker. Anything heavier must be optional (a config flag) or a plugin.
- **Built assets ship with releases.** `public/build` is committed to releases; end users never run npm (see the frontend build in the contributing section).
- **Private files.** Photos, logos, profile pictures and theme images are never public files. Every one is served by a controller that checks access first.
- **Extensible without core edits.** Themes and plugins are folders that hook into seams core provides. A new need gets a new seam (event, config hook, block), never a special case for one extension.

## Where things live

| Path | Contents |
| --- | --- |
| `app/Enums` | `Permission`, `Role`, `Visibility`, `PhotoOrder`, `ActivityType`, `ActivityLevel`, `MailDelivery` |
| `app/Models`, `app/Models/Pivots` | Eloquent models; pivot models that log their changes (see [Data model](data-model.md)) |
| `app/Policies` | `AlbumPolicy`, `PhotoPolicy` (see [Access model](access-model.md)) |
| `app/Support` | Services without a better home: `SiteSettings`, `AlbumAccess`, `AlbumSearch`, `ActivityLog`, `PhotoUploads`, `Theme`, `Locales`, `Documentation`, `DashboardStats` and `Dashboard\*` (queries behind the dashboard widgets), `Avatars`, `AdminTheme`, `Navigation`, and the e-mail helpers `Mailing`, `Invitations`, `ShareNotices`, `AccountNotices` |
| `app/Notifications` | Notices in the gallery and e-mails, such as `UserInvited`, `AlbumShared`, `AccountCreated`, and the queued mail channel (see [Notifications and e-mails](../internals/emails.md)) |
| `app/Extensions` | Theme and plugin loading: `ThemeManager`, `PluginManager`, `Manifest`, `PluginSettings`, `LoginOptions`, `DashboardWidgets`, `TranslationLoader` |
| `app/Filament` | The admin: resources, pages, widgets, relation managers, the media picker action and form field, rich editor blocks, the global search provider |
| `app/Livewire/MediaPicker.php` | The one photo picker used everywhere in the admin |
| `app/Http/Controllers` | Site pages, auth (Laravel Breeze), and the controllers that serve private files |
| `app/Observers/ActivityObserver.php` | Feeds model changes into the activity log |
| `resources/views` | Site views; `blocks/` holds the themeable building blocks, `filament/` the admin's custom views and partials |
| `themes/{slug}`, `plugins/{slug}` | Installed themes and plugins |
| `lang` | Core translations (`{locale}.json` plus the framework's `{locale}/*.php`) |
| `docs` | This documentation, rendered in the admin |

## Boot sequence

`AppServiceProvider::register()` runs first:

1. Binds `ThemeManager` (themes folder and fallback theme from `config/gallery.php`), `PluginManager` and `LoginOptions` as singletons.
2. Replaces the translation loader with `App\Extensions\TranslationLoader`, so a theme's `lang` folder overrides texts instead of being a normal lookup path.
3. Calls `PluginManager::register()`, which autoloads and registers every enabled plugin's service provider. Plugins are therefore registered before the admin panel is configured and can add to it. A plugin's `register()` must not use the database; its `boot()` may.

`AppServiceProvider::boot()` then:

1. Registers the `database` session driver as `App\Support\PrivateDatabaseSessionHandler` (no IP addresses or raw user agents; only the short `UserAgentLabel`), sets `Password::defaults()` to at least 12 characters (used by every password field), and adds `Gate::before()`: the `super-admin` role passes every check.
2. Listens to auth events: `Registered` gives new accounts the `user` role; `Login`, `Failed`, `Lockout` and `PasswordReset` are written to the activity log.
3. Wraps every Filament bulk action in `ActivityLog::startBatch()` / `finishBatch()`, so a bulk action is one log entry.
4. Sets the table defaults for the admin (filters in a dropdown, applied immediately) and the confirmation of plain delete actions ("This cannot be undone.").
5. Registers `ActivityObserver` on `Album`, `User`, `Group`, `Role`, `Page` and `Photo`.
6. Calls `SiteSettings::apply()`, which copies the values saved in Admin → Settings into config (see [Site settings internals](site-settings.md)).
7. Sets the site's default language for the app and for number formatting.
8. Calls `ThemeManager::boot()`, which wires the active theme (and its parents) into views, error pages, package views, mail components and translations.

`bootstrap/app.php` adds `App\Http\Middleware\SetLocale` to the `web` middleware group, so every page, including Livewire requests, switches to the visitor's language. The admin panel lists it in its own middleware stack too.

## Requests

### The site

Routes are in `routes/web.php` (site) and `routes/auth.php` (Breeze auth plus unlinking sign-in accounts). Controllers return Blade views built from blocks, rendered with `<x-block name="..." />`; `ThemeManager::blockView()` picks the theme's layout for each block.

| Route | Name | What |
| --- | --- | --- |
| `/` | `home` | Top-level albums the visitor may list; the landing page or a redirect to login for guests while public albums are off |
| `/albums/{album}` | `albums.show` | An album (by slug): password form, sub-albums, photos (60 per page); records an album view |
| `/albums/{album}/unlock` | `albums.unlock` | Album password form (throttled to 10 tries a minute) |
| `/s/{token}` | `albums.shared` | Share link of an unlisted album: unlocks it for the session, or shows "expired" (HTTP 410) |
| `/my-albums` | `dashboard` | Own albums and albums shared with the user |
| `/pages/{page}` | `pages.show` | A content page (by slug) |
| `/profile`, `/profile/avatar` | `profile.*` | Profile, password, avatar, account deletion |
| `/uploads/photos` | `uploads.photos` | Chunked photo uploads from the media picker |
| `/offline` | `web-app.offline` | The page the service worker shows without a connection (see [Installable app](../internals/web-app.md)) |
| `/up` | | Laravel's health check |

### Private files

Every file a visitor sees goes through one of these controllers:

| Route | Controller | Serves |
| --- | --- | --- |
| `/media/{media}/{conversion?}` (`media.show`) | `MediaController` | Photos: the original, `thumb` or `preview`, if the visitor may see one of the photo's albums |
| `/pages/{page}/photos/{photo}/{conversion?}` (`pages.photo`) | `PageController@photo` | Photos placed on a page the visitor may see |
| `/site/{asset}` (`site.asset`) | `BrandingController` | Logo, dark logo and favicon, with a restrictive Content Security Policy |
| `/avatars/{user}` (`avatars.show`) | `AvatarController` | Profile pictures, to logged-in users |
| `/manifest.webmanifest` (`web-app.manifest`), `/app-icons/{size}.png` (`web-app.icon`) | `WebAppController` | The web app manifest and the uploaded app icon in 180, 192 and 512 px |
| `/theme-media/{path}` (`theme.upload`) | `ThemeAssetController@upload` | Images uploaded in theme settings |
| `/themes/{theme}/{path}` (`theme.asset`) | `ThemeAssetController` | Files from a theme's `assets/` folder |
| `/help/assets/{path}` (`docs.asset`) | `DocumentationAssetController` | Images of this documentation, to admin users |

### The admin panel

`App\Providers\Filament\AdminPanelProvider` configures the panel at `/admin`:

- There is no admin login page: guests are sent to the site's `/login`, which also shows sign-in buttons from plugins. Access needs the permission `access admin panel` (`User::canAccessPanel()`).
- Resources and pages are discovered in `app/Filament` (the dashboard is `App\Filament\Pages\Dashboard`); plugin settings pages are added from `PluginManager::settingsPages()`. Dashboard widgets come from `App\Extensions\DashboardWidgets` (see [Dashboard widgets](../extending/dashboard-widgets.md)).
- Navigation groups come from `App\Filament\Navigation\NavigationGroup`; colours from `App\Support\AdminTheme`, so the admin follows the active theme.
- Render hooks add the theme's corner and button styles, the chunked photo uploader script and a fix for global search results in Safari.
- Global search is `App\Filament\GlobalSearch\AdminSearchProvider` (admin screens, records and the documentation), opened with Ctrl/Cmd+K.

## Storage

All disks are private; files are only reachable through the controllers above.

| Disk | Root | Holds |
| --- | --- | --- |
| `gallery` (`gallery.media_disk`) | `storage/app/gallery` | Photo originals and their `thumb` and `preview` conversions |
| `local` | `storage/app/private` | `branding/` (logo, favicon), `avatars/`, `themes/{slug}/` (theme setting images), `upload-chunks/` (unfinished uploads) |

Runtime state that is not a model lives in the `settings` table, read with `App\Models\Setting::get()` and written with `Setting::put()`. Reads are cached forever (cache key `gallery.settings`) and the cache is cleared on every write. `Setting::get()` returns defaults when the table does not exist yet, so the app boots before the first migration. Keys in use:

| Key | Holds |
| --- | --- |
| `theme` | Slug of the active theme (`ThemeManager::SETTING`) |
| `theme.options.{slug}` | Saved theme settings (`ThemeManager::OPTIONS`) |
| `plugins.enabled` | Slugs of plugins turned on in the admin (`PluginManager::SETTING`) |
| `plugin.{slug}` | A plugin's own settings (`PluginSettings`) |
| `site.*`, `access.*`, `albums.*`, `uploads.*`, `mail.*`, `activity.*` | Admin → Settings (see [Site settings internals](site-settings.md)) |

## Background work

Nothing requires a queue worker. Image conversions run during the upload request unless `GALLERY_QUEUE_CONVERSIONS=true`. Periodic housekeeping (activity log retention, unfinished chunked uploads, the `small` size of older photos, waiting e-mails and, with queued conversions on the database queue, the queue itself) runs in `App\Support\BackgroundJobs`, started by page views (AJAX), a webcron address or cron, as chosen in Admin → System → Background jobs. See [Background jobs](../contributing/commands.md#background-jobs).

## Extending

Themes, plugins, blocks and translations are covered in [Extending OpenGallery](../extending/README.md).
