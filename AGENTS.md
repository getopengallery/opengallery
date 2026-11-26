<laravel-boost-guidelines>
=== .ai/documentation rules ===

# Documentation (`/docs`)

`/docs` is part of the deliverable for every change. This overrides the general rule to create documentation files only when asked: pages under `/docs` are always kept up to date (new ones included); outside `/docs`, create documentation files only when asked. Full writing rules are in `.claude/rules/docs.md`, which loads when you open any file under `docs/`. Hand doc updates to the `docs-writer` subagent with a short description of the change; include its report in your summary.

## When to update
- User/admin-visible change (settings, permissions, routes, share links, UI, install steps) → update `docs/user/`.
- Developer-facing change (new enum value, extension point, required config, access logic, plugin hook, build/queue requirement, `theme.json`/`plugin.json` field) → update `docs/dev/`.
- Bug fix with no behavior impact, or pure copy/styling change → usually no doc change; say so in your summary and flag stale screenshots.

## Reporting
In your final summary, list the doc files you added or updated, any screenshot placeholders (`<!-- TODO: screenshot ... -->`) and stale screenshots, or why you skipped a doc update.

=== .ai/opengallery rules ===

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
- Tests: Pest in `tests/Feature`. Docs: `docs/user/` (admins, no code) and `docs/dev/` (developers).

## Area rules

Detailed rules live in `.claude/rules/` (access model, security and settings, languages, themes and plugins, releases, docs). Claude Code loads them when you open matching files. Other agents: read the matching file before editing in that area.

## Conventions

- Pest feature tests for every behaviour change; roles are seeded automatically in feature tests (`tests/Pest.php`). Delegate writing and running tests to the `test-writer` subagent, and doc updates to the `docs-writer` subagent (after tests pass).
- Run `vendor/bin/pint --dirty --format agent` before finishing.
- Image processing uses GD by default (Imagick optional); conversions run synchronously unless `GALLERY_QUEUE_CONVERSIONS=true`.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
