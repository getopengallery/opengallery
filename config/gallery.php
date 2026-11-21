<?php

use App\Support\ProductionCaches;

return [

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    |
    | Whether the setup is finished. Leave unset: OpenGallery finds out itself
    | (marker file storage/app/installed, written by the web setup at /setup
    | and by `php artisan gallery:install`). true / false forces the answer.
    |
    */

    'installed' => env('GALLERY_INSTALLED'),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Whether visitors may create accounts themselves (locally or through an
    | OAuth provider). Disable for invite-only family / club installations.
    |
    */

    'registration_enabled' => env('GALLERY_REGISTRATION', true),

    /*
    | When false, albums marked "Public" are only visible to logged-in users
    | and guests see a landing page (or the login page) instead of albums.
    | Both settings can be changed in Admin → Settings, which overrides .env.
    */

    'public_albums_enabled' => env('GALLERY_PUBLIC_ALBUMS', true),

    /*
    | Activity log for the admin dashboard (logins and album views). Stores no
    | IP addresses; guests are counted with a daily-rotating anonymous hash.
    | Entries older than the retention period are removed by the scheduler
    | (`php artisan schedule:run` via cron) or `php artisan model:prune`.
    */

    // off | changes | logins (changes + logins) | all (also album views). Admin → Settings overrides it.
    // The older GALLERY_ACTIVITY_LOG=false still means "off".
    'activity_log_level' => env('GALLERY_ACTIVITY_LOG_LEVEL', env('GALLERY_ACTIVITY_LOG', true) ? 'all' : 'off'),

    // Photo order for new albums (App\Enums\PhotoOrder). Admin → Settings overrides it.
    'default_photo_order' => env('GALLERY_DEFAULT_PHOTO_ORDER', 'manual'),

    'activity_retention_days' => env('GALLERY_ACTIVITY_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    'media_disk' => env('GALLERY_MEDIA_DISK', 'gallery'),

    // Shared hosting usually has no queue worker, so conversions run inline
    // by default. Set to true when a worker (or cron-driven queue) exists.
    'queue_conversions' => env('GALLERY_QUEUE_CONVERSIONS', false),

    // Largest photo accepted, in KB. Photos are uploaded in chunks, so this is
    // not limited by PHP's upload_max_filesize / post_max_size.
    'max_upload_kb' => env('GALLERY_MAX_UPLOAD_KB', 51200),

    // Size of one upload chunk in KB. Empty: derived from upload_max_filesize
    // and post_max_size (at most 8 MB), which suits most hosts.
    'upload_chunk_kb' => env('GALLERY_UPLOAD_CHUNK_KB'),

    'accepted_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/avif',
    ],

    /*
    |--------------------------------------------------------------------------
    | Themes & plugins
    |--------------------------------------------------------------------------
    |
    | Themes live in /themes/{slug}, plugins in /plugins/{slug}. Each folder
    | carries a manifest (theme.json / plugin.json). Which theme is active and
    | which plugins are enabled is stored in the `settings` table and managed
    | from the admin panel; the values below are fallbacks.
    |
    */

    'themes_path' => base_path('themes'),
    // Comma-separated plugin slugs that are always enabled (e.g. for automated
    // deployments). Plugins can also be enabled in Admin → Plugins.
    'plugins' => env('GALLERY_PLUGINS', ''),
    'plugins_path' => base_path('plugins'),
    'default_theme' => env('GALLERY_THEME', 'default'),

    // Whether themes and plugins can be uploaded as zip files in the admin
    // (by people with the permission "Upload themes and plugins"). Uploaded
    // extensions run code on the server; false allows only copying folders.
    'extension_uploads' => env('GALLERY_EXTENSION_UPLOADS', true),

    // GitHub repository ("owner/name") whose releases the update check reads
    // (App\Support\UpdateCheck). Forks set their own; empty means no checks.
    'update_repository' => env('GALLERY_UPDATE_REPOSITORY', 'getopengallery/opengallery'),

    // Whether a super administrator may install updates from the admin.
    // false leaves the update check on and installing to be done by hand.
    'update_install' => env('GALLERY_UPDATE_INSTALL', true),

    // Where release files are downloaded from (a mirror or a fork's host).
    'update_download_url' => env('GALLERY_UPDATE_DOWNLOAD_URL', 'https://github.com'),

    // Public Ed25519 keys (base64) that releases must be signed with, created
    // by `php artisan gallery:release-keys`. Without one, nothing is installed.
    'update_keys' => [
        'stable' => env('GALLERY_UPDATE_KEY_STABLE', 'nyhAR2XRpb6vu6y/eLzniVtlIGaBjBv1KLTTB7NbXRE='),
        'dev' => env('GALLERY_UPDATE_KEY_DEV', 'ItWMl/bLViqH+U3pO8D3WLgXLWBp40gRAM5SCSaxi+M='),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Links use the address the visitor came through only if it is APP_URL,
    | its www / non-www twin or one of these comma-separated host names
    | (App\Http\Middleware\UseConfiguredSiteAddress). On HTTPS, browsers are
    | told to keep using it for this many seconds; 0 turns that off.
    |
    */

    'trusted_hosts' => env('GALLERY_TRUSTED_HOSTS', ''),
    'hsts_max_age' => (int) env('GALLERY_HSTS_MAX_AGE', 15552000),

    /*
    |--------------------------------------------------------------------------
    | Speed
    |--------------------------------------------------------------------------
    |
    | Live sites (APP_ENV=production) build Laravel's and Filament's caches
    | themselves and rebuild them when the code, `.env` or the plugins change
    | (App\Support\ProductionCaches). Set to false to manage them by hand.
    |
    */

    'optimize' => env('GALLERY_OPTIMIZE', true),

    // Let the web server send photo files after the gallery checked access:
    // "apache" (mod_xsendfile) or "nginx" (X-Accel-Redirect to an internal
    // location for the storage folder). Empty: PHP sends them (works everywhere).
    'sendfile' => env('GALLERY_SENDFILE'),
    'sendfile_nginx_prefix' => env('GALLERY_SENDFILE_NGINX_PREFIX', '/og-storage'),

    // What the caches were made from; compared on every request while they are built.
    'code_fingerprint' => ProductionCaches::fingerprint(),

];
