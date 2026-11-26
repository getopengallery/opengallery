# OpenGallery project guidelines

Self-hosted photo gallery (Laravel 13, Filament 5, Spatie Permission + Media Library, Blade/Alpine/Tailwind v3).

## Hard constraints

- Must run on plain shared PHP hosting: PHP 8.3+, MySQL/MariaDB, local filesystem. No Docker, Redis, Node at runtime, or required queue worker / websockets. Anything heavier must be optional (config flag) or a plugin.
- Built frontend assets (`public/build`) ship with releases; end users never run npm.
- Photo files live on the private `gallery` disk and are served only via `MediaController`. Never expose them through `storage:link` or public URLs.
- Every user-facing string goes through `__('English text')` / `trans_choice()` and each new key is added to every `lang/*.json`. Plugins and themes never add core keys.
- Frontend views use the theme tokens and `og-*` classes, never raw `gray-*`/`red-*`/hex.
- Plugins must not require core edits; add an event or config hook to core instead.
- `main` is protected: every change goes through a branch and a pull request. Never tag or create a release unless the maintainer asks.

## Where things live

- Access logic: `Album` (`isVisibleTo`, `scopeListableFor`), `AlbumPolicy`, `PhotoPolicy`, `App\Support\AlbumAccess`, `MediaController`.
- Admin: Filament in `app/Filament` (settings pages in `Clusters/Settings`, media library in `Resources/Photos`). Site settings: `App\Support\SiteSettings`.
- Extensions: `App\Extensions\{PluginManager,ThemeManager}`, `/themes/{slug}`, `/plugins/{slug}`. Blocks: `resources/views/blocks`.
- Logging: `App\Support\ActivityLog`, `App\Observers\ActivityObserver`, pivots in `App\Models\Pivots`.
- Tests: Pest in `tests/Feature/{Frontend,Backend,System}`, one file per view (`Frontend/AlbumShowTest`, `Backend/Settings/EmailTest`); behaviour without a view goes in `System`. Shared helpers live in `tests/Helpers` (test files define no named functions). Docs: `docs/user/` (admins, no code) and `docs/dev/` (developers).

## Area rules

Detailed rules live in `.claude/rules/` (access model, security and settings, languages, themes and plugins, releases, docs). Claude Code loads them when you open matching files. Other agents: read the matching file before editing in that area.

## Conventions

- Pest feature tests for every behaviour change; roles are seeded once per run (`TestCase::$seeder`) and each test runs in a transaction; never add real sleeps (Sleep is faked, the queue worker stops instead of sleeping). Delegate writing and running tests to the `test-writer` subagent, and doc updates to the `docs-writer` subagent (after tests pass).
- Run `vendor/bin/pint --dirty --format agent` before finishing.
- Image processing uses GD by default (Imagick optional); conversions run synchronously unless `GALLERY_QUEUE_CONVERSIONS=true`.
