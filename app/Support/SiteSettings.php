<?php

namespace App\Support;

use App\Enums\ActivityLevel;
use App\Enums\BackgroundJobMode;
use App\Enums\MailDelivery;
use App\Enums\PhotoOrder;
use App\Enums\UpdateChannel;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Site configuration edited in Admin → Settings, stored in the `settings`
 * table. Values not set there fall back to config / .env.
 *
 * apply() copies the values into config at boot, so code reading
 * config('app.name'), config('gallery.*'), config('mail.*') or
 * config('media-library.max_file_size') sees the admin's choices.
 */
class SiteSettings
{
    public const NAME = 'site.name';

    public const SHOW_NAME = 'site.show_name';

    /** Short text about the site for search results and link previews (App\Support\PageMeta). */
    public const DESCRIPTION = 'site.description';

    /** Search engines may index the site (robots.txt, sitemap, robots meta; App\Support\SearchEngines). On by default. */
    public const SEARCH_INDEXING = 'seo.indexing';

    /** Ask AI crawlers (SearchEngines::AI_CRAWLERS) to stay away, also while search engines are welcome. On by default. */
    public const BLOCK_AI_CRAWLERS = 'seo.block_ai_crawlers';

    /** Extra lines the admin adds to robots.txt. */
    public const ROBOTS_EXTRA = 'seo.robots_extra';

    /** Verification code from Google Search Console (content of its "google-site-verification" meta tag). */
    public const GOOGLE_VERIFICATION = 'seo.google_verification';

    /** Verification code from Bing Webmaster Tools (content of its "msvalidate.01" meta tag). */
    public const BING_VERIFICATION = 'seo.bing_verification';

    /** Default language (a code from App\Support\Locales::available()). */
    public const LOCALE = 'site.locale';

    public const LOGO = 'site.logo';

    public const LOGO_DARK = 'site.logo_dark';

    public const FAVICON = 'site.favicon';

    /** Square image for home screens when the site is installed as an app (App\Support\WebApp). */
    public const APP_ICON = 'site.app_icon';

    /** The site can be installed as an app (manifest + service worker, App\Support\WebApp). On by default. */
    public const WEB_APP = 'site.web_app';

    public const REGISTRATION = 'access.registration';

    public const PUBLIC_ALBUMS = 'access.public_albums';

    /** People who sign up themselves wait for an administrator's approval (AccountApproval). */
    public const REQUIRE_APPROVAL = 'access.require_approval';

    /** Tell people who manage users when someone creates an account (AccountNotices). */
    public const NOTIFY_REGISTRATIONS = 'access.notify_registrations';

    public const GUEST_HOME = 'access.guest_home';

    /** Restricted albums can be shared by e-mail with people without an account (AlbumGuests). */
    public const ALBUM_GUESTS = 'access.album_guests';

    /** Days an album shared by e-mail stays open to that guest; 0 = no limit. */
    public const ALBUM_GUEST_DAYS = 'access.album_guest_days';

    /** @deprecated Old on/off switch; read only as fallback for ACTIVITY_LEVEL. */
    public const ACTIVITY_LOG = 'access.activity_log';

    public const ACTIVITY_LEVEL = 'activity.level';

    public const ACTIVITY_RETENTION = 'activity.retention_days';

    public const DEFAULT_PHOTO_ORDER = 'albums.default_photo_order';

    public const MAIL_DELIVERY = 'mail.delivery';

    public const MAIL_HOST = 'mail.host';

    public const MAIL_PORT = 'mail.port';

    /** "smtp" (STARTTLS when offered) or "smtps" (TLS from the start, port 465). */
    public const MAIL_SCHEME = 'mail.scheme';

    public const MAIL_USERNAME = 'mail.username';

    /** Stored encrypted with the app key. */
    public const MAIL_PASSWORD = 'mail.password';

    public const MAIL_FROM_ADDRESS = 'mail.from_address';

    public const MAIL_FROM_NAME = 'mail.from_name';

    /** Most e-mails sent per hour; notices to many people wait for the next hour (App\Support\MailQueue). */
    public const MAIL_HOURLY_LIMIT = 'mail.hourly_limit';

    public const MAIL_HOURLY_DEFAULT = 200;

    public const MAIL_HOURLY_MAX = 100000;

    /** What starts the background jobs (App\Enums\BackgroundJobMode, see App\Support\BackgroundJobs). */
    public const BACKGROUND_JOBS = 'jobs.mode';

    /** Look for new versions once a day (App\Support\UpdateCheck). Off until an admin chooses it. */
    public const UPDATE_CHECKS = 'updates.enabled';

    /** Which releases the update check reports (App\Enums\UpdateChannel). */
    public const UPDATE_CHANNEL = 'updates.channel';

    public const UPLOAD_MAX_MB = 'uploads.max_mb';

    /** Whether visitors get photo files without GPS data (on unless turned off). */
    public const REMOVE_PHOTO_LOCATION = 'photos.remove_location';

    /** Site-wide switch for downloads; albums can only allow them while it is on. Off by default. */
    public const DOWNLOADS_ENABLED = 'photos.downloads_enabled';

    /** Logged-in people can keep favourite photos (App\Support\Favorites). On by default. */
    public const FAVORITES_ENABLED = 'photos.favorites_enabled';

    /** The lightbox's "i" button shows a photo's description and camera details. On by default. */
    public const PHOTO_INFO_ENABLED = 'photos.info_enabled';

    /** The lightbox can play the photos as a slideshow. On by default. */
    public const SLIDESHOW_ENABLED = 'photos.slideshow_enabled';

    /** Seconds each photo is shown in the slideshow. */
    public const SLIDESHOW_SECONDS = 'photos.slideshow_seconds';

    public const SLIDESHOW_SECONDS_DEFAULT = 5;

    public const SLIDESHOW_SECONDS_MIN = 2;

    public const SLIDESHOW_SECONDS_MAX = 60;

    /** List of mime types (keys of PhotoUploads::TYPES). */
    public const UPLOAD_TYPES = 'uploads.accepted_types';

    /** Albums and photos can be tagged and found by tag (App\Support\Tagging). On by default. */
    public const TAGGING = 'tags.enabled';

    /** People may create tags besides the predefined ones. On by default. */
    public const TAGS_ALLOW_CUSTOM = 'tags.allow_custom';

    /** Suggest every tag in use, not only the ones a person can reach. Off by default: tag names can be private. */
    public const TAGS_GLOBAL_SUGGESTIONS = 'tags.global_suggestions';

    /** List of tag names offered to everyone. */
    public const TAGS_PREDEFINED = 'tags.predefined';

    /** Photos carry a license (App\Support\Licensing). Off by default. */
    public const LICENSING = 'licenses.enabled';

    /** Id of the license new photos get. */
    public const LICENSE_DEFAULT = 'licenses.default';

    /** Uploaders may give their photos another license than the default. On by default. */
    public const LICENSES_ALLOW_CHOICE = 'licenses.allow_choice';

    /** List of license ids uploaders may pick from; not set means every active license. */
    public const LICENSES_CHOOSABLE = 'licenses.choosable';

    /** Allowed range for the largest photo, in MB. */
    public const UPLOAD_MIN_MB = 1;

    public const UPLOAD_MAX_MB_LIMIT = 2048;

    /** Allowed range for how long activity log entries are kept, in days. */
    public const RETENTION_MIN_DAYS = 1;

    public const RETENTION_MAX_DAYS = 3650;

    /** Disk + folder for uploaded branding files (private; served by BrandingController). */
    public const DISK = 'local';

    public const DIRECTORY = 'branding';

    /** Uploadable branding assets: route key => setting key. */
    public const ASSETS = [
        'logo' => self::LOGO,
        'logo-dark' => self::LOGO_DARK,
        'favicon' => self::FAVICON,
        'app-icon' => self::APP_ICON,
    ];

    public static function apply(): void
    {
        config([
            'app.name' => Setting::get(self::NAME) ?: config('app.name'),
            'gallery.default_locale' => self::storedLocale(),
            'gallery.registration_enabled' => (bool) Setting::get(self::REGISTRATION, config('gallery.registration_enabled')),
            'gallery.public_albums_enabled' => (bool) Setting::get(self::PUBLIC_ALBUMS, config('gallery.public_albums_enabled')),
        ]);

        $level = self::storedActivityLevel();
        config([
            'gallery.activity_log_level' => $level->value,
            'gallery.activity_retention_days' => self::storedRetentionDays(),
            'gallery.default_photo_order' => (PhotoOrder::tryFrom((string) Setting::get(self::DEFAULT_PHOTO_ORDER)) ?? self::configPhotoOrder())->value,
        ]);

        $maxKb = self::storedMaxUploadKb();
        config([
            'gallery.max_upload_kb' => $maxKb,
            'media-library.max_file_size' => $maxKb * 1024,
            'gallery.accepted_mime_types' => self::storedUploadTypes(),
        ]);

        config(self::mailConfig(self::storedMail()));
    }

    /** The default language: the admin's choice, else APP_LOCALE, else English. */
    private static function storedLocale(): string
    {
        foreach ([Setting::get(self::LOCALE), self::original('app.locale')] as $locale) {
            if (Locales::isAvailable($locale)) {
                return $locale;
            }
        }

        return Locales::SOURCE;
    }

    /**
     * Config values for the given mail settings (as stored, password in plain
     * text). Empty values keep what config / .env already has; the sender
     * name falls back to the site name.
     *
     * @param  array{delivery?: ?string, host?: ?string, port?: int|string|null, scheme?: ?string, username?: ?string, password?: ?string, from_address?: ?string, from_name?: ?string}  $mail
     * @return array<string, mixed>
     */
    public static function mailConfig(array $mail): array
    {
        $delivery = MailDelivery::tryFrom((string) ($mail['delivery'] ?? '')) ?? MailDelivery::Server;
        $smtp = $delivery === MailDelivery::Smtp;

        return [
            'mail.default' => $delivery->mailer() ?? self::original('mail.default'),
            'mail.from.address' => ($mail['from_address'] ?? null) ?: self::original('mail.from.address'),
            'mail.from.name' => ($mail['from_name'] ?? null) ?: (self::original('mail.from.name') ?: config('app.name')),
            // A MAIL_URL from .env would win over the fields below.
            'mail.mailers.smtp.url' => $smtp ? null : self::original('mail.mailers.smtp.url'),
            'mail.mailers.smtp.host' => $smtp ? ($mail['host'] ?? null) : self::original('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => $smtp ? ((int) ($mail['port'] ?? 0) ?: 587) : self::original('mail.mailers.smtp.port'),
            'mail.mailers.smtp.scheme' => $smtp ? (($mail['scheme'] ?? null) === 'smtps' ? 'smtps' : 'smtp') : self::original('mail.mailers.smtp.scheme'),
            'mail.mailers.smtp.username' => $smtp ? (($mail['username'] ?? null) ?: null) : self::original('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $smtp ? (($mail['password'] ?? null) ?: null) : self::original('mail.mailers.smtp.password'),
        ];
    }

    /**
     * The value $key had in config / .env before any setting was applied, so
     * applying again (after saving) falls back to it, not to an old setting.
     */
    private static function original(string $key): mixed
    {
        $stash = 'gallery.env_defaults.'.str_replace('.', '_', $key);

        if (! config()->has($stash)) {
            config([$stash => config($key)]);
        }

        return config($stash);
    }

    /**
     * Mail settings as stored, password decrypted.
     *
     * @return array{delivery: ?string, host: ?string, port: ?int, scheme: ?string, username: ?string, password: ?string, from_address: ?string, from_name: ?string}
     */
    public static function storedMail(): array
    {
        return [
            'delivery' => Setting::get(self::MAIL_DELIVERY),
            'host' => Setting::get(self::MAIL_HOST),
            'port' => Setting::get(self::MAIL_PORT),
            'scheme' => Setting::get(self::MAIL_SCHEME),
            'username' => Setting::get(self::MAIL_USERNAME),
            'password' => self::decrypt(Setting::get(self::MAIL_PASSWORD)),
            'from_address' => Setting::get(self::MAIL_FROM_ADDRESS),
            'from_name' => Setting::get(self::MAIL_FROM_NAME),
        ];
    }

    public static function mailHourlyLimit(): int
    {
        $limit = (int) Setting::get(self::MAIL_HOURLY_LIMIT, self::MAIL_HOURLY_DEFAULT);

        return $limit > 0 ? min($limit, self::MAIL_HOURLY_MAX) : self::MAIL_HOURLY_DEFAULT;
    }

    public static function mailDelivery(): MailDelivery
    {
        return MailDelivery::tryFrom((string) Setting::get(self::MAIL_DELIVERY)) ?? MailDelivery::Server;
    }

    public static function hasMailPassword(): bool
    {
        return filled(Setting::get(self::MAIL_PASSWORD));
    }

    public static function encrypt(?string $secret): ?string
    {
        return blank($secret) ? null : Crypt::encryptString($secret);
    }

    /** Null when nothing is stored or the app key changed since it was saved. */
    private static function decrypt(mixed $payload): ?string
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Largest photo accepted, in MB. */
    public static function maxUploadMb(): int
    {
        return max(self::UPLOAD_MIN_MB, (int) round((int) config('gallery.max_upload_kb') / 1024));
    }

    /** @return list<string> mime types that may be uploaded */
    public static function uploadTypes(): array
    {
        return array_values((array) config('gallery.accepted_mime_types'));
    }

    private static function storedMaxUploadKb(): int
    {
        $mb = (int) Setting::get(self::UPLOAD_MAX_MB);

        return $mb > 0
            ? min(self::UPLOAD_MAX_MB_LIMIT, $mb) * 1024
            : (int) self::original('gallery.max_upload_kb');
    }

    /** @return list<string> */
    private static function storedUploadTypes(): array
    {
        $types = array_values(array_intersect(array_keys(PhotoUploads::TYPES), (array) Setting::get(self::UPLOAD_TYPES, [])));

        return $types ?: array_values((array) self::original('gallery.accepted_mime_types'));
    }

    public static function activityLevel(): ActivityLevel
    {
        return ActivityLevel::tryFrom((string) config('gallery.activity_log_level')) ?? ActivityLevel::All;
    }

    /** How long activity log entries are kept before the scheduled cleanup removes them. */
    public static function activityRetentionDays(): int
    {
        return (int) config('gallery.activity_retention_days');
    }

    private static function storedRetentionDays(): int
    {
        $days = (int) (Setting::get(self::ACTIVITY_RETENTION) ?? config('gallery.activity_retention_days'));

        return max(self::RETENTION_MIN_DAYS, min(self::RETENTION_MAX_DAYS, $days ?: 365));
    }

    /** What starts the background jobs; AJAX until an administrator chooses otherwise. */
    public static function backgroundJobs(): BackgroundJobMode
    {
        return BackgroundJobMode::tryFrom((string) Setting::get(self::BACKGROUND_JOBS)) ?? BackgroundJobMode::Ajax;
    }

    public static function updateChecksEnabled(): bool
    {
        return (bool) Setting::get(self::UPDATE_CHECKS, false);
    }

    public static function updateChannel(): UpdateChannel
    {
        return UpdateChannel::tryFrom((string) Setting::get(self::UPDATE_CHANNEL)) ?? UpdateChannel::Stable;
    }

    /** Photo order new albums start with. */
    public static function defaultPhotoOrder(): PhotoOrder
    {
        return PhotoOrder::tryFrom((string) config('gallery.default_photo_order')) ?? PhotoOrder::Manual;
    }

    /** The level chosen in Settings; installs that only had the old on/off switch keep their choice. */
    private static function storedActivityLevel(): ActivityLevel
    {
        $level = ActivityLevel::tryFrom((string) Setting::get(self::ACTIVITY_LEVEL));

        if ($level) {
            return $level;
        }

        $legacy = Setting::get(self::ACTIVITY_LOG);
        if ($legacy !== null) {
            return $legacy ? ActivityLevel::All : ActivityLevel::Off;
        }

        return ActivityLevel::tryFrom((string) config('gallery.activity_log_level')) ?? ActivityLevel::All;
    }

    private static function configPhotoOrder(): PhotoOrder
    {
        return PhotoOrder::tryFrom((string) config('gallery.default_photo_order')) ?? PhotoOrder::Manual;
    }

    public static function name(): string
    {
        return (string) config('app.name');
    }

    public static function showName(): bool
    {
        return (bool) Setting::get(self::SHOW_NAME, true);
    }

    public static function description(): ?string
    {
        return Setting::get(self::DESCRIPTION) ?: null;
    }

    /** Whether search engines may index the site; link previews work either way. */
    public static function searchIndexing(): bool
    {
        return (bool) Setting::get(self::SEARCH_INDEXING, true);
    }

    public static function blocksAiCrawlers(): bool
    {
        return (bool) Setting::get(self::BLOCK_AI_CRAWLERS, true);
    }

    public static function robotsExtra(): ?string
    {
        return Setting::get(self::ROBOTS_EXTRA) ?: null;
    }

    public static function googleVerification(): ?string
    {
        return Setting::get(self::GOOGLE_VERIFICATION) ?: null;
    }

    public static function bingVerification(): ?string
    {
        return Setting::get(self::BING_VERIFICATION) ?: null;
    }

    public static function registrationEnabled(): bool
    {
        return (bool) config('gallery.registration_enabled');
    }

    public static function publicAlbumsEnabled(): bool
    {
        return (bool) config('gallery.public_albums_enabled');
    }

    public static function requiresApproval(): bool
    {
        return (bool) Setting::get(self::REQUIRE_APPROVAL, false);
    }

    public static function removesPhotoLocation(): bool
    {
        return (bool) Setting::get(self::REMOVE_PHOTO_LOCATION, true);
    }

    public static function downloadsEnabled(): bool
    {
        return (bool) Setting::get(self::DOWNLOADS_ENABLED, false);
    }

    public static function favoritesEnabled(): bool
    {
        return (bool) Setting::get(self::FAVORITES_ENABLED, true);
    }

    public static function photoInfoEnabled(): bool
    {
        return (bool) Setting::get(self::PHOTO_INFO_ENABLED, true);
    }

    public static function slideshowEnabled(): bool
    {
        return (bool) Setting::get(self::SLIDESHOW_ENABLED, true);
    }

    public static function slideshowSeconds(): int
    {
        $seconds = (int) Setting::get(self::SLIDESHOW_SECONDS, self::SLIDESHOW_SECONDS_DEFAULT);

        return max(self::SLIDESHOW_SECONDS_MIN, min(self::SLIDESHOW_SECONDS_MAX, $seconds ?: self::SLIDESHOW_SECONDS_DEFAULT));
    }

    public static function albumGuestsEnabled(): bool
    {
        return (bool) Setting::get(self::ALBUM_GUESTS, true);
    }

    /** Days a guest keeps access after being invited; 0 means no limit. */
    public static function albumGuestDays(): int
    {
        return max(0, (int) Setting::get(self::ALBUM_GUEST_DAYS, 30));
    }

    public static function notifiesAboutRegistrations(): bool
    {
        return (bool) Setting::get(self::NOTIFY_REGISTRATIONS, false);
    }

    public static function taggingEnabled(): bool
    {
        return (bool) Setting::get(self::TAGGING, true);
    }

    public static function allowsCustomTags(): bool
    {
        return (bool) Setting::get(self::TAGS_ALLOW_CUSTOM, true);
    }

    public static function globalTagSuggestions(): bool
    {
        return (bool) Setting::get(self::TAGS_GLOBAL_SUGGESTIONS, false);
    }

    /** @return list<string> */
    public static function predefinedTags(): array
    {
        return array_values((array) Setting::get(self::TAGS_PREDEFINED, []));
    }

    public static function licensingEnabled(): bool
    {
        return (bool) Setting::get(self::LICENSING, false);
    }

    public static function defaultLicenseId(): ?int
    {
        $id = Setting::get(self::LICENSE_DEFAULT);

        return filled($id) ? (int) $id : null;
    }

    public static function allowsLicenseChoice(): bool
    {
        return (bool) Setting::get(self::LICENSES_ALLOW_CHOICE, true);
    }

    /** @return list<int>|null null while not set: every active license may be picked */
    public static function choosableLicenseIds(): ?array
    {
        $ids = Setting::get(self::LICENSES_CHOOSABLE);

        return $ids === null ? null : array_values(array_map('intval', (array) $ids));
    }

    /** What guests see at "/" while public albums are off: "landing" or "login". */
    public static function guestHome(): string
    {
        return Setting::get(self::GUEST_HOME) === 'login' ? 'login' : 'landing';
    }

    /** Stored path of a branding asset ("logo", "logo-dark", "favicon", "app-icon"), if it exists. */
    public static function assetPath(string $asset): ?string
    {
        $key = self::ASSETS[$asset] ?? null;
        $path = $key ? Setting::get($key) : null;

        return is_string($path) && $path !== '' && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    /** Public URL of a branding asset, cache-busted by file name. */
    public static function assetUrl(string $asset): ?string
    {
        $path = self::assetPath($asset);

        return $path ? route('site.asset', ['asset' => $asset, 'v' => substr(md5($path), 0, 8)]) : null;
    }

    public static function faviconUrl(): string
    {
        return self::assetUrl('favicon') ?? asset('favicon.svg');
    }
}
