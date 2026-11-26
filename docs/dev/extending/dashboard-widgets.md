---
order: 15
---

# Dashboard widgets

The admin dashboard (`App\Filament\Pages\Dashboard`) shows the widgets registered in `App\Extensions\DashboardWidgets`, each under a stable string key. Each widget decides for itself who sees it, and each person can arrange the widgets they see (see [Per-user layouts](#per-user-layouts)). Core widgets and plugin widgets work the same way. What administrators see is described in the user guide's [The dashboard](../../user/administration/dashboard.md).

## Core widgets

| Key | Widget (`App\Filament\Widgets\…`) | `canView()` | `defaultSize()` (outside presets) |
| --- | --- | --- | --- |
| `quick-actions` | `QuickActions` | `access admin panel` | full |
| `your-albums` | `YourAlbums` | `create albums` or `upload photos` | normal |
| `recent-uploads` | `RecentUploads` | `upload photos` | normal |
| `albums-attention` | `AlbumsNeedingAttention` | `edit any album` | normal |
| `trash` | `TrashOverview` | `create albums`, `upload photos`, `edit any album` or `delete any album`, and something in the viewer's trash | normal |
| `site-health` | `SiteHealth` | `manage settings` | normal |
| `members` | `MembersOverview` | `manage users` | normal |
| `pages` | `PagesOverview` | `manage pages` | normal |
| `library` | `LibraryOverview` | `view activity` | full |
| `activity-calendar` | `ActivityCalendar` | `view activity` | full |
| `activity-trends` | `ActivityTrends` | `view activity` | full |
| `recent-activity` | `RecentActivity` | `view activity` | wide |
| `popular-albums` | `PopularAlbums` | `view activity` | normal |

Every check also requires `access admin panel`. Core positions run from 0 to 120 (`DashboardWidgets::CORE`).

## Writing a widget

Extend `App\Filament\Widgets\DashboardWidget` (a Filament `Widget`; the registry accepts nothing else). Give it a `dashboardLabel()` and a `dashboardDescription()` (translated; shown in "Customize dashboard") and, if it should not be one column wide by default, a `defaultSize()`. Do not set `$columnSpan`: `DashboardWidget::getColumnSpan()` derives it from the size. Its `viewer()` returns the signed-in user only if they may open the admin, and `viewerCan(Permission ...$any)` checks permissions on top of that. Build `canView()` on them, and gate the widget by the capability it is about, not by `view activity`:

```php
namespace Plugins\Watermark\Filament;

use App\Enums\Permission;
use App\Filament\Widgets\DashboardWidget;

class WatermarkQueue extends DashboardWidget
{
    protected string $view = 'watermark::queue-widget';

    public static function dashboardLabel(): string
    {
        return __('watermark::dashboard.queue');
    }

    public static function dashboardDescription(): ?string
    {
        return __('watermark::dashboard.queue_description');
    }

    public static function canView(): bool
    {
        return static::viewerCan(Permission::UploadPhotos);
    }

    protected function getViewData(): array
    {
        return ['waiting' => \Plugins\Watermark\Queue::waitingFor(static::viewer())];
    }
}
```

Filament checks `canView()` before mounting the widget and before every Livewire update, so it is the single place that decides visibility. Rules for the data:

- Scope everything about albums and photos to the viewer: `Album::listableFor()`, `Album::manageableBy()`, `Photo::visibleTo()`, or the owner or uploader for "my …" widgets. Never show album titles, photos or names the viewer could not open elsewhere in the admin.
- Keep queries in a support class (core: `App\Support\DashboardStats` and `App\Support\Dashboard\*`) and the widget thin. Aggregate in SQL, avoid one query per row, and wrap site-wide totals in `Cache::remember()` with a short time to live. The cache is the file or database store on shared hosting.
- Set `protected static bool $isLazy = true;` for a widget whose queries are slow, so the rest of the dashboard renders first.
- Use the `og-*` classes and theme tokens from `resources/views/filament/partials/theme-styles.blade.php` and Filament's components (`x-filament::section`, `x-filament::badge`), never raw colours, and translate every text (see [Admin UI conventions](../internals/admin-ui.md)).

## Registering a widget from a plugin

Register the widget in the provider's `register()`, not `boot()`: the admin panel is set up after plugins are registered, and it needs the class list to register the widgets with Livewire.

```php
use App\Extensions\DashboardWidgets;

public function register(): void
{
    $this->app->make(DashboardWidgets::class)
        ->register('watermark.queue', WatermarkQueue::class, sort: 200);
}
```

- **Key**: lowercase words joined by `-` or `.`. Prefix it with the plugin slug (`watermark.queue`). The key, not the class name, identifies the widget, so keep it stable when you rename the class. An invalid key, or a class that is not a Filament widget, throws an `InvalidArgumentException`.
- **Sort**: the default position. Core uses 0 to 120; plugins usually pick 200 or more, which puts them after the core widgets. Registering an existing key again replaces that widget.
- **Width**: override `defaultSize()` with `DashboardLayout::WIDE` or `FULL` (see below).
- A disabled plugin is not loaded, so its widgets disappear from every dashboard.

## Per-user layouts

`App\Support\Dashboard\DashboardLayout` combines the registry with the person's saved preferences in `users.dashboard_layout`:

```json
{"v": 1, "widgets": [{"key": "members", "hidden": false, "size": "full"}, {"key": "recent-activity", "hidden": true, "size": "wide"}]}
```

- Defaults: `DashboardLayout::PRESETS` holds one arrangement per kind of admin user (`administrator`, `moderator`, `uploader`, `analyst`, `user-manager`, `site-operator`, `content-editor`), each an ordered map of widget key => size planned for the three-column grid. `presetFor($available)` picks the preset with the most widgets in common with what the user may see, on a tie the smallest (most specific) one, so custom roles get the closest fit. `defaults($available)` returns that preset limited to the available widgets, followed by any other widget (plugins, new core widgets) in registry order with its `defaultSize()`. To place a new core widget, add it to the presets it belongs in.
- `entries($user)` starts from the widgets the signed-in user may see now (`available()`: `canView()`) in their default arrangement, applies the saved order, `hidden` and `size`, drops saved keys that are no longer available (permission removed, plugin turned off) and inserts widgets missing from the saved list after their predecessor in the default arrangement. `visible($user)` leaves out hidden ones. Permissions always win: the dashboard renders only these entries, and each widget checks `canView()` again when it mounts.
- `save($user, $entries)` keeps only available keys, each once, a boolean `hidden` and a size from `DashboardLayout::SIZES` (`normal`, `wide`, `full`; anything else falls back to the default arrangement's size), so a forged request cannot add a widget. It writes with `saveQuietly()`; `ActivityLog` also ignores the column. `reset($user)` stores null.
- The dashboard grid has one column on phones, two from `md` and three from `xl`. Normal spans one column, wide two (`['default' => 1, 'md' => 2]`), full the whole row; cards in a row are stretched to the same height.
- The stored data names widgets only by key, so renaming a widget class or removing a plugin never breaks a saved layout. Keep a widget's key when you change it; a new key counts as a new widget and appears at its default position.

The editor is the "Customize dashboard" header action of `App\Filament\Pages\Dashboard`: a slide-over with a Filament `Repeater` (drag and drop plus move up/down buttons, a "Show" toggle and a width select per widget, "Reset to default"). There is no site-wide default layout set by administrators; the defaults are the presets above. `tests/Feature/Backend/DashboardTest.php` ("the dashboard layout") covers saving, hiding, lost permissions, forged requests and reset.

## Tests

The Example plugin registers `Plugins\Example\Filament\ExampleWidget` as `example.hello` (shown to people with `manage plugins`), and `tests/Feature/System/Plugins/ExamplePluginTest.php` checks that it appears only while the plugin is enabled. `tests/Feature/Backend/DashboardTest.php` holds the visibility matrix per user type, the scoping tests and a check that each widget's number of queries does not grow with the library.
