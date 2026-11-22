---
order: 10
---

# Frontend build

The site's CSS and JavaScript are built with Vite and Tailwind CSS. The build output in `public/build` ships inside every release, so people who install OpenGallery never run Node.js or npm. Only developers who change views, CSS or JavaScript need the toolchain.

## Requirements

- Node.js `^20.19` or `>=22.12` (Vite 8). `.nvmrc` pins the version: run `nvm use` in the project folder.
- `npm install` once, to install the packages in `package.json`.

## Commands

| Command | What it does |
| --- | --- |
| `npm run dev` | Vite dev server with hot reload, while you work on views, CSS or JS |
| `npm run build` | Production build into `public/build` (with `manifest.json`) |
| `composer run dev` | Runs `php artisan dev`: the PHP server, a queue listener, the log viewer (`pail`) and `npm run dev` together |
| `composer run setup` | First-time setup for developers: Composer install, `.env`, key, migrations, `npm install` and `npm run build` |

If a page fails with "Unable to locate file in Vite manifest" or a change does not show up, run `npm run build` (or keep `npm run dev` running).

## What is built

`vite.config.js` has three entry points (with `laravel-vite-plugin`):

| Entry | Contents | Used by |
| --- | --- | --- |
| `resources/css/app.css` | Tailwind (base, components, utilities), the self-hosted Hanken Grotesk font, the default `--og-*` tokens and the `og-*` component classes | Site |
| `resources/css/fonts.css` | Hanken Grotesk only | Admin panel (`AdminPanelProvider` loads it through `LocalFontProvider`, so the admin uses the same typeface without a font CDN) |
| `resources/js/app.js` | Alpine.js with the `ogLightbox` component (`lightbox.js`) and the `appearance` store for light/dark mode (`appearance.js`) | Site |

The admin panel's JavaScript and CSS come from Filament's own published assets, not from this build. The admin's photo uploader is an inline script (`resources/views/filament/partials/photo-uploader-script.blade.php`), so it needs no build step either.

## Tailwind and tokens

`tailwind.config.js` maps the theme tokens to Tailwind colours, so views use classes such as `bg-canvas`, `text-ink`, `border-line` or `bg-primary` and themes recolour them without a build:

- colours `canvas`, `surface`, `ink`, `muted`, `line`, `primary`, `on-primary`, `danger`, `on-danger` are `rgb(var(--og-{name}) / <alpha-value>)`, so opacity modifiers work;
- `rounded-og` is `var(--og-radius)`, `font-sans` starts with `var(--og-font-sans)`;
- the font sizes `caption`, `body`, `title` and `heading` form the type scale;
- `darkMode: 'class'`: dark styles apply under `html.dark`, the same switch the Filament admin uses (stored as `filament:theme:mode`).

Defaults for every token are in `resources/css/app.css` and in the Default theme's `theme.json`. A new colour need gets a new token in all three places (app.css, tailwind.config.js, the Default theme), never a hard-coded value. See [Writing themes](../extending/themes.md#tokens).

Tailwind only generates classes it finds in its `content` paths at build time: `resources/views`, `themes/**/*.blade.php`, Laravel's pagination views and compiled views in `storage/framework/views`. A theme installed later can only use classes that are already in the shipped build; themes should style their own markup with the tokens, the `og-*` classes or a plain stylesheet (see [Tailwind classes in theme views](../extending/themes.md#tailwind-classes-in-theme-views)).

## Fonts

Fonts are self-hosted: Hanken Grotesk comes from the `@fontsource-variable/hanken-grotesk` package and is bundled by Vite. Never load fonts or scripts from a CDN.

## Shipping a release

When to make a release and the steps to publish it are in [Releases and versions](releases.md). This section covers how the zip is built.

`public/build` and Filament's published assets (`public/css/filament`, `public/js/filament`, `public/fonts/filament`) are in `.gitignore`, so the repository does not contain them. Release archives are built with:

```bash
php artisan gallery:release v1.2.0
```

(`composer release` runs it without a version, which then comes from `git describe`.) The command (`App\Console\Commands\BuildRelease`) exports the last commit with `git archive` into a temporary folder, runs `composer install --no-dev --optimize-autoloader` there (which also publishes Filament's assets through `filament:upgrade`), `npm ci` and `npm run build`, removes `node_modules` and the files only the build needs, writes the version into `VERSION`, and creates `opengallery-v1.2.0.zip` with everything under an `opengallery/` folder. `--output=` chooses the folder for the zip. Uncommitted changes are not included; the command warns about them.

It needs git, tar, Composer and Node `^20.19` or `>=22.12` on the machine that builds; it stops early with an older Node, because npm then silently skips Vite's native build tool. Files only developers need (tests, agent and editor tooling, `phpunit.xml`, ...) are marked `export-ignore` in `.gitattributes` and stay out of the zip; add new ones there. Files the frontend build needs but the server does not are listed in `BuildRelease::BUILD_ONLY`.

### Versions

The root `VERSION` file is the only source of the version, read through `App\Support\AppVersion`. In the repository it names the version in development (`0.2.0-dev`). The release command replaces it in the zip, so tag `v1.2.0` becomes version `1.2.0`. Versions follow Semantic Versioning, and a tag with a suffix (`-dev`, `-alpha`, `-beta` or `-rc`, optionally with a number such as `v1.3.0-dev.1`) is a prerelease.

### Update checks

`App\Support\UpdateCheck` reads the published releases of `config('gallery.update_repository')` (`GALLERY_UPDATE_REPOSITORY`, default `getopengallery/opengallery`; empty turns checks off on that server) through GitHub's public REST API. It only reports a new version and never downloads anything.

- It runs daily in `BackgroundJobs::tasks()` while `SiteSettings::updateChecksEnabled()` is on, and at most once per `UpdateCheck::MIN_SECONDS`, because GitHub allows 60 anonymous requests per hour for each IP address and shared hosts share one.
- `App\Enums\UpdateChannel`: `stable` sees releases that have no suffix and are not marked as prereleases on GitHub. `dev` sees those and prereleases, whichever is newest.
- The newest release of both channels is stored in the setting `updates.status`, so changing the channel or installing a version needs no new request. A failed request keeps the last result.
- Only `tag_name`, `draft` and `prerelease` are used, and the release link is built from the repository and tag. Release titles and notes never reach the admin.
- The request has a generic user agent and carries nothing about the gallery.

To publish a version for the dev channel, push a tag such as `v1.3.0-dev.1` and publish its draft release. Drafts are invisible to the check. Installing a release is described in [One-click updates](../internals/updates.md).

### PHP compatibility

`config.platform.php` in `composer.json` is `8.3.0`, so `composer update` cannot lock a package that needs a newer PHP. Raise it only when the minimum PHP version changes, together with `Requirements::PHP_VERSION`, the CI matrix and the docs.

### Automated releases

`.github/workflows/ci.yml` runs the tests on PHP 8.3 and 8.4 and checks the code style on pull requests and pushes to `main`.

`.github/workflows/release.yml` runs on every pushed version tag:

```bash
git tag v1.2.0
git push origin v1.2.0
```

It runs the same tests, builds the zip with `gallery:release` and creates a **draft** GitHub release with the zip, a SHA-256 checksum and generated notes. Prereleases are signed in CI with the `DEV_SIGNING_KEY` secret. Stable releases must be [signed on your own machine](../internals/updates.md#signing-releases) before you publish the draft. Only the final job has write access to the repository.

CI also runs the database backup tests on real MySQL and MariaDB servers.
