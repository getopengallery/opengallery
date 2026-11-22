---
order: 100
---

# One-click updates

A super administrator can install a new version from **System → Updates** on plain shared hosting, without a console. The code lives in `App\Support\Updates` and `resources/updater/runner.php`. Finding new versions is separate: see [Update checks](../contributing/frontend-build.md#update-checks).

## How an update runs

The work is split into short requests, because shared hosts limit how long one request may run.

While the site is online, `UpdateInstaller` does the preparation, driven by the settings page:

1. `start()` fetches `opengallery-v{version}.update.json`, verifies its signature and runs `UpdatePreflight`.
2. `advance()` downloads the zip in 4 MB `Range` requests, compares the SHA-256, validates every entry (`ReleaseArchive`) and unpacks it in portions to `storage/app/updates/staging`.
3. `handOver()` builds the `ReleasePlan`, copies the runner into the work folder and writes `storage/framework/maintenance.php`.

The maintenance file is the seam: `public/index.php` loads it before Composer or any application code. From then on, every request goes to `OpenGalleryUpdater`. Visitors get a 503 page. The administrator's browser presents a one-time key, kept on the server only as a hash, and then drives one step per request:

```text
backup -> swap -> migrate -> verify -> done
```

- **backup** boots the old application and saves the database (`DatabaseBackup`).
- **swap** renames each planned path. Every rename is recorded first, so an interrupted swap can be resumed or undone.
- **migrate** boots the new application and runs `gallery:install`.
- **verify** checks `VERSION` and sends a real request for the login page through the new HTTP kernel.

Any failure after the swap, including a fatal error, leads to:

```text
rollback -> restore -> aborted
```

If the way back fails too, the phase becomes `stuck`: the site stays offline rather than running on mixed code, and the administrator can retry. If the browser goes away, the first visitor after `STALE_SECONDS` (20 minutes) starts the recovery.

## Rules for changing it

- `runner.php` runs while the application is being replaced. It must not depend on Composer classes, except in the steps that deliberately boot Laravel.
- The runner of the **installed** version performs the update. A change to it takes effect one release later.
- Every step must be safe to run again after an interruption.
- A path that must survive an update belongs in `ReleasePlan`. Today these are `.env`, `storage`, `bootstrap/cache`, SQLite files, an existing `public/.htaccess`, files added to `public`, themes and plugins that are not in the release, and bundled ones with a newer installed version.
- `DatabaseBackup` only touches tables that OpenGallery's or a plugin's migrations create, recognised from `Schema::create` and `Schema::rename` with a literal name. A database on shared hosting may hold other applications' tables. A plugin that builds table names at runtime is not backed up.
- Migrations need no `down()` for this: a failed update restores the backup.

## Signing releases

Releases are signed with Ed25519. Stable and dev have separate keys (`config('gallery.update_keys')`), and the signature covers the version, channel, size and checksum. A stable site accepts stable releases only.

Create the keys once. They are written outside the project:

```bash
php artisan gallery:release-keys
```

Put the printed public keys into `config/gallery.php`, back up the secret keys, and store the content of `dev.key` as the repository secret `DEV_SIGNING_KEY`. **Never put `stable.key` into GitHub**: it is what keeps a compromised repository from shipping code to every stable gallery.

To publish a stable release, push the tag, wait for the draft, then sign it on your machine:

```bash
gh release download v1.2.0 --pattern 'opengallery-v1.2.0.zip'
php artisan gallery:release-sign opengallery-v1.2.0.zip --key-file="$HOME/.opengallery-signing/stable.key"
gh release upload v1.2.0 opengallery-v1.2.0.update.json
gh release edit v1.2.0 --draft=false
```

Use `--requires-from=1.1.0` when a release can only be reached from that version or later. A release without an `.update.json` is still announced, but must be installed by hand.

## Configuration

| Setting | Purpose |
| --- | --- |
| `GALLERY_UPDATE_INSTALL` | `false` keeps the update check but removes one-click install |
| `GALLERY_UPDATE_REPOSITORY` | Repository whose releases are used (forks) |
| `GALLERY_UPDATE_DOWNLOAD_URL` | Host of the release files (mirrors) |
| `GALLERY_UPDATE_KEY_STABLE`, `GALLERY_UPDATE_KEY_DEV` | Public keys, for forks with their own signing keys |
