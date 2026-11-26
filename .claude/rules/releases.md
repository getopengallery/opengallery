---
paths:
  - "VERSION"
  - "CHANGELOG.md"
  - ".github/**"
  - ".gitattributes"
  - "composer.json"
  - "resources/updater/**"
  - "app/Support/Update*.php"
  - "app/Console/**"
---

## Releases and updates
- Built frontend assets (`public/build`) ship with releases; end users never run npm. Release zips come from `php artisan gallery:release` (run by `.github/workflows/release.yml` on `v*` tags); dev-only files go in `.gitattributes` `export-ignore`. The root `VERSION` file is the only version source (`App\Support\AppVersion`); the release command stamps it. `config.platform.php` keeps the lock file installable on PHP 8.3. Update checks (`App\Support\UpdateCheck`, Admin → System → Updates, channels in `App\Enums\UpdateChannel`) report new GitHub releases, are off until an admin chooses them and send nothing about the gallery. One-click updates (`App\Support\Updates`, standalone `resources/updater/runner.php`, developer reference `docs/dev/internals/updates.md`) install only releases with a valid channel signature; the runner must stay dependency-free, and paths that must survive an update belong in `ReleasePlan`. Vite 8 needs Node ^20.19 or >=22.12 (see `.nvmrc`).
- `main` is protected: every change goes through a branch and a pull request with the five CI checks. Merging never creates a release; tag one only when the maintainer asks.
- Next version: bug or security fix = patch (`0.2.1`). New feature, database change, updater change or, during 0.x, a breaking theme/plugin change = at least the next minor (`0.3.0`). `1.0.0` only when the project is promised as stable. The highest kind of change in a release decides.
- Publish a test version (`-rc.N` or `-dev.N`) first for everything except plain fixes that touch neither the database nor the updater. Updater changes need a real update test, because the installed version's runner performs the update.
- Never create a GitHub release by hand, change a published release or reuse a tag. Stable releases are signed only on the maintainer's machine; the stable secret key never goes into GitHub.
- Steps and full rules: `docs/dev/contributing/releases.md`.
