---
order: 20
---

# Testing

Every behaviour change comes with a Pest feature test. The suite runs against an in-memory SQLite database and needs no services.

## Running tests

```bash
php artisan test --compact                                   # the whole suite
php artisan test --compact tests/Feature/System/AccessModelTest.php
php artisan test --compact --filter="share link"
composer test                                                # clears the config cache first
```

Run the narrowest set that covers your change while you work, and the whole suite before you finish.

Format PHP before finishing:

```bash
vendor/bin/pint --dirty
```

## Layout

Every test is a Pest feature test under `tests/Feature`, bound to `Tests\TestCase` with `RefreshDatabase`. The folders say what a test is about:

| Folder | Holds | Examples |
| --- | --- | --- |
| `tests/Feature/Frontend` | One file per public view | `HomeTest`, `SearchTest` (`/?q=`), `MyAlbumsTest`, `AlbumShowTest`, `FavoritesTest`, `ProfileTest`, `PageTest`, `LayoutTest`, `MediaTest`, `WebAppTest`, `SetupTest`, `ScrapbookThemeTest`, and `Auth/*` for the sign-in, registration and password views |
| `tests/Feature/Backend` | One file per Filament page or resource | `PanelTest` (admin shell, navigation and access to every settings page), `DashboardTest`, `AlbumsTest`, `AlbumCollaboratorsTest`, `MediaLibraryTest`, `MediaPickerTest`, `UsersTest`, `GroupsTest`, `RolesTest`, `PagesTest`, `ActivityLogTest`, `PluginsTest`, `ThemesTest`, `ThemeSettingsTest`, `DocumentationTest`, and `Settings/<Page>Test.php` for each settings page |
| `tests/Feature/System` | Behaviour that is not tied to one view | `AccessModelTest`, `ActivityRecordingTest`, `MailQueueTest`, `SecurityTest`, `PhotoLocationTest`, `TranslationFilesTest`, `DocumentationTest` (the docs link checks), `ExtensionManagerTest`, `MigrationsTest`, `DemoSeederTest`, `UpdateInstallerTest`, and `Plugins/IdentityProvidersTest` and `Plugins/ExamplePluginTest` |

### Where a new test goes

- A new test goes in the file of the view it exercises. If there is no view, it goes in the matching `System` file, or a new `System/<Area>Test.php`.
- Tests that render the same page as the same person are one test that renders once and asserts everything. Use datasets only when the setup really differs (another role, another setting).
- Test files define no named functions or constants. Pest loads test files as global scripts, so a duplicate name is a fatal error. Anything shared goes into a helper file (see [Helpers](#helpers)). Closures and `describe()` blocks are fine.

## Setup

- **Pest 5** with the Laravel plugin. There is no unit test suite (`phpunit.xml` only lists `Feature`).
- **`tests/Pest.php`** binds every feature test to `Tests\TestCase` with `RefreshDatabase` and loads every file in `tests/Helpers`.
- **`phpunit.xml`** sets the test environment: SQLite in memory, array cache, session and mail, synchronous queue, fast password hashing (`BCRYPT_ROUNDS=4`), `GALLERY_PLUGINS=identity-providers`, so the identity-providers plugin is loaded in every test, and `GALLERY_INSTALLED=true`, so requests are not sent to the [setup wizard](../internals/setup-wizard.md). `Tests\TestCase` keeps the installation marker files of each test in a temporary folder.

## Helpers

Shared helpers live in `tests/Helpers/*.php`, one file per theme, and `tests/Pest.php` loads them all. Look there before writing a new one; add a new function to the file of its theme, or a new file for a new theme.

| File | Holds |
| --- | --- |
| `Users.php` | `userWithRole()`, `actingAsRole()` (a user with a built-in role, the second one also signs in), `userWithPermissions()`, and users for common cases such as `userManager()` or `pageEditor()` |
| `Photos.php` | `galleryPhoto()` (a photo with files on the private disk), `uploadPhotoInChunks()` (uploads a generated JPEG through the chunked upload endpoint, exactly as the media picker does) |
| `Albums.php` | Collaborators, album trees, sub-albums, groups with an album |
| `Settings.php` | `applySiteSettings()`, `saveSettingsPage()`, `useDefaultLocale()` |
| `Mail.php`, `Accounts.php` | Working or test-mailbox delivery and counting sent mail; signing up and confirming an address |
| `Themes.php`, `Extensions.php` | Fixture themes, extension zips, `expectedShade($hex)` (the OKLCH value Filament derives from a hex colour, for admin theme tests) |

Further files cover activity, images, pages, tags, favorites, updates, the setup wizard and the identity providers.

## Speed

The suite takes about 85 seconds. Keep it that way:

- Roles and permissions are seeded once, when the in-memory database is migrated (`TestCase::$seeder`), and every test runs in a transaction on top of it. Do not seed them again in a test.
- `Sleep` is faked in `Tests\TestCase`, so the timebox of the login does not wait.
- The queue worker is `Tests\Support\TestQueueWorker`, which stops instead of sleeping when no job is ready.
- Never add a real `sleep()` or `usleep()`. Move the clock with `$this->travel()` or Laravel's `Sleep::fake()` instead.
- Render a page once and assert everything on that response rather than once per assertion.

## Fixtures

| Folder | Used by |
| --- | --- |
| `tests/Fixtures/themes/plain`, `tests/Fixtures/themes/contact-sheet` | `Backend/ThemesTest` and `System/ExtensionManagerTest`: a bare theme and a theme that uses every seam (views, error pages, package views, mail components, language files, blocks) |
| `tests/Fixtures/docs` | `System/DocumentationTest`: a small docs tree to test ordering, folder labels, front matter and link rewriting |

Tests that need their own themes create a `ThemeManager` for the fixture folder and bind it in the container (`useFixtureThemes()`); tests for the documentation bind a `Documentation` instance for the fixture docs (`useFixtureDocs()`).

## Tests that guard project rules

Some tests check rules across the whole project rather than one feature. When they fail, fix the cause rather than the test:

| Test | Guards |
| --- | --- |
| `System/TranslationFilesTest` | Every `__()` key exists in every `lang/*.json`, translations keep their `:placeholders`, and the admin and site render in German without untranslated texts |
| `System/DocumentationTest` | Every doc has an `order` (except `docs/README.md`), and every relative link, section anchor and image in `/docs` resolves |
| `Frontend/ScrapbookThemeTest` | Core changes still work for a theme that shares nothing with the Default theme |
| `System/AccessModelTest` | The access rules for albums, share links, passwords and end dates |
| `System/ActivityRecordingTest` | Changes in the admin reach the activity log |
| `System/DemoSeederTest` | The demo seeder runs (with `DemoSeeder::$photoScale` reduced) |
| `Backend/PanelTest` | Every settings page is open to the people it should be open to, and closed to everyone else |

## Writing tests

- Create test files with `php artisan make:test --pest {Name}Test`, then move the file into the folder it belongs to (see [Layout](#layout)).
- Test the changed behaviour and its important failure modes, including access: a test for something new should also show that a person without permission cannot see or do it.
- Use factories and their states; do not create records by hand when a factory exists.
- Test Livewire and Filament screens through Livewire's testing helpers (see the existing `Backend/AlbumsTest`, `Backend/MediaLibraryTest` or `Backend/MediaPickerTest`).
- Do not delete or skip existing tests to make a change pass.
