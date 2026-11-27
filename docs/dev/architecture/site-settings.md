---
order: 40
---

# Site settings internals

The site settings (pages of the cluster `App\Filament\Clusters\Settings\SettingsCluster`, see [Settings pages](#settings-pages)) store their values in the `settings` table through `App\Models\Setting`. `App\Support\SiteSettings` defines the keys, applies the stored values to Laravel's config at boot and offers typed accessors. Code reads the config keys or the accessors, never `env()`.

## How a setting flows

1. `.env` and `config/*.php` provide the **initial defaults** (for example `GALLERY_REGISTRATION`, `APP_LOCALE`, `MAIL_MAILER`).
2. The administrator saves a settings page. `SettingsPage::save()` writes each of that page's values with `Setting::put()` (secrets through `SiteSettings::encrypt()`), records one "Changed settings" activity entry and calls `SiteSettings::apply()` again.
3. On every request, `AppServiceProvider::boot()` calls `SiteSettings::apply()`, which overwrites the config keys with the stored values.

`Setting::get()` reads all settings as one array from the cache store (`gallery.settings`, filled from the `settings` table on a miss) through `Cache::memo()`, so a request reads the cache store once and every further `get()` comes from memory. `Setting::put()` and deletes call `Setting::forgetCached()`, which forgets the array in memory and in the cache store, so a changed value is visible at once, also later in the same request. `Cache::memo()` is a scoped binding: queued jobs start with an empty memo. Do not write the `settings` table directly; a direct write is only seen after `Setting::forgetCached()`.

`SiteSettings::original()` stashes the config value a key had before any setting was applied (under `gallery.env_defaults.*`), so re-applying after a save, or clearing a field, falls back to `.env` rather than to an older setting.

## Keys

| Constant | Setting key | Applied to / read with |
| --- | --- | --- |
| `NAME` | `site.name` | `config('app.name')` |
| `SHOW_NAME` | `site.show_name` | `SiteSettings::showName()` |
| `DESCRIPTION` | `site.description` | `SiteSettings::description()` (search results and link previews, see [Search engines](../internals/search-engines.md)) |
| `SEARCH_INDEXING` | `seo.indexing` | `SiteSettings::searchIndexing()` (robots.txt, sitemap, `robots` meta tag; on by default) |
| `BLOCK_AI_CRAWLERS` | `seo.block_ai_crawlers` | `SiteSettings::blocksAiCrawlers()` (on by default; `SearchEngines::AI_CRAWLERS`) |
| `ROBOTS_EXTRA` | `seo.robots_extra` | `SiteSettings::robotsExtra()` (lines added to robots.txt as written) |
| `GOOGLE_VERIFICATION`, `BING_VERIFICATION` | `seo.google_verification`, `seo.bing_verification` | `SiteSettings::googleVerification()`, `bingVerification()` (the code only; `SearchEngineSettings::verificationCode()` takes it out of a pasted meta tag) |
| `LOGO`, `LOGO_DARK`, `FAVICON` | `site.logo`, `site.logo_dark`, `site.favicon` | `SiteSettings::assetUrl()`, `faviconUrl()` |
| `APP_ICON` | `site.app_icon` | `SiteSettings::assetPath('app-icon')`, `WebApp::iconUrl()` |
| `WEB_APP` | `site.web_app` | `WebApp::enabled()` (on by default) |
| `LOCALE` | `site.locale` | `config('gallery.default_locale')`, `Locales::default()` |
| `REGISTRATION` | `access.registration` | `config('gallery.registration_enabled')` |
| `REQUIRE_APPROVAL` | `access.require_approval` | `SiteSettings::requiresApproval()` (people who sign up wait for approval; off by default) |
| `NOTIFY_REGISTRATIONS` | `access.notify_registrations` | `SiteSettings::notifiesAboutRegistrations()` (e-mail people who manage users about sign-ups; off by default) |
| `PUBLIC_ALBUMS` | `access.public_albums` | `config('gallery.public_albums_enabled')` |
| `GUEST_HOME` | `access.guest_home` | `SiteSettings::guestHome()` (`landing` or `login`) |
| `ALBUM_GUESTS` | `access.album_guests` | `SiteSettings::albumGuestsEnabled()` (on by default; sharing restricted albums by e-mail with people without an account, see [Album guests](access-model.md#album-guests)) |
| `ALBUM_GUEST_DAYS` | `access.album_guest_days` | `SiteSettings::albumGuestDays()` (30 by default, 0 = no limit, at most 3650; counted from when the guest was added) |
| `DEFAULT_PHOTO_ORDER` | `albums.default_photo_order` | `config('gallery.default_photo_order')`, `SiteSettings::defaultPhotoOrder()` |
| `UPLOAD_MAX_MB` | `uploads.max_mb` | `config('gallery.max_upload_kb')` and `config('media-library.max_file_size')` |
| `UPLOAD_TYPES` | `uploads.accepted_types` | `config('gallery.accepted_mime_types')`, `SiteSettings::uploadTypes()` |
| `COMPRESSION_ENABLED` | `uploads.compression_enabled` | Main switch (off by default); `SiteSettings::compressionEnabled()` |
| `COMPRESSION_RESIZE` | `uploads.compression_resize` | Part "limit the resolution" (on by default once compression is on) |
| `COMPRESSION_MAX_PX` | `uploads.compression_max_px` | Longest side in px (3840 by default, 640 to 20000); `SiteSettings::compressionMaxPx()` is `null` while compression or this part is off |
| `COMPRESSION_REENCODE` | `uploads.compression_reencode` | Part "lower the quality" |
| `COMPRESSION_QUALITY` | `uploads.compression_quality` | JPEG/WebP quality in % (80 by default, 1 to 100); `SiteSettings::compressionQuality()` is `null` while compression or this part is off. See [Photo compression](../internals/uploads.md#photo-compression) |
| `REMOVE_PHOTO_LOCATION` | `photos.remove_location` | `SiteSettings::removesPhotoLocation()` (on by default) |
| `DOWNLOADS_ENABLED` | `photos.downloads_enabled` | `SiteSettings::downloadsEnabled()` (off by default; master switch above each album's `allow_downloads`, see [Downloads and favourites](access-model.md#downloads-and-favourites)) |
| `FAVORITES_ENABLED` | `photos.favorites_enabled` | `SiteSettings::favoritesEnabled()` (on by default; `Favorites::enabledFor()` also needs a logged-in user) |
| `PHOTO_INFO_ENABLED` | `photos.info_enabled` | `SiteSettings::photoInfoEnabled()` (on by default; the lightbox's "i" panel, `App\Support\PhotoExif`) |
| `LIGHTBOX_FOOTER` | `photos.lightbox_footer` | `SiteSettings::lightboxFooter()`: `App\Enums\LightboxFooter` (`DescriptionAndTaken` by default, also for unknown values); what the lightbox shows below the photo, built per photo by `PhotoGridItems` as the item's `footer` |
| `SLIDESHOW_ENABLED` | `photos.slideshow_enabled` | `SiteSettings::slideshowEnabled()` (on by default) |
| `SLIDESHOW_SECONDS` | `photos.slideshow_seconds` | `SiteSettings::slideshowSeconds()` (5 by default, clamped to `SLIDESHOW_SECONDS_MIN` 2 to `SLIDESHOW_SECONDS_MAX` 60) |
| `STORAGE_LIMITS` | `storage.limits_enabled` | `SiteSettings::storageLimitsEnabled()` (off by default; off = nobody is limited, see [Storage limits](../internals/uploads.md#storage-limits)) |
| `DEFAULT_STORAGE_QUOTA_GB` | `storage.default_quota_gb` | `SiteSettings::defaultStorageQuotaGb()` (10 by default, clamped to `STORAGE_QUOTA_MIN_GB` 1 to `STORAGE_QUOTA_MAX_GB` 1000000; followed by every user without their own limit) |
| `TAGGING` | `tags.enabled` | `SiteSettings::taggingEnabled()` (on by default) |
| `TAGS_ALLOW_CUSTOM` | `tags.allow_custom` | `SiteSettings::allowsCustomTags()` (on by default) |
| `TAGS_GLOBAL_SUGGESTIONS` | `tags.global_suggestions` | `SiteSettings::globalTagSuggestions()` (off by default, because tag names can be private) |
| `TAGS_PREDEFINED` | `tags.predefined` | `SiteSettings::predefinedTags()` (list of names, see [Tags](data-model.md#tags)) |
| `LICENSING` | `licenses.enabled` | `SiteSettings::licensingEnabled()` (off by default; `Licensing::enabled()`) |
| `LICENSE_DEFAULT` | `licenses.default` | `SiteSettings::defaultLicenseId()` (id of the license new photos get; required while licensing is on) |
| `LICENSES_ALLOW_CHOICE` | `licenses.allow_choice` | `SiteSettings::allowsLicenseChoice()` (on by default; people who manage settings may choose regardless) |
| `LICENSES_CHOOSABLE` | `licenses.choosable` | `SiteSettings::choosableLicenseIds()` (list of license ids; null while never saved = every active license; the default can always be chosen) |
| `MAIL_DELIVERY` | `mail.delivery` | `config('mail.default')` through `App\Enums\MailDelivery` (`server`, `smtp`, `sendmail`, `mailbox` for the test mailbox) |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | `mail.host`, `mail.port`, `mail.scheme`, `mail.username`, `mail.password` | `config('mail.mailers.smtp.*')` |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `mail.from_address`, `mail.from_name` | `config('mail.from.*')` |
| `ACTIVITY_LEVEL` | `activity.level` | `config('gallery.activity_log_level')`, `SiteSettings::activityLevel()` |
| `ACTIVITY_RETENTION` | `activity.retention_days` | `config('gallery.activity_retention_days')`, `SiteSettings::activityRetentionDays()` |
| `ACTIVITY_LOG` | `access.activity_log` | Legacy on/off switch; only read when no level is stored |

Limits are constants too: `UPLOAD_MIN_MB` (1), `UPLOAD_MAX_MB_LIMIT` (2048), `RETENTION_MIN_DAYS` (1), `RETENTION_MAX_DAYS` (3650). Stored values outside them are clamped when applied.

The `.env` names behind the initial defaults are in `config/gallery.php`: `GALLERY_REGISTRATION`, `GALLERY_PUBLIC_ALBUMS`, `GALLERY_ACTIVITY_LOG_LEVEL` (and the older `GALLERY_ACTIVITY_LOG`), `GALLERY_ACTIVITY_RETENTION_DAYS`, `GALLERY_DEFAULT_PHOTO_ORDER` and `GALLERY_MAX_UPLOAD_KB`. Only their first value matters: once the setting is saved in the admin, the stored value wins.

## Language

`SiteSettings::apply()` sets `config('gallery.default_locale')` to the stored language if `App\Support\Locales::isAvailable()` accepts it, else to `APP_LOCALE`, else English (`Locales::SOURCE`). Per-user languages and `SetLocale` are described in [Languages and translations](../extending/translations.md).

## E-mail

`App\Enums\MailDelivery` has four cases: `Server` (keep `.env`), `Smtp`, `Sendmail` and `Log`; `mailer()` returns the Laravel mailer name or null for `Server`. `SiteSettings::mailConfig(array $mail)` turns stored values into config:

- empty fields keep the `.env` values; the sender name falls back to the site name;
- for SMTP, the port defaults to 587, the scheme is `smtps` for SSL/TLS and `smtp` otherwise (STARTTLS), and `mail.mailers.smtp.url` is cleared so a `MAIL_URL` in `.env` cannot override the fields.

The settings page's **Send a test e-mail** builds the config from the form state with the same method, sends a message to the current user and restores the stored config with `apply()` afterwards.

The SMTP password is encrypted with `SiteSettings::encrypt()` (Laravel's `Crypt`, so it depends on `APP_KEY`). It is decrypted only inside `storedMail()`; if `APP_KEY` changed, decryption fails quietly and the password counts as empty. The form never shows it: an empty field keeps it, and clearing the user name removes it.

## Uploads

`UPLOAD_TYPES` stores mime types; only keys of `App\Support\PhotoUploads::TYPES` are kept, and the form only offers types in `PhotoUploads::processableTypes()` (what the configured image driver can decode). An empty selection falls back to `config('gallery.accepted_mime_types')`. Upload handling itself is described in [Photo uploads](../internals/uploads.md).

## Branding assets

Logo, dark logo, favicon and app icon are uploaded to the private `local` disk under `branding/` (`SiteSettings::DISK`, `DIRECTORY`). `SiteSettings::ASSETS` maps the route keys `logo`, `logo-dark`, `favicon` and `app-icon` to their setting keys; `BrandingController` serves them at `route('site.asset', ...)` with a restrictive Content Security Policy, and `assetUrl()` adds a cache-busting `v` parameter. Without a favicon, `faviconUrl()` returns `public/favicon.svg`. The app icon is used in its square PNG sizes, made by `WebApp::iconPath()` under `branding/app-icons/` (see [Installable app](../internals/web-app.md)).

The `local` disk keeps Laravel's `serve` option on (`config/filesystems.php`): the admin's file upload fields preview existing logos, avatars and theme images through short-lived signed links (`temporaryUrl()`). Unsigned requests to `/storage/...` are refused; visitors only get these files through the controllers above.

## Settings pages

Each topic is a class in `app/Filament/Clusters/Settings/Pages` that extends `SettingsPage`. Filament discovers it. `SettingsPage::registerNavigationItems()` lists it directly in the main admin menu, in the group from `$navigationGroup` (`App\Filament\Navigation\NavigationGroup::Settings` or `System`), ordered by `$navigationSort`. The cluster keeps the shared `/admin/settings/...` addresses and access check, but has no menu entry or sub-navigation of its own.

A page declares:

- `FIELDS`: form field => setting key and English name. The name is used in the activity log and by the admin search, so typing a field's name finds its page.
- `FILE_FIELDS`, `INTEGER_FIELDS` and `SECRET_FIELDS` for fields that need that handling when saved.
- `formValues()` and `formSections()`: the stored values and the form.
- Optional hooks: `prepareValue()`, `fileReplaced()`, `afterFieldsStored()` and `afterSettingsApplied()`.

`save()` only touches the page's own fields. `/admin/settings` redirects to the first page. Every page warns before leaving with unsaved changes. Link to a topic with its class, for example `EmailSettings::getUrl()`; `SettingsPage::menuPath()` gives its translated menu path, such as "System → Updates".

`ActivityLogSettings` is the exception in the menu: it opens from the **Log settings** header action of `ManageActivityEvents`, and only people who cannot open the log get a menu entry for it (`shouldRegisterNavigation()`).

## Adding a site setting

1. Add a constant for the key to `SiteSettings` and, if code elsewhere needs it, an accessor.
2. If it maps to a config key, set that key in `SiteSettings::apply()`, using `original()` for the fallback, and put the initial default in `config/gallery.php` (with an `env()` call there only).
3. Add the field to the settings page of its topic (`FIELDS`, `formValues()` and `formSections()`), or add a new page class for a new topic, with `__()` texts in every `lang/*.json`.
4. Read it through the config key or the accessor everywhere else.
5. Add a test (see `tests/Feature/Backend/Settings/<Page>Test.php` for the page of the topic and `tests/Feature/System/SettingsTest.php` for defaults and reading) and describe the field in the user guide's [Site settings](../../user/administration/settings.md).
