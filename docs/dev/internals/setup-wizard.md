---
order: 60
---

# Setup wizard

A fresh upload has no `.env`, no app key and no database. The setup wizard at `/setup` takes it from there in the browser, so shared hosting needs no console. `php artisan gallery:install` remains the console route and does the same core work.

## Installed or not

`App\Support\Setup\Installation::isInstalled()` decides, once per request:

1. `config('gallery.installed')` (`GALLERY_INSTALLED`) wins when set. `phpunit.xml` sets it to `true`.
2. The marker file `storage/app/installed` means installed. The wizard's last step and `gallery:install` write it (`Installation::markInstalled()`).
3. No app key, or the marker `storage/app/setup-in-progress` (written by `Installation::begin()` while the wizard runs), means not installed.
4. Otherwise the `.env` was made by hand, or the copy is older than the wizard. If the database has a super administrator, the copy counts as installed and gets the marker. If the tables do not exist yet, the wizard opens. If the database cannot be reached, the copy counts as installed: the wizard stays closed, so nobody can rewrite `.env` while the database is down.

Deleting the marker does not reopen the wizard on a gallery that has a super administrator: rule 4 writes it again.

## Before the database exists

While not installed, `AppServiceProvider::boot()` calls `Installation::prepare()` for web requests:

- sessions and cache go to files and the queue runs synchronously (`.env` may still point at a database that does not exist);
- the in-progress marker is written, then a missing app key is generated and saved to `.env` straight away (with `APP_ENV=production` and `APP_DEBUG=false`), so encrypted cookies and the session survive the next request. If `storage/app` cannot be written, the key stays in memory and the requirements check explains why.

`Setting::get()` already survives a missing table, so `SiteSettings::apply()`, the plugin manager and the theme manager boot without a database.

`App\Http\Middleware\RedirectToSetup` (global, `bootstrap/app.php`) sends every request except `setup`, `setup/*` and `up` to `/setup` until the installation is finished; JSON requests get a 503. `App\Http\Middleware\EnsureSetupIsOpen` returns 404 for the wizard once installed and sets the wizard's language (session `setup.locale`, chosen on the first page; until then the browser's `Accept-Language`, and `Locales::default()`, which is `APP_LOCALE`, English in `.env.example`, when the browser sends none or only languages the gallery does not have).

## Steps

`App\Http\Controllers\SetupController`, routes in `routes/setup.php` (named `setup.*`), steps in `App\Enums\SetupStep`, views in `resources/views/setup` with the frame `<x-setup.layout>` (core tokens only, no theme blocks; `@vite` only when `public/build` or `public/hot` exists).

| Step | What it does |
| --- | --- |
| Welcome | Language and `App\Support\Setup\Requirements::checks()`: PHP version, extensions, a PDO driver, writable folders and `.env`, built assets |
| Database | `App\Support\Setup\DatabaseSetup`: `check()` connects with a 10 s timeout (SQLite creates `database/database.sqlite`), `apply()` writes `APP_URL` and `DB_*` to `.env`, switches the running request to the connection, runs `migrate --force` and `RolesAndPermissionsSeeder` |
| Administrator | `Installation::createSuperAdmin()` (shared with `gallery:install`): confirmed e-mail, `super-admin` role, the wizard's language as the account's language |
| Your gallery | Writes the same `SiteSettings` keys as Admin → Settings: name, default language, public albums, registration, approval, mail delivery and SMTP (password through `SiteSettings::encrypt()`), background job mode |
| Demo content | Optionally runs `DemoSeeder` with `$photoScale` at most `SetupController::DEMO_SCALE` (0.1, about 30 photos) and `DemoSeeder::$administrator` set to the new super administrator, then `markInstalled()` and renders the "ready" page in the same response |

`nextStep()` derives progress from the real state (requirements, `Installation::databaseIsReady()`, `Installation::hasSuperAdmin()`, session `setup.site`), so a step that was skipped ahead to redirects back, and a wizard that was interrupted continues where it stopped.

Once a super administrator exists, every step except Welcome needs the session that created it (session `setup.admin`) or that administrator's e-mail and password on `setup.unlock` (throttled). Without this, a stranger could switch the database or add the demo accounts, which use the password `password`, before the owner finishes.

`App\Support\Setup\EnvironmentFile::set()` changes keys in `.env` in place (a missing file starts from `.env.example`), comments a key out for `null`, double-quotes values with `\`, `"` and `$` escaped, refuses line breaks and deletes a cached config.

## Tests

`Tests\TestCase` points the marker files at a temporary folder for every test (`Installation::useDirectory()`), so tests never touch `storage/app`. `tests/Feature/Frontend/SetupTest.php` sets `gallery.installed` to null, uses a temporary `.env` (`app()->useEnvironmentPath()`) and a temporary SQLite file (`DatabaseSetup::$sqlitePath`).
