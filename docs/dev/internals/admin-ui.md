---
order: 40
---

# Admin UI conventions

The admin is built with Filament. A few shared rules keep its screens consistent; follow them in core code and in plugins that add resources or pages.

## Lists and filters

Every admin list shows its filters as a row of chips above the table (`App\Filament\Tables\FilterBar`). `AppServiceProvider::boot()` sets the default for all other tables with `Table::configureUsing()`: filters in a dropdown behind the funnel icon next to the search (`FiltersLayout::Dropdown`), applied immediately (`deferFilters(false)`). The media picker (`App\Livewire\MediaPicker`) is the one core table that keeps this dropdown. Do not override the layout or deferral in a single table beyond calling `FilterBar`.

### The filter bar

```php
use App\Filament\Tables\FilterBar;

$table
    ->tap(fn (Table $table) => FilterBar::configure($table, primary: ['visibility', 'owner']))
    ->filters([...]);
```

`FilterBar::configure()` sets `FiltersLayout::Hidden` and `deferFilters(false)`. Filament still builds, applies and stores the filters (so `?filters[...]` URLs and `$table->getFilters()` work as before); the bar is only the view. It is rendered by the `TablesRenderHook::TOOLBAR_AFTER` hook and `TablesRenderHook::FILTER_INDICATORS` hides Filament's "Active filters" row, both registered in `AdminPanelProvider` and active only for tables that called `configure()`. The view is `resources/views/filament/tables/filter-bar.blade.php`, the styles are the `og-filter-*` classes in `resources/views/filament/partials/theme-styles.blade.php`. Plugins that add admin tables opt in with the same call.

- **Primary filters**: the names in `primary` always show a chip, in that order. All other filters are listed under "+ More filters" and get a chip once set.
- **Chip**: shows the label, or "Label: value" with a remove cross once set ("Album: Sea +2" for several values). Clicking it opens a popover with checkboxes (`multiple()`) or radio choices, a search field above `FilterBar::SEARCH_THRESHOLD` (7) options, a "Clear" link, and for dates quick picks (`FilterBar::datePresets()`: last 7 days, last 30 days, this year, last year) next to From / To. "Reset all" clears every filter. On phones the popover is a bottom sheet with a "Show N results" button.
- **Supported filters**: `SelectFilter` (single or `multiple()`, static options or a `BelongsTo` / `BelongsToMany` `relationship()`), `TernaryFilter`, `DateRangeFilter`, `TextFilter` and toggle `Filter`s without a form. Any other filter type throws a `LogicException` when the table renders.
- **Counts**: for relationship filters the popover shows how many rows each option has. The counts come from the table's base query (`$table->getQuery()`, with its query scopes, without filters and search); the labels come from the filter's own relationship query, so access scopes such as `Album::listableFor()` still apply. Only `BelongsTo` and `BelongsToMany` are counted. At most `FilterBar::OPTION_LIMIT` (100) options are listed, the most used ones plus any selected, and the popover says so.
- **Text**: `App\Filament\Tables\Filters\TextFilter` is the one free-text "contains" filter; give it the query with `->matchUsing(fn (Builder $query, string $text) => ...)` (see `PhotoFilters::whereContains()`).
- **Empty choice**: `relationship(..., hasEmptyOption: true)` with `emptyRelationshipOptionLabel()` adds a "no value" option, such as "Without tags".

Further rules:

- **Trash**: see [The trash](#the-trash) below. Do not use tabs or a `TrashedFilter` for it.
- **Dates**: use `App\Filament\Tables\Filters\DateRangeFilter` for any date filter. It renders one "From – To" field and an indicator such as "Uploaded since 03.11.2026"; in the filter bar it becomes a chip with the quick picks. Qualify the column when the query joins other tables.
- **Date format**: every date and time is shown as `DD.MM.YYYY HH:MM` (seconds only where they matter, such as the activity log), in every language. The formats are the constants of `App\Support\DateFormat`. `AppServiceProvider` makes them the defaults of every table, infolist and date picker (pickers use Filament's own calendar, `native(false)`, Monday first), so call `->dateTime()` / `->date()` without a format. In PHP and Blade use `DateFormat::date()`, `dateTime()` or `dateTimeWithSeconds()`; never `isoFormat('LL')`, `diffForHumans()` or `->since()`.
- **Users**: filters that choose a person are `searchable()` and never `preload()`, because a site can have thousands of users. Short lists such as roles, groups and albums may preload.
- **Shared filters**: when two tables show the same records, share the filter definitions instead of copying them. The media library and the picker both use `PhotosTable::albumFilter()` (`multiple: true` in the library, one album in the picker), `uploaderFilter()` and `uploadedFilter()`.
- **Layouts**: a table with a list and a grid layout (the media library) keeps the same filters and search in both; only columns, page sizes and record actions change.

```php
use App\Filament\Tables\Filters\DateRangeFilter;

DateRangeFilter::make('registered')
    ->label('Registered')
    ->column('users.created_at');
```

## Actions and empty states

Rows, headers and empty lists follow the same rules everywhere:

- **Row actions**: no Edit button in lists. The row opens the record (`recordUrl()`, as in Media), so the remaining icon buttons are for other actions: `OpenOnSiteAction`, `ViewAction` where there is no row link (the trash), then the row's own action. A single one is an icon button too (red for destructive, e.g. `DeleteAction::make()->iconButton()`); two or more go into an `ActionGroup` ("…"), destructive last. Never put a single action alone in an `ActionGroup`. Actions that are the reason to open the list stay labelled: `UsersTable::approveAction()` and the trash page's Restore / Delete permanently.
- **Tooltips**: `AppServiceProvider` gives every icon-only `Action` its label as a tooltip (`Action::configureUsing()`), so set a clear `label()` and do not repeat it with `tooltip()`. An explicit `tooltip()` still wins.
- **View on site**: `App\Filament\Actions\OpenOnSiteAction` (name `open`, gray, new tab). Set `->url()`; add `->iconButton()` in tables. Albums and pages use it in the list and in the record's header.
- **Remove vs. delete**: actions that only unlink (detach a user from a group or role, remove photos from an album) are gray with the remove icon; red is for deleting and the trash. Give a custom remove action `ActionIcons::remove($action)`.
- **Header actions**: secondary actions are gray and come first; the main action (create, upload) is the last one, in the primary colour. `ListPhotos`: Trash, Upload photos. Switches that change how the table looks (the media library's List / Grid) are not header actions: they are the table's `headerActions()`, in the toolbar next to search and filters, like "Change order" in the albums list.
- **Empty states**: every table calls `App\Filament\Tables\EmptyState::apply()` (via `->tap()`) with a heading ("No groups yet"), a sentence on what the list is for, an icon and the action that fills it (`CreateAction::make()->url(...)`). While a search or filter is on, it shows "Nothing matches" and hides the action. Relation managers and the trash pages use it too.
- **Deleting users**: `UserDeletionForm::deleteAction()` is the one `DeleteAction` for a person (row and edit page); it asks who gets their albums.

```php
->recordActions([
    OpenOnSiteAction::make()->url(fn (Page $record) => route('pages.show', $record))->iconButton(),
    DeleteAction::make()->iconButton(),
])
->tap(fn (Table $table) => EmptyState::apply(
    $table,
    __('No pages yet'),
    __('Pages hold texts such as an imprint, a privacy policy or an about page.'),
    Heroicon::OutlinedDocumentText,
    [CreateAction::make()->url(PageResource::getUrl('create'))->visible(fn (): bool => PageResource::canCreate())],
))
```

## Components, styles and icons

Build admin screens from Filament's components; write custom Blade only where Filament has nothing (the media tiles, the chunked uploader, the album tree, the activity calendar grid):

| Need | Use |
| --- | --- |
| A card or panel with a heading (dashboard widgets, the documentation page) | `<x-filament::section>` with `heading`, `description`, the `afterHeader` slot for links or buttons, and the `footer` slot |
| A switch between views (the activity calendar's Members / Guests, the Plugins filter) | `<x-filament::tabs>` with `tabs.item` (`active`, `badge`) |
| A link that is only an icon | `<x-filament::icon-button tag="a">` with `label` and `tooltip` |
| A small status label | `<x-filament::badge>` |
| A note or warning in a form or modal | the `Callout` schema component (`Callout::make()->warning()`) or `<x-filament::callout>` |
| An empty page or list without a table | `<x-filament::empty-state>` |
| An explanation under the title | `getSubheading()` (return an `HtmlString` for markup such as `<code>`) |

Styles:

- Custom CSS lives only in `resources/views/filament/partials/theme-styles.blade.php` (Filament ships precompiled CSS, so these are plain rules), with `og-` class names. No `<style>` blocks in views and no `style=""` attributes, except for values computed per element, passed as CSS variables (`style="--og-depth: 2"`, `--share`, `--swatch`, grid positions).
- Colours come from the theme tokens (`rgb(var(--og-muted))`, ...) or Filament's palettes (`var(--gray-700)`, `var(--primary-600)`), which `App\Support\AdminTheme` derives from the theme. In dark mode prefer Filament's palettes: the admin does not load `resources/css/app.css`, so a theme without `variables_dark` keeps light `--og-*` values there. Hex colours only for white or black overlays on photos.
- Images and editors styled from PHP get a class (`og-thumb`, `og-preview-image`, `og-page-editor`), not inline styles.

Icons:

- In PHP, always the `Filament\Support\Icons\Heroicon` enum (`Heroicon::OutlinedArrowUpTray`); in Blade, `heroicon-o-...` strings on Filament components, or `<x-filament::icon>`. No hand-drawn SVG icons.
- One icon per meaning: View on site `ArrowTopRightOnSquare` (only this action uses the arrow-out-of-box; the media tile's "Open details" is the eye), Upload `ArrowUpTray`, Add photos `Photo`, New album `FolderPlus`, Add to album `PlusCircle`, Settings `Cog6Tooth`, Use as cover `Star`.
- **Action icons**: `App\Filament\Actions\ActionIcons` holds the constants `EDIT` (`PencilSquare`), `VIEW` (`Eye`), `DELETE` (`Trash`), `RESTORE` (`ArrowUturnLeft`), `REMOVE` (`MinusCircle`) and `APPROVE` (`CheckBadge`), always the outlined variants. Delete, Move to trash and Delete permanently share the one red trash icon; the confirmation and the tooltip say which it is. Restore and Remove are gray, Approve is green.
- `ActionIcons::register()`, called in `AppServiceProvider`, sets them through `configureUsing()` on Filament's `EditAction`, `ViewAction`, `DeleteAction`, `ForceDeleteAction`, `RestoreAction`, `DetachAction` and their bulk versions. They apply in table rows, "…" menus, bulk actions and page header buttons (which Filament leaves without an icon). An explicit `->icon()` on the action wins. Use the constants for your own actions (`->icon(ActionIcons::APPROVE)`), never another icon for the same meaning.

## The trash

Every resource whose model soft deletes (albums, media) handles the trash the same way, with the classes in `App\Filament\Trash`:

- The **list page** shows only records outside the trash. If the resource query keeps trashed records (as `PhotoResource::getEloquentQuery()` does for the view page and search), the list page narrows it with `TrashActions::scope($query, false)` in its `table()`. Its header has `TrashActions::open(YourResource::class)`: a gray icon button (label "Trash" as tooltip and accessible name) with the count of trashed records the person may see as its badge, hidden while there are none. A resource can narrow what its trash shows with a static `modifyTrashQuery(Builder $query): void` (photos: only those the person may restore); `TrashActions::trashFor()` applies it to the trash page and the count. The count (`TrashActions::count()`) runs once per request and person (`RequestMemo`): Filament asks for the badge and the visibility several times per render. `AppServiceProvider` forgets it whenever any model is deleted, restored or deleted permanently, so the badge is right after a trash action in the same request.
- The **trash page** extends `ListTrashedRecords` and is registered as the resource's `trash` page, before any `/{record}` route. It reuses the resource's table, limited to trashed records and sorted by `deleted_at`, with "Trash" as title and breadcrumb, a "Back to ..." button and an empty state. Subclasses implement `trashExplanation()` (the subheading: what the trash means for this kind of record) and `emptyTrashText()`.
- The **table** checks `$table->getLivewire() instanceof ListTrashedRecords` and offers different actions. On the list: `TrashActions::moveToTrash()` (an icon button, or in the row's "…" menu next to other actions) and `moveToTrashBulk()`. In the trash: `restore()` / `restoreBulk()` and `deletePermanently()` / `deletePermanentlyBulk()`. All use the `ActionIcons` constants: the same red trash icon for moving to the trash and deleting permanently, and the gray restore icon. Never offer both sets in one place.
- **Record pages** use the same actions in the header; Filament shows the right ones by `trashed()`. A record in the trash should be read-only (`EditAlbum` disables its form, hides Save and Cancel, and its photos relation manager returns `true` from `isReadOnly()`).

`TrashActions` keeps Filament's action names (`delete`, `restore`, `forceDelete`) and authorization, and sets the wording: "Move to trash" (can be restored) and "Delete permanently" ("This cannot be undone."). Add what is specific to the resource on top, for example `AlbumsTable::moveToTrash()`, whose confirmation says which albums and photos go with it, and `AlbumsTable::deletePermanently()` with `AlbumDeletionForm`.

Plain `DeleteAction` / `DeleteBulkAction`, for models without a trash, get the confirmation "This cannot be undone." from `AppServiceProvider`.

```php
// ListTrashedAlbums
class ListTrashedAlbums extends ListTrashedRecords
{
    protected static string $resource = AlbumResource::class;

    protected function trashExplanation(): string
    {
        return __('Albums in the trash are hidden on the site, ...');
    }

    protected function emptyTrashText(): string
    {
        return __('Albums you move to the trash appear here until you restore or delete them.');
    }
}

// AlbumResource::getPages()
'trash' => ListTrashedAlbums::route('/trash'),
```

## Closure parameters

Filament passes values into closures **by parameter name**. Use the names Filament knows: `$query`, `$data`, `$record`, `$state`, `$get`, `$set`, `$livewire`. A differently named parameter gets an unrelated value; a filter written as `fn (Builder $q) => ...` receives a new, empty query, so the filter silently does nothing.

```php
// Right: the filter's query is passed in as $query.
->query(fn (Builder $query, array $data) => $query->where('owner_id', $data['value']))
```

## Navigation and labels

- Every resource or page sets `$navigationGroup` to a case of `App\Filament\Navigation\NavigationGroup` (`Gallery`, `UsersAndAccess`, `AppearanceAndExtensions`, `Settings`, `System`, `Help`), and `$navigationSort` within it. The enum's labels are translated per request (`NavigationGroup::panelGroups()` gives the panel closures), because the panel is configured before the visitor's language is known. Plugins use the same enum.
- Static label properties cannot call `__()`. Override `getModelLabel()`, `getPluralModelLabel()`, `getNavigationLabel()`, `getTitle()` and `getSubheading()` instead (see [Languages and translations](../extending/translations.md)).
- Pages and resources check access in `canAccess()` with a permission from `App\Enums\Permission`. Only albums and photos have policies.

## Users on a record

Lists of users that belong to a record (group members, role holders) can grow to thousands of people:

- Use a relation manager extending `App\Filament\RelationManagers\UsersRelationManager`. It is searchable and paginated, adds people by searching for their name or e-mail (nothing is preloaded) and removes them per row. Subclasses set `$relationship` (default `users`) and override the wording: `getTitle()`, `addLabel()`, `removeHeading()`, `emptyHeading()`. Examples: `MembersRelationManager` (groups), `HoldersRelationManager` (roles).
- Never use a multi-select or a preloaded list for members.
- Show a person's picture in front of their name with `App\Filament\Tables\Columns\UserAvatarColumn::make()` (the users list and `UsersRelationManager` use it).
- In tables, show them with `App\Filament\Tables\Columns\UserStackColumn` (overlapping pictures with names as tooltips and "+N"), and load them with `UserStackColumn::withUsers($query)` in `modifyQueryUsing()`, so only the first few users per row are fetched. `GroupResource` and `RoleResource` show how.

## Photos

Choose photos only with the media picker (`PickMediaAction` or `MediaPickerField`), and scope every photo query with `Photo::visibleTo()`. Show photos in a grid only with `MediaGrid` and build image addresses with `$photo->mediaUrl()`. See [Media library and photo picker](media-library.md).

## Standalone images

A field for one image that is not a gallery photo (logo, icon, background, profile picture) uses `App\Filament\Forms\Components\ImageUpload`. Plugins use it too. It extends Filament's `FileUpload`, so disks, directories, `acceptedFileTypes()`, `maxSize()`, validation and `saveUploadedFileUsing()` work as usual. Only the display is replaced.

```php
use App\Enums\ImagePreview;
use App\Filament\Forms\Components\ImageUpload;

ImageUpload::make('icon')
    ->previewAs(ImagePreview::Icon)
    ->previewChip('Log in with :name', 'name', 'Provider')
    ->acceptedFileTypes(['image/png', 'image/webp'])
    ->maxSize(256)
    ->disk('local')
    ->directory('plugins/my-plugin')
    ->visibility('private');
```

- **Preview**: `App\Enums\ImagePreview` has `Logo`, `DarkLogo`, `Icon`, `Wide` (the default) and `Square` (a square upload box with the picture shown as a circle inside it, for profile pictures).
- **Sample**: `previewChip()` shows an `Icon` next to a text at its real size. `:name` follows the given field of the same form, with the third argument as a fallback.
- **Files**: the view is `filament.forms.image-upload`, the Alpine component `ogImageUpload` is in `filament.partials.image-upload-script`, and the `og-iu-*` styles are in `filament.partials.theme-styles`. All are inline, so no frontend build is needed.
- **Saving**: uploads stay temporary until the form saves. `ImageUpload::saveUploadedFiles()` then dispatches the browser event `og-image-upload-saved`, which clears the "Not saved yet" notice. A page that replaces or removes a stored image still deletes the old file itself on save, as `SettingsPage::save()` does.
- **Filament upgrades**: the component relies on `$wire.upload`, the field's `getUploadedFiles` and `removeUploadedFile` methods and the `form-processing-started` / `form-processing-finished` events. Check them when upgrading.

## Page changes without a reload

The panel runs in Filament's SPA mode (`->spa()` in `AdminPanelProvider`, Livewire's `wire:navigate`): a click on an admin link swaps only the page content, shows a loading bar at the top and keeps scripts, styles, Livewire and Alpine loaded. Plugin pages registered under the panel path work the same way.

Links that leave the admin load a whole page, because the site has its own styles and scripts: the site, photo files, downloads, login and every plugin route outside `/admin`. Filament only takes URL patterns for this (`spaUrlExceptions()`), so `App\Support\AdminNavigation::fullPageUrls($panelPath)` builds them from every GET route outside the panel path. Nothing has to be registered for a plugin's own routes.

What this means for scripts:

- Inline `<script>` in a render hook in the body (`SCRIPTS_BEFORE`, `SCRIPTS_AFTER`) runs again on every admin page change. Add `data-navigate-once` for setup that must run only once, such as a global event listener or an `Alpine.data()` registration. The core partials `global-search-fix`, `media-grid-script`, `photo-uploader-script` and `image-upload-script` do.
- For work that belongs to each page, use `document.addEventListener('livewire:navigated', ...)` instead of `DOMContentLoaded` or `load`. `livewire:navigated` also fires on the first load.
- Scripts in `<head>` (`HEAD_END`) run only on the first load.
- `beforeunload` does not fire on an admin page change. Listen for `livewire:navigate`, which can be cancelled with `event.preventDefault()`. The photo uploader does this to ask before an upload is stopped (see [Photo uploads](uploads.md#flow)). Keep `beforeunload` as well for reloads and links that leave the admin.

```blade
<script data-navigate-once>
    document.addEventListener('livewire:navigate', (event) => {
        if (window.myPluginHasUnsavedWork && ! window.confirm('{{ __('Leave this page?') }}')) {
            event.preventDefault()
        }
    })
</script>
```

## Global search

The search field in the top bar is served by `App\Filament\GlobalSearch\AdminSearchProvider` (registered with `->globalSearch()` in `AdminPanelProvider`, shortcut `mod+k`). It returns, in this order:

- **Admin**: every navigation item the user can see (`Filament::getNavigation()`, so access rules come from each page's `canAccess()`), plus a "New ..." entry for each resource with a `create` page the user may use.
- **Records**: Filament's resource search. Every resource with a `$recordTitleAttribute` takes part; set what it searches with `getGloballySearchableAttributes()` (relationship columns such as `media.file_name` work), the subtitle with `getGlobalSearchResultDetails()` and scoping with `modifyGlobalSearchQuery()` or `getEloquentQuery()`. Results without a URL the user may open (no view/edit permission) are dropped, so keep the search query close to the policy to avoid empty result lists. Plugins that register resources join automatically.
- **Documentation**: `App\Support\Documentation::search()` over `/docs`.

Safari does not focus a link on click, which made Filament close the results before the click landed. `resources/views/filament/partials/global-search-fix.blade.php` (render hook `SCRIPTS_AFTER`, `data-navigate-once`) keeps the focus in the search field while a result is pressed; keep it when upgrading Filament until upstream fixes this.

## Plugin and theme lists

The Plugins and Themes pages are tables built from the manifests (`plugin.json`, `theme.json`), not from the database: each page implements `HasTable` and renders it with `EmbeddedTable::make()` in `content()`. `App\Filament\Tables\ExtensionsTable` turns the manifests into rows keyed by slug (`records()`, with search and sorting by name; the page passes its own fields and filters) and gives the shared columns (`columns()`: name with description, version, author). On phones only the name and description are shown: version, author and the theme colours are `visibleFrom('md')`, so the description gets the whole width.

- **Plugins**: an **Active** `ToggleColumn` (`updateStateUsing()` calls `Plugins::toggle()`), disabled with the tooltip "Enabled in the server configuration" for `GALLERY_PLUGINS`, and a **Status** filter (a primary chip of the filter bar). The row (`recordUrl()`) and a gear icon button open the plugin's settings page while it is on.
- **Themes**: a colour preview (`filament.tables.theme-swatches`) and, because exactly one theme is always active, no switch but record actions: **Activate** (a quiet grey outlined button with a confirmation on every other theme, calls `Themes::activate()`) and **Active** (a disabled badge in the primary colour, which is the active theme's own colour in the admin, with a tooltip, kept at full colour with `fi-force-enabled`). The active theme is listed first (`sortByDesc('active')` over `ExtensionsTable::records()`). The row and the gear icon button open `ThemeSettings`, also for inactive themes.

Both pages have an **Upload theme** / **Upload plugin** header action, `App\Filament\Actions\UploadExtensionAction` (`->themes()` / `->plugins()`): a `FileUpload` with `storeFiles(false)` (the Livewire temporary file is unpacked directly) and a **Replace the installed version** toggle. It is visible only while `ExtensionInstaller::allowed()` and checks that and the page's `canAccess()` again before installing. An `ExtensionInstallFailed` is shown as a persistent danger notification and keeps the modal open (`halt()`); a successful upload is logged (`ActivityType::ThemeInstalled` / `PluginInstalled`) and reloads the page. The installer itself is described in [Writing plugins](../extending/plugins.md#installing-from-a-zip-file).

Rows are arrays (`fn (array $record) => ...`), and toggle columns on custom data need `updateStateUsing()`. `toggle()` and `activate()` check `canAccess()` themselves, because the rows are not models with policies, and then reload the page (`redirect(static::getUrl())`): plugins are loaded and settings pages registered per request, and the admin's colours come from the active theme.
