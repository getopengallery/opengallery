# Contributing to OpenGallery

Thank you for helping. Bug reports, translations, documentation and code are all welcome. For a larger change, please open an issue first so we can agree on the approach.

Report security problems privately, as described in [SECURITY.md](SECURITY.md).

## Project rules

- OpenGallery must run on plain shared PHP hosting: PHP 8.3+, MySQL or MariaDB and the local filesystem. Docker, Redis, Node.js at runtime or a required queue worker are not an option. Anything heavier must be optional or a plugin.
- Photos are private by default. Read the [access model](docs/dev/architecture/access-model.md) and [security notes](docs/dev/architecture/security.md) before changing albums, photos or media.
- Every user-facing text goes through `__()` and is added to every `lang/*.json`. See [Languages and translations](docs/dev/extending/translations.md).
- Extend through [themes](docs/dev/extending/themes.md) and [plugins](docs/dev/extending/plugins.md) where you can.

## Getting started

You need PHP 8.3+ with `gd`, `exif`, `intl` and `zip`, Composer, and Node `^20.19` or `>=22.12`.

```bash
composer run setup
php artisan db:seed
composer run dev
```

## Pull requests

1. Branch from `main` and keep the change focused.
2. Add or update Pest tests for behaviour changes.
3. Update `/docs` for anything users or developers will notice. See [Writing documentation](docs/dev/contributing/documentation.md).
4. Before you push, run:

   ```bash
   php artisan test
   vendor/bin/pint
   ```

5. Add a line to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md) for notable changes.

Merging does not create a release. Maintainers follow [Releases and versions](docs/dev/contributing/releases.md).

## Licence

By contributing, you confirm that you may submit the work and agree that it is licensed under the project's licence, the [GNU Affero General Public License v3.0 or later](LICENSE).
