---
order: 30
---

# Commands and schedule

OpenGallery adds three artisan commands and one scheduled task to Laravel's own. Nothing else needs to run in the background.

## Artisan commands

### `gallery:install`

```bash
php artisan gallery:install [--name=] [--email=] [--password=]
```

`App\Console\Commands\InstallGallery`. Used for installing and for every upgrade:

1. runs `migrate --force`;
2. runs `RolesAndPermissionsSeeder`, which creates new permissions and gives the built-in roles only the permissions it has not given them before; changes made in the admin are kept (see [Permissions and roles](../architecture/access-model.md#permissions-and-roles));
3. if no super administrator exists, asks for name, e-mail and password (at least 12 characters, validated) or takes them from the options and creates the user through `App\Support\Setup\Installation::createSuperAdmin()` (confirmed e-mail address, `super-admin` role). Otherwise it stops there;
4. writes the marker `storage/app/installed`, which closes the [setup wizard](../internals/setup-wizard.md).

The options exist for hosts where commands can only run through cron or a console that cannot answer questions.

### `gallery:prune-uploads`

```bash
php artisan gallery:prune-uploads
```

Defined in `routes/console.php`; calls `App\Support\PhotoUploads::prune()`, which removes chunked uploads (and their `.done` markers) under `storage/app/private/upload-chunks` that are older than a day, and prints how many it removed. See [Photo uploads](../internals/uploads.md#housekeeping).

### `gallery:background-jobs`

```bash
php artisan gallery:background-jobs
```

Defined in `routes/console.php`; makes one pass of the background jobs (`App\Support\BackgroundJobs::run()`) as the "cron" trigger and says whether it ran or was skipped (less than a minute after the last pass, or one is still running). Usually started by `schedule:run`; see [Background jobs](#background-jobs).

### Laravel commands worth knowing

| Command | Use in OpenGallery |
| --- | --- |
| `php artisan key:generate` | Once per installation; `APP_KEY` also encrypts stored SMTP and plugin secrets and keys the guest visitor hash |
| `php artisan model:prune` | Deletes activity log entries older than the retention period (`ActivityEvent` is mass-prunable) |
| `php artisan schedule:run` | Runs `gallery:background-jobs`; called every minute by cron when System → Background jobs is "Cron" |
| `php artisan schedule:list` | Shows the scheduled task |
| `php artisan queue:work` | Optional with `GALLERY_QUEUE_CONVERSIONS=true`; the background jobs also work through the database queue |
| `php artisan dev` | Development: PHP server, queue listener, log viewer and Vite together (`composer run dev`) |
| `php artisan db:seed --class=DemoSeeder` | Development: demo content (see [Demo data](demo-data.md)) |
| `php artisan gallery:release-keys [--output=]` | Maintainers: creates the signing keys for releases (see [One-click updates](../internals/updates.md#signing-releases)) |
| `php artisan gallery:release-sign {zip} [--key-file=] [--requires-from=]` | Maintainers: writes the signed `.update.json` next to a release zip |
| `php artisan gallery:release [version] [--output=]` | Development: builds the release zip for shared hosting (`composer release`; see [Shipping a release](frontend-build.md#shipping-a-release)) |

## Background jobs

`App\Support\BackgroundJobs` runs the regular housekeeping. One pass (`run()`) happens at most every `MIN_SECONDS` (55 s), guarded by a cache lock, and:

1. runs each task in `BackgroundJobs::tasks()` whose own interval has passed (last runs are stored in the setting `jobs.tasks`):

   | Task | Interval | Why |
   | --- | --- | --- |
   | `model:prune` | daily | Activity log retention (`config('gallery.activity_retention_days')`) |
   | `PhotoUploads::prune()` | daily | Unfinished chunked uploads |
   | `UpdateCheck::run()` | daily | Reads the newest releases while the admin has update checks on; see [Update checks](frontend-build.md#update-checks) |
   | `UpdateInstaller::prune()` | daily | Update preparations nobody finished and leftovers of a finished update |
   | `SmallThumbnails::makeMissing()` | every pass | The `small` size for photos uploaded before it existed, made from their `thumb`, at most 15 s per pass |
   | `SiteHealth::refresh()` | `SiteHealth::REFRESH_SECONDS` (5 min) | The site health checks that scan tables and files (pending migrations, stuck and failed jobs, missing thumbnails), cached for `LATE_SECONDS`; the widget only reads them and runs them itself only when no result is cached. Then `SiteHealthNotices::check()` tells people who change settings about new problems |
   | `Inbox::prune()` | daily | Notifications older than `Inbox::KEEP_DAYS` (180 days) |

2. sends waiting e-mails in a limited portion (`MailQueue::work()`, see [The mail queue](../internals/emails.md#the-mail-queue));
3. with `GALLERY_QUEUE_CONVERSIONS=true` on the `database` queue, runs `queue:work --stop-when-empty` in-process for what is left of `QUEUE_SECONDS` (20 s, at least 5 s), so shared hosting needs no separate worker.

The last pass is stored in the setting `jobs.last_run` (`lastRun()`: time and trigger). `isLate()` feeds the "Background jobs do not run" check of the site health widget: no pass for `LATE_SECONDS` (26 hours), or none ever while cron or webcron is chosen.

### Triggers

What starts a pass is chosen in Admin → System → Background jobs (`SiteSettings::backgroundJobs()`, enum `App\Enums\BackgroundJobMode`, setting `jobs.mode`, default `ajax`):

| Mode | Trigger |
| --- | --- |
| `ajax` | `resources/views/partials/background-jobs.blade.php` (in both site layouts and the admin's `SCRIPTS_AFTER` render hook) prints a `navigator.sendBeacon()` to `POST /background-jobs` (`background-jobs.ajax`) while `BackgroundJobs::ajaxDue()`: 5 minutes after the last pass, 1 minute while the queue is worked |
| `webcron` | `GET` or `POST /cron/{token}` (`background-jobs.webcron`); 404 unless the mode is `webcron` and the token matches the setting `jobs.webcron_token` (`renewWebcronToken()` replaces it) |
| `cron` | `routes/console.php` schedules `gallery:background-jobs` every minute; `schedule:run` from cron starts it in every mode |

Both web routes are exempt from CSRF (`bootstrap/app.php`) and throttled. `BackgroundJobs::lastSeen($mode)` (cache) records when each trigger last called `run()`, even when the pass was skipped; the settings page uses it to warn while cron or webcron has not been set up.

A new periodic task belongs in `BackgroundJobs::tasks()` with its interval, not in its own `Schedule` entry, so it also runs with AJAX and webcron.

Without any trigger, old activity log entries stay, and unfinished uploads are only removed when about one in 50 new uploads triggers `PhotoUploads::prune()` on its first chunk.

## Composer scripts

| Script | Runs |
| --- | --- |
| `composer run setup` | `composer install`, copies `.env.example` to `.env` if missing, `key:generate`, `migrate --force`, `npm install --ignore-scripts`, `npm run build` |
| `composer run dev` | `php artisan dev` without a process timeout |
| `composer test` | `config:clear`, then `php artisan test` |
| `composer release` | `php artisan gallery:release` without a process timeout |

After `composer install` (and every autoload dump), `filament:upgrade` publishes Filament's assets.

## Seeders

| Seeder | Creates |
| --- | --- |
| `RolesAndPermissionsSeeder` | Permissions and built-in roles; idempotent, run by `gallery:install` and before every feature test |
| `DatabaseSeeder` | Roles, plus `admin@example.com` (super admin) and `test@example.com` (user), both with the password `password`. Development only. |
| `DemoSeeder`, `DemoPagesSeeder` | Demo people, albums, photos, pages and a year of activity. For development, and offered (smaller) by the setup wizard; see [Demo data](demo-data.md). |
