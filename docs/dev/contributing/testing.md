---
order: 20
---

# Testing

Every behaviour change comes with a Pest feature test. The suite runs against an in-memory SQLite database and needs no services.

## Running tests

```bash
php artisan test --compact                                   # the whole suite
php artisan test --compact tests/Feature/AlbumVisibilityTest.php
php artisan test --compact --filter="share link"
composer test                                                # clears the config cache first
```

Run the narrowest set that covers your change while you work, and the whole suite before you finish.

Format PHP before finishing:

```bash
vendor/bin/pint --dirty
```

## Setup

- **Pest 5** with the Laravel plugin. Tests live in `tests/Feature`; there is no unit test suite (`phpunit.xml` only lists `Feature`).
- **`tests/Pest.php`** binds every feature test to `Tests\TestCase` with `RefreshDatabase` and seeds `RolesAndPermissionsSeeder` before each test, so the built-in roles and permissions always exist. Create users with the factory and give them a role: `User::factory()->create()->assignRole(Role::Admin->value)`.
- **`phpunit.xml`** sets the test environment: SQLite in memory, array cache, session and mail, synchronous queue, fast password hashing (`BCRYPT_ROUNDS=4`), `GALLERY_PLUGINS=identity-providers`, so the identity-providers plugin is loaded in every test, and `GALLERY_INSTALLED=true`, so requests are not sent to the [setup wizard](../internals/setup-wizard.md). `Tests\TestCase` keeps the installation marker files of each test in a temporary folder.

Helpers in `tests/Pest.php`:

| Helper | Use |
| --- | --- |
| `uploadPhotoInChunks($target, $name, $batch, $chunkSize, $overrides)` | Uploads a generated JPEG through the chunked upload endpoint, exactly as the media picker does |
| `expectedShade($hex)` | The OKLCH value Filament derives from a hex colour, for admin theme tests |

## Fixtures

| Folder | Used by |
| --- | --- |
| `tests/Fixtures/themes/plain`, `tests/Fixtures/themes/contact-sheet` | `ThemeSeamsTest` and `ExtensionsTest`: a bare theme and a theme that uses every seam (views, error pages, package views, mail components, language files, blocks) |
| `tests/Fixtures/docs` | `DocumentationTest`: a small docs tree to test ordering, folder labels, front matter and link rewriting |

Tests that need their own themes create a `ThemeManager` for the fixture folder and bind it in the container; tests for the documentation bind a `Documentation` instance for the fixture docs.

## Tests that guard project rules

Some tests check rules across the whole project rather than one feature. When they fail, fix the cause rather than the test:

| Test | Guards |
| --- | --- |
| `TranslationFilesTest` | Every `__()` key exists in every `lang/*.json`, translations keep their `:placeholders`, and the admin and site render in German without untranslated texts |
| `DocumentationTest` | Every doc has an `order` (except `docs/README.md`), and every relative link, section anchor and image in `/docs` resolves |
| `ScrapbookThemeTest` | Core changes still work for a theme that shares nothing with the Default theme |
| `AlbumVisibilityTest` | The access rules for albums, share links, passwords and end dates |
| `ActivityCoverageTest`, `ActivityAuditTest` | Changes in the admin reach the activity log |
| `DemoSeederTest` | The demo seeder runs (with `DemoSeeder::$photoScale` reduced) |

## Writing tests

- Create test files with `php artisan make:test --pest {Name}Test`.
- Test the changed behaviour and its important failure modes, including access: a test for something new should also show that a person without permission cannot see or do it.
- Use factories and their states; do not create records by hand when a factory exists.
- Test Livewire and Filament screens through Livewire's testing helpers (see the existing `AlbumAdminTest`, `MediaLibraryTest` or `MediaPickerTest`).
- Do not delete or skip existing tests to make a change pass.
