---
order: 10
---

# Writing plugins

A plugin is a folder in `/plugins/{slug}` with a manifest and a Laravel service provider. It is loaded only while it is turned on, hooks into core through events, routes, views and a few extension points, and never requires an edit to core. Administrators install it by uploading it as a zip file or copying the folder, and switch it on under Appearance & extensions → Plugins.

Two plugins ship with OpenGallery and serve as references:

- `plugins/example`: a small plugin to copy: a service provider that listens to two events, adds an admin page through `Panel::configureUsing()` and ships its own views and texts.
- `plugins/identity-providers`: a complete plugin with routes, a settings page, encrypted secrets, translations and a login extension point.

## Folder layout

```text
plugins/watermark/
  plugin.json                  manifest (required)
  src/
    WatermarkServiceProvider.php
    Filament/Settings.php      optional settings page
  routes/web.php               optional, loaded by the provider
  resources/views/             optional, loaded by the provider
  lang/
    en/settings.php            the plugin's texts: __('watermark::settings.title')
    de/settings.php
```

The folder name is the plugin's **slug**. It is used in the settings key, the translation namespace, the settings page address and `GALLERY_PLUGINS`.

## plugin.json

```json
{
    "name": "watermark::plugin.name",
    "version": "1.0.0",
    "description": "watermark::plugin.description",
    "author": "You",
    "autoload": {
        "Plugins\\Watermark\\": "src/"
    },
    "provider": "Plugins\\Watermark\\WatermarkServiceProvider",
    "settings": "Plugins\\Watermark\\Filament\\Settings"
}
```

| Key | Meaning |
| --- | --- |
| `name` | Required; a manifest without it is ignored. Shown in the plugins list. |
| `version`, `description`, `author` | Shown in the plugins list. `version` defaults to `0.0.0`. |
| `autoload` | PSR-4 map of namespaces to folders inside the plugin |
| `provider` | Service provider class, registered while the plugin is on |
| `settings` | Optional settings page class (see [Settings page](#settings-page)) |

`name` and `description` are shown as written, or translated when they are a key in the plugin's namespace (`{slug}::group.key`, through `App\Extensions\Manifest::text()`).

Plugins cannot bring Composer dependencies: only the `autoload` map is registered (`Composer\Autoload\ClassLoader`). Use the packages core already ships, or include the code in `src/`.

## Installing from a zip file

To distribute a plugin, zip its folder (`watermark.zip` containing `watermark/plugin.json`, `watermark/src/...`). Administrators upload it under Appearance & extensions → Plugins → **Upload plugin**; themes work the same way under Themes → **Upload theme**.

`App\Extensions\ExtensionInstaller` (`installPlugin()` / `installTheme()`) does the work:

- The manifest is either at the top of the zip or inside its only top-level folder. The slug is that folder's name, or without a folder the zip's file name (`Str::slug()`, a trailing ` (1)` removed). Slugs must match `[A-Za-z0-9][A-Za-z0-9_-]*`. Packers' junk (`__MACOSX`, `.DS_Store`, `Thumbs.db`) is skipped. A GitHub "Download ZIP" folder is named `{repo}-main`, which then becomes the slug and the translation namespace: publish a zip whose folder has the plugin's real slug.
- Every entry is checked before anything is written: absolute paths, `.` / `..` segments, symbolic links (Unix mode `0120000` in the external attributes), more than `ExtensionInstaller::MAX_FILES` entries or more than `MAX_UNPACKED_BYTES` unpacked (counted again while writing) refuse the whole zip. The manifest must be JSON with a `name`.
- The files are unpacked into a hidden folder next to the installed ones (`plugins/.{slug}.upload-…`, skipped by the `*/plugin.json` glob) and renamed into place. An existing folder is only replaced when asked to: it is renamed aside, the new one moved in, then the old one deleted (or moved back if that fails). Settings in the database, keyed by slug, stay.
- A new plugin is off. Replacing a plugin that is on calls `ProductionCaches::outdated()`, as switching it does.
- Failures throw `App\Extensions\ExtensionInstallFailed` with a translated message for the admin.

Uploads need the permission `install extensions` (`Permission::InstallExtensions`) besides `manage plugins` / `manage themes`, because an uploaded extension runs code on the server (plugin PHP, theme Blade views). `GALLERY_EXTENSION_UPLOADS=false` (`config('gallery.extension_uploads')`) turns uploads off for everyone. The zip goes through Livewire's temporary upload, so PHP's `upload_max_filesize` and `post_max_size` limit it too, besides `ExtensionInstaller::MAX_UPLOAD_KB`. Covered by `tests/Feature/ExtensionUploadTest.php`.

## How plugins are loaded

`App\Extensions\PluginManager` finds every `plugins/*/plugin.json`. A plugin is **on** when its slug is in the setting `plugins.enabled` (switched in the admin) or in `GALLERY_PLUGINS` (comma-separated, from `.env`; such plugins cannot be switched off in the admin).

`AppServiceProvider::register()` calls `PluginManager::register()`, which, for every plugin that is on:

1. registers the PSR-4 autoloader from `autoload`;
2. registers the `provider` with the application.

If loading throws, the error is logged ("Plugin [slug] failed to load") and the plugin is skipped; the site keeps running. The enabled list is read straight from the database because Eloquent is not booted yet; a missing `settings` table (fresh install) means no plugins.

Because plugins are registered in the register phase, before the admin panel is built:

- **`register()`** may bind services and configure things that are built later, but must not use Eloquent or the database.
- **`boot()`** may use everything: models, settings, routes, views, events.

The translation namespace `{slug}` is registered for every installed plugin, on or off, so the admin can show translated names of plugins that are off.

Switching a plugin on or off takes effect on the next request; its settings page appears after a reload.

On live sites the configuration, routes and admin pages are cached ([Speed](../internals/performance.md#production-caches)). With cached routes and configuration, `loadRoutesFrom()` and `mergeConfigFrom()` are skipped, and with cached Filament components, pages and widgets come from the cache. Switching a plugin on or off rebuilds the caches, and so does changing a plugin's `plugin.json` or a file in its `routes/` folder. Anything else a plugin's provider registers is read on every request as usual. Keep `env()` out of plugin code (only in its configuration files) and do not put closures into configuration.

## Hooking into core

A plugin's service provider is an ordinary Laravel service provider. Typical hooks:

```php
public function boot(): void
{
    // React to core events and model events.
    Event::listen(Registered::class, fn (Registered $event) => /* ... */);
    Photo::created(fn (Photo $photo) => /* e.g. tag or watermark the photo */);

    // Add pages and views.
    $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    $this->loadViewsFrom(__DIR__.'/../resources/views', 'watermark');
}
```

- Routes need the `web` middleware group for sessions and the visitor's language: wrap them in `Route::middleware('web')`, as `plugins/identity-providers/routes/web.php` does.
- Anything the plugin shows visitors must follow the [access model](../architecture/access-model.md): list albums with `Album::listableFor()`, serve photos only through `route('media.show', ...)`, and re-check photo ids with `Photo::visibleTo()`.
- Pages a plugin adds to the site should use the site layout and the `og-*` classes and tokens, so they follow the active theme.

### Admin screens

The only admin screen core wires up for a plugin is its settings page. To add resources, pages or widgets, use Filament's `Panel::configureUsing()` from the provider's `register()`. Its callbacks run when `AdminPanelProvider` sets the panel's id, which happens after plugins are registered:

```php
use Filament\Panel;

public function register(): void
{
    Panel::configureUsing(fn (Panel $panel) => $panel->resources([
        \Plugins\Watermark\Filament\WatermarkResource::class,
    ]));
}
```

Resources work the same way (`$panel->resources([...])`); `pages()` and the others add to what core registers. Dashboard widgets are registered with `App\Extensions\DashboardWidgets` instead, under a stable key (see [Dashboard widgets](dashboard-widgets.md)). The Example plugin adds `Plugins\Example\Filament\ExamplePage` like this (with its view loaded under `example::` in `boot()`), and `tests/Feature/Plugins/ExamplePluginTest.php` checks that the page appears while the plugin is enabled and is gone when it is off. Give such screens a navigation group from `App\Filament\Navigation\NavigationGroup`, a `canAccess()` that checks a permission and translated texts from the plugin's own `lang` folder (see [Admin UI conventions](../internals/admin-ui.md)).

## Settings page

Declare a page class in `plugin.json` (`settings`) that extends `App\Filament\Pages\PluginSettingsPage`:

```php
namespace Plugins\Watermark\Filament;

use App\Extensions\PluginSettings;
use App\Filament\Pages\PluginSettingsPage;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class Settings extends PluginSettingsPage
{
    protected static string $plugin = 'watermark';

    public function mount(): void
    {
        $this->form->fill(['text' => static::settings('text', '')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('text')->label(__('watermark::settings.text')),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        static::putSettings('text', $this->form->getState()['text']);
    }
}
```

The base class provides:

- the address `/admin/plugins/{slug}/settings`, reached through the gear icon in the plugin's row of the plugins list; no navigation entry;
- access only for people with `manage plugins`, and only while the plugin is on (`canAccess()`);
- the title (the plugin's name), breadcrumbs and a **Save settings** button (also Ctrl/Cmd+S) that calls `save()`;
- `settings($key, $default)` and `putSettings($key, $value)`, which read and write the plugin's settings.

Settings are stored as one array in the setting `plugin.{slug}`, through `App\Extensions\PluginSettings` (`all()`, `get()`, `put()`). Code outside the page reads them with `PluginSettings::get('watermark', 'text')`. Store secrets with `PluginSettings::encrypt()` and read them with `decrypt()`; never show a stored secret in the form again (keep it when the field is left empty), as the identity-providers plugin does with client secrets.

## Login options

`App\Extensions\LoginOptions` lets a plugin add sign-in methods to the login page and the profile. Register a resolver in `boot()`; it is called lazily when a page needs the options. A plugin `sso` with its own sign-in routes might register:

```php
$this->app->make(LoginOptions::class)->resolveUsing(fn () => [
    new LoginOption(
        key: 'keycloak',
        name: 'Keycloak',
        label: __('sso::login.log_in_with', ['name' => 'Keycloak']),
        url: route('sso.redirect', 'keycloak'),
        linkUrl: route('sso.link', 'keycloak'),
        isDefault: false,
    ),
]);
```

The identity-providers plugin builds its options this way in `ProviderRepository::loginOptions()`.

`App\Extensions\LoginOption` fields:

| Field | Meaning |
| --- | --- |
| `key` | Identifier; also stored as `social_accounts.provider` for linked accounts |
| `name` | Shown in the profile's linked accounts |
| `label` | Button text on the login page |
| `url` | Starts the sign-in |
| `description`, `iconUrl` | Optional, shown with the button |
| `linkUrl` | Optional; starts linking the method to the logged-in user, shown in the profile |
| `isDefault` | "Log in" goes straight to `url` (`LoginOptions::default()`) |

Rules core enforces or relies on:

- `/login?local=1` always shows the password form, even with a default option, so staff can log in when the provider is down.
- Core keeps the `social_accounts` table and the unlink route (`linked-accounts.destroy`); creating and linking accounts is the plugin's job.
- A plugin must never sign someone into an existing account by matching the e-mail address, unless that provider is explicitly configured to and confirms the address is verified.
- New accounts created by a plugin should fire Laravel's `Registered` event, so core gives them the `user` role, and respect `config('gallery.registration_enabled')` unless the plugin is configured otherwise.

## Translations

Put the plugin's texts in `lang/{locale}/{group}.php` and use them as `__('{slug}::group.key')`; `PluginManager` registers the namespace, so do not call `loadTranslationsFrom()`. Plugins never add keys to core's `lang/*.json`. Details: [Languages and translations](translations.md#writing-plugins).

## Adding an extension point

When a plugin needs something core does not offer yet, add a general hook to core (an event, a config value, a registry like `LoginOptions`, a block) and document it here. Never special-case one plugin in core code.

## Testing

Plugin behaviour is tested from core's test suite: `phpunit.xml` sets `GALLERY_PLUGINS=identity-providers`, and its tests are in `tests/Feature/Plugins`. A plugin that is not in `GALLERY_PLUGINS` is not loaded in tests.
