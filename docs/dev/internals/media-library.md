---
order: 10
---

# Media library and photo picker

Photos exist once and can be in many albums. The admin's media library lists every photo a person may see, and one photo picker is used everywhere a photo is chosen. This page covers both, plus how photos get into albums and how covers stay valid. Uploading itself is described in [Photo uploads](uploads.md); who may see what in [Access model](../architecture/access-model.md).

## Photos and albums

`album_photo` links photos and albums (pivot `App\Models\Pivots\AlbumPhoto`, per-album position in `sort_order`). Every photo should stay in at least one album.

Photos join and leave albums in these ways, with different logging:

| Method | Use for | Activity log |
| --- | --- | --- |
| `Album::addPhotos(Photo\|array $photos)` | Several photos into one album: uploads, the album's own "Add photos", "Add to album" and "New album from selection" in the media library | None; uploads are logged once per batch with `ActivityLog::photosUploaded()`, other callers log `photosAdded()` once for the album |
| `Album::removePhotos(Collection $photos)` | Several photos out of one album: "Remove from album" in the media library and in the album's Photos section | `photosRemoved()` once for the album, by the method. Keeps photos for which this is the only album and returns `['removed' => n, 'kept' => n]`; `PhotosTable::notifyRemoved()` turns that into the notification |
| `$photo->albums()->attach($albumId, ['sort_order' => ...])` / `sync()` | Changing one photo's albums (photo form) | "Albums: +X" on the photo, through the pivot |

`addPhotos()` appends the photos at the end of the album and skips photos already in it; `attach()` does neither. A bulk action that adds or removes photos for one album goes through the album, so the log says "Ada added 3 photos to Best of", not one change per photo. Both create an `AlbumPhoto` pivot, whose `created` event takes the photo out of every "Unsorted uploads" album once it joins any other album, so all paths behave the same.

`App\Actions\AddPhotoToAlbum::handle(Album, $path, $originalName, ?User)` turns a stored file into a photo: it reads the dimensions and EXIF (a short list of useful keys, and `taken_at`), compresses the file when [compression](uploads.md#photo-compression) is on, creates the `Photo`, attaches the file to its `original` media collection on the `gallery` disk (with the custom property `compression` when it was compressed) and adds it to the album, in one transaction. `App\Actions\StorePhotoUploads` calls it for every finished upload.

### Covers

An album's cover (`albums.cover_photo_id`) is kept valid automatically; nothing else has to take care of it:

- `AlbumPhoto::created`: an album without a cover takes the first photo added.
- `AlbumPhoto::deleted` and `Photo::deleted` (trash): `Album::releaseCover()` replaces the cover with another photo of the album, or null.
- `Photo::restored`: the photo becomes the cover of its albums that have none.

These changes use `updateQuietly()`, so they never show up in the activity log. Use `updateQuietly()` for any other automatic, non-user change too.

### System albums

`Album::inbox(User)` ("Unsorted uploads") and `Album::pageImages(User)` ("Page images") return that person's own system album, creating it (private) or restoring it from the trash when needed. `albums.system_album` (`Album::INBOX` = `inbox`, `Album::PAGE_IMAGES` = `page-images`) marks them, unique per owner; `Album::isSystem()` checks it. Never look them up by slug or title.

## The media library

`App\Filament\Resources\Photos\PhotoResource` (slug `media`, so `/admin/media`):

- Its query is always `Photo::visibleTo(auth()->user())`, including trashed photos, for the list, the view page and route model binding. Trashed photos are further limited to `Photo::trashableBy()` (`PhotoPolicy::delete()` in SQL: the uploader, or someone who may edit all of the photo's albums): `PhotoResource::modifyTrashQuery()` for the trash page and its count (applied by `TrashActions::trashFor()`), and in the record binding, so a trashed photo's view page opens for nobody else, just as `MediaController` serves its files.
- `$mediaLayout` (trait `App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout`, used by `ListPhotos` and `ListTrashedPhotos`) switches between the list and the grid layout; both pages share the choice for the session. The "List" / "Grid" buttons are rendered by `App\Filament\Tables\MediaLayoutSwitch::render()` on `TablesRenderHook::TOOLBAR_END` (view `filament.tables.layout-switch`, icons only on phones) and call `setLayout()`; the trait has no `mediaLayoutActions()` and the pages have no `listLayout` / `gridLayout` header actions. Both layouts use the same search and filters from `PhotosTable`; only columns, page sizes and record actions differ. In the list layout the title, file name, album path and uploader name are cut at `PhotosTable::TEXT_LIMIT` (40) characters with the full text as a tooltip. Each album links to `AlbumResource` edit when `AlbumResource::canEdit()` allows it, the uploader to `UserResource` edit when `UserResource::canEdit()` allows it; otherwise they are plain text.
- `PhotosTable::configure()` shows the filters as chips (`FilterBar::configure()`, see [Admin UI conventions](admin-ui.md#the-filter-bar)); primary chips: `albums`, `taken`, `tags`, `uploader`, `orientation`, the rest are under "More filters". `PhotosTable::albumFilter()`, `uploaderFilter()`, `uploadedFilter()` and `PhotoFilters::all()` are shared with the photo picker, which uses the same filter bar (`albumFilter()` is single-select there, `multiple: true` in the library, so the URL is `?filters[albums][values][]={id}`). Reuse them for any new photo list. `App\Filament\Resources\Photos\Tables\PhotoFilters::all()` adds the media library's own filters (list, grid, trash and the picker, except for collaborators): `tags` (multiple, visible only while `SiteSettings::taggingEnabled()`, with a "Without tags" empty option; it replaces the old `tagged` filter); `license`, visible only while `Licensing::enabled()`; `taken` (`DateRangeFilter` on `photos.taken_at`); `orientation` (landscape, portrait, square, from `width` and `height`); `resolution` (under 2 MP, 2-12 MP, over 12 MP, from `width * height`); `file_type` (multiple, the `gallery.accepted_mime_types` matched on the media's `mime_type`); `missing` (multiple: photos lacking any ticked detail among title, description, `taken_at` and, while licensing is on, license; `PhotoFilters::whereMissing()`); `album_count` (none / one / several, `has('albums', ...)`); `favorites` (toggle: the current user's rows of `photo_favorites`); and `file_name`, a `TextFilter` substring match on the media's `file_name`. `PhotoFilters::whereContains($query, $column, $text, $boolean)` is the portable "contains" query: it escapes `!`, `%` and `_` and uses `LIKE ? ESCAPE '!'`, which MySQL, MariaDB and SQLite all accept. Use it instead of a plain `like` for user-typed text.
- One photo page, two classes with the same layout. `PhotoResource::opensForEditing(Photo)` (not trashed and `PhotoPolicy::update()`) decides which one: `PhotoResource::detailUrl(Photo)` returns the `edit` URL for editors and the `view` URL otherwise. Use `detailUrl()` for every link to a photo (the list's `recordUrl`, the grid tile's "Open details" button, the dashboard's "Your recent uploads", activity log links), never a hard-coded route. `ViewPhoto::mount()` redirects editors to `edit`, and `EditPhoto::mount()` sends a trashed photo to `view`, so a stale link always lands on the right page.
- `EditPhoto` is the photo page for editors: the form (`PhotoForm`) with the preview (`og-fill` sections) and the editable title and description, "Where it is" (editable albums and tags, read-only "Cover of", and the license field `PhotoForm::licenseField()`, shown while `Licensing::enabled()` and read-only when `Licensing::canChoose()` is false) and "History" (read-only uploader, uploaded and last changed, editable taken date) on the right, and the read-only "File" and "Camera" sections in a second row. Saving stays on the page (`getRedirectUrl()` returns null). The form's album field only accepts albums the person may upload to and only removes the photo from albums they may edit.
- `ViewPhoto` is the read-only version, for photos in the trash and for people who may see but not edit a photo. It shows the preview and the infolist (`PhotoInfolist`) in the same arrangement: row 1 the preview with "Where it is" and "History", row 2 a two-column grid with "File" (full width when `hasCameraData()` is false) and "Camera". The albums, "Cover of" and "Uploaded by" entries link to the `AlbumResource` / `UserResource` edit page only when `AlbumResource::canEdit()` / `UserResource::canEdit()` allow it, and are plain text otherwise. `PhotoForm` reuses the public static helpers of `PhotoInfolist` (`previewImage()`, `coverOfEntry()`, `licenseEntry()`, `uploaderEntry()`, `uploadedEntry()`, `changedEntry()`, `fileSection()`, `cameraSection()`, `hasCameraData()`), so change those once and both pages follow.
- The header actions of both pages are in the trait `App\Filament\Resources\Photos\Pages\Concerns\PhotoPageActions` (`photoPageActions()`): "Open in album", "Download original", "Move to trash", and for trashed photos "Restore" (redirects to `detailUrl()`, so an editor lands on the editable page) and "Delete permanently". Add or change a header action there, once.
- In the list layout the row actions are only "Move to trash" (an icon button); there are no View / Edit icons, because the row itself opens the photo page. The trash list (`ListTrashedPhotos`) keeps View, Restore and Delete permanently. Other resources (users, albums, pages, groups, roles) have no read-only page and stay list, then edit page.
- Bulk actions ("Add to album", "Remove from album", trash, restore, delete permanently, and those in `PhotoBulkActions`, see below) check `PhotoPolicy` per record and keep at least one album per photo. "New album from selection" (needs `create albums`) creates a private album owned by the current user with the selected visible photos, like `CreateAlbum`, and logs one `photosAdded()`.
- "Set license" (`PhotosTable::setLicense()`) is only visible while `Licensing::canChoose()` allows it. It refuses a license `Licensing::allows()` rejects, skips photos the user may not `update` and logs the changed photos once with `ActivityLog::changedInBulk()`.
- `App\Filament\Resources\Photos\Tables\PhotoBulkActions::all()` (added to `PhotosTable::bulkActions()` before the trash action) holds the other bulk actions, see [Bulk actions](#bulk-actions).
- "Upload photos" (`ListPhotos`, needs `upload photos`) asks for a target first (`ListPhotos::uploadTargets()`: `inbox`, then `album:{id}` for every non-system album the person may upload to), then replaces itself with the `uploadInto` picker action (`replaceMountedAction()`), which uploads there. `?action=uploadPhotos` opens it on load (the dashboard's "Your albums" panel links to it; "Unsorted uploads" links to the list filtered with `?filters[albums][values][]={id}`).
- Trashed photos are on their own page, `ListTrashedPhotos` (`/admin/media/trash`), opened with the "Trash" button on `ListPhotos`; `ListAlbums` and `ListTrashedAlbums` work the same way (see [The trash](admin-ui.md#the-trash)).
- Every photo grid (the media library's grid layout, an album's Photos section, the picker) is set up by `App\Filament\Tables\MediaGrid::configure($table, $tileData, $perPage)`: the same compact `media-tile` column and the page sizes of every other admin list (10, 25, 50, 100; 50 by default, 25 in the picker). Tiles are laid out in justified rows like the site's `photo-grid` block: `MediaGrid` puts the photo's shape on each record as a class (`MediaGrid::ratioClass()`, `og-r-30` = 1.50, steps of 0.05 from 0.25 to 5, because Filament's record wrapper takes classes but no inline style), and the admin styles (`filament/partials/theme-styles.blade.php`) turn Filament's content grid into wrapping flex rows (target row height 120 / 140 / 180 px by screen width) in which each record grows in proportion to its shape. `filament/partials/media-grid-script.blade.php` then splits each page of photos into the rows closest to that height for the current width and sets each tile's `--og-basis`, so every row ends flush, including the last one and rows on phones. The chosen photos of a `MediaPickerField` use the same layout, with the shape in each item's `ratio`. Only when all photos on a page would have to grow past 1.75 times the row height to fill a single row do they keep the normal height. The tile image uses `ImageLoading::next()` (first photos load at once) and offers `small` through `srcset`, like the site's grids. The ratio is `Photo::aspectRatio()`, shared with `PhotoGridItems`. Add `MediaGrid::selectionActions(acrossPages: bool = false)` to the toolbar: it returns one action, `selectAll`, with the Alpine-only view `filament.tables.selection-control`, which reads "Select all" and, once photos are selected, "N selected · Select all · ×" (the × clears the selection; there is no `clearSelection` action). CSS hides Filament's `.fi-ta-selection-indicator` row in any table containing `.og-selection`. The tiles have no checkboxes. By default "Select all" selects the photos on the page (`selectRecords(getRecordsOnPage())`); with `acrossPages: true` it calls Filament's `selectAllRecords()`, which selects every record matching the search and filters. The media library (list and grid layout) uses `acrossPages: true`, the album's Photos section and the picker the default.
- Build photo image addresses with `$photo->mediaUrl('thumb'|'preview')` (null until the conversion exists). It only makes the URL; `MediaController` still decides who gets the file.
- A photo outside the trash whose albums are all in the trash is hidden on the site. Its grid tile (`media-tile`) shows "Not on the site", and the list's albums column marks trashed albums.

### Bulk actions

`PhotoBulkActions` builds these `BulkAction`s; none of them is available in the trash. Each one acts only on the selected photos the user may `update` (the private `editable()` helper) and reports how many were left out through one notification (`notify()`, a warning when photos were left out). Filament wraps every bulk action in one activity-log batch, so field changes are counted with `ActivityLog::changedInBulk()` and the batch writes one entry (see [Activity log](activity-log.md)).

| Action | Visible | What it does |
| --- | --- | --- |
| `addTags` | `SiteSettings::taggingEnabled()` | `Tagging::add($photo, $names, $user)` adds to the photo's own tags without removing any. It skips names that `allowsCustomTags()` / `predefinedTags()` forbid, stops at `Tagging::MAX_PER_ITEM` and then returns false, which the notification reports as a warning |
| `removeTags` | `SiteSettings::taggingEnabled()` | `Tagging::remove($photo, $names)` detaches only the photo's own tags (tags from albums are not touched) and deletes tags nothing uses any more, like `Tagging::sync()` (both use `deleteUnused()`); returns whether the photo had any |
| `rename` | always | Titles come from `App\Support\PhotoRenaming` and only `photos.title` changes; the stored file names stay |
| `changeUploader` | `Permission::EditAnyAlbum` | Sets `uploader_id`. The new uploader must hold `Permission::UploadPhotos` or be a super admin (`uploaders()`), checked again on the server together with the permission. Photos in the old uploader's "Unsorted uploads" move to the new uploader's `Album::inbox()` |
| `removeFromAllAlbums` | always | Needs `delete` on each photo (`PhotoPolicy::delete()`). First adds the photos to their uploader's `Album::inbox()` (the current user's when the uploader is gone), then detaches them from every other album with one `ActivityLog::photosRemoved()` per album. Covers move on through the pivot's `deleted` event (see [Covers](#covers)) |
| `download` | always | Keeps `AlbumAccess::canDownload()` photos, warns about the rest and redirects to `media.selection.download` |

`PhotoRenaming` is the pattern engine of "Rename":

- `tokens()` returns the placeholders with their meaning (`{n}`, `{title}`, `{original}`, `{album}`, `{uploader}`, `{taken}`, `{uploaded}`), which the form shows as help text.
- `problemWith($pattern)` returns a message for an empty pattern or an unknown placeholder, or null; the form rule uses it.
- `titles($photos, $pattern, $start, $digits)` returns the title by photo id. Photos are numbered oldest first by `taken_at` (`created_at` when unknown), then id. `{album}` is the title of the photo's first non-trashed album by id, the system album "Unsorted uploads" only when it is the photo's only album. `{taken}` and `{uploaded}` are `Y-m-d`; `{taken}` falls back to the upload date. Results are limited to `PhotoRenaming::MAX_LENGTH` (255).

### Selection download

The "Download" bulk action stores the selected ids with `PhotoSelectionDownloadController::prepare($ids)`, which puts them in the session under a random one-time key (a selection can be too long for a URL) and returns the key. The action redirects to `route('media.selection.download', $key)` (`GET /photo-downloads/{key}`, middleware `auth` and `throttle:5,1`). The controller pulls the ids from the session (404 when the key is unknown or used), and re-checks them: only `Photo::visibleTo()` photos that pass `AlbumAccess::canDownload()` go in, and `PhotoLocation::isHiddenFrom()` photos lose their GPS data. It streams the zip with `PhotoZip::make(__('Photos'), $photos, withoutLocation: ..., namedByTitle: true)`, so no queue or temporary file is needed.

With `namedByTitle: true`, `PhotoZip::make()` sets the in-memory custom property `PhotoZip::FILE_NAME` on each original to `PhotoZip::fileNameFor($title, $storedName)` (title made safe for file names, with the stored file's extension, or the stored name without a title). `PhotoZipStream` uses that name inside the zip and appends " (1)", " (2)" to duplicates; the stored file is never renamed.

## The photo picker

Choosing photos anywhere in the admin goes through one component. **Never build another photo chooser.**

- `App\Livewire\MediaPicker`: the picker itself, a Livewire table of `Photo::visibleTo()` (for albums and covers also `Photo::reusableBy()`) with the media library's filter chips (`FilterBar::configure()`; primary chips: the `scope` toggle, `albums`, `uploader`, `taken`) and an upload drop zone. The `scope` toggle ("Hide photos already in this album" with `scope: 'exclude'`, "Only photos in this album" with `'only'`) is on by default and exists only when the opener passed `scopeAlbum()`. When `limitToAlbumId` is set (collaborators), only `scope` and the `uploaded` date filter remain. It only selects; it never changes data.
- `App\Filament\Actions\PickMediaAction`: an action that opens the picker in a modal and runs JavaScript with the result.
- `App\Filament\Forms\Components\MediaPickerField`: a form field built on the action; its state is a photo id, or a list of ids with `->multiple()`.

### PickMediaAction

```php
PickMediaAction::make('addFromLibrary')
    ->pickerKey('album-photos-'.$album->id)
    ->multiple()
    ->scopeAlbum($album->id, 'exclude')
    ->uploadTo('album:'.$album->id)
    ->confirmLabel(__('Add to album'))
    ->onPicked('$wire.call("addPickedPhotos", ids)');
```

| Method | Meaning |
| --- | --- |
| `pickerKey()` | Identifies this opener; echoed back in the browser event |
| `multiple()` | Allow several photos (default: one) |
| `confirmLabel()` | Text of the confirm button (default "Use selected photos") |
| `scopeAlbum($id, 'only'\|'exclude')` | Adds a filter, on by default, that shows only that album's photos or hides them |
| `uploadTo()` | Where uploads go: `album:{id}`, `inbox` or `page-images`; null hides the drop zone |
| `onPicked()` | JavaScript run after picking, with `ids` (photo ids) and `items` (`{id, thumb, title}`) in scope |

When the person confirms, `MediaPicker::pick()` dispatches the browser event `media-picked` with `{key, items}`; the action closes its modal and runs `onPicked`.

### MediaPickerField

```php
MediaPickerField::make('photo_ids')
    ->multiple()
    ->uploadTo('page-images')
    ->chooseLabel(__('Add photos'))
    ->confirmLabel(__('Add to gallery'));
```

Besides the action's options it has `chooseLabel()`, `pickerHeading()` and `emptyText()`. State is dehydrated to an `int` or a list of unique `int`s.

### Uploads in the picker

The drop zone appears when `PhotoUploads::allows($uploadTo, $user)` is true: the person has `access admin panel` and `upload photos`, and either may `upload` to the target album or the target is `inbox` or `page-images`. A collaborator needs neither permission for an `album:{id}` target, and gets a picker limited to that album's photos and their own uploads (see [Collaboration](../architecture/access-model.md#collaboration)). Finished uploads come back through `MediaPicker::addUploaded()`, which keeps only the person's own visible photos and selects them.

Core's openers use these targets:

| Opener | `uploadTo` |
| --- | --- |
| Album form: photos and cover (`AlbumForm`) | `inbox` |
| Album's Photos section: "Add photos" (`PhotosRelationManager`) | `album:{id}` |
| Page photo and gallery blocks (`PhotoBlock`, `GalleryBlock`) | `page-images` |

Photos uploaded in an existing album's picker go straight into that album (logged once per batch as uploaded). The album's grid shows them when the picker closes, even without choosing, because closing it renders the page behind it again. Photos uploaded for an album that does not exist yet, or for a cover, land in the uploader's "Unsorted uploads" first and leave it when an album adds them.

`PhotosRelationManager::addPhotosUrl($album)` is the edit page URL with `?tableAction=addFromLibrary&actionComponent=photos`: the section answers to the action identifier `photos` (`getDefaultActionUrlIdentifier()`), so Filament opens "Add photos" on load. The site's album page links to it.

### Re-check picked ids on the server

The picked ids come from the browser. Every consumer must filter them with `Photo::visibleTo(auth()->user())` before using them. Consumers that put photos into an album also need `Photo::reusableBy()`, as `CreateAlbum`, `EditAlbum` (cover) and `PhotosRelationManager::addPickedPhotos()` do. The page blocks keep their ids in the page body, so the page form checks the whole body when saving; see [Photos on pages](../architecture/access-model.md#photos-on-pages).

## Serving photos

Photo files are on the private `gallery` disk and are only served by `MediaController` (`route('media.show', [$media, 'thumb'|'preview'])`, or without a conversion for the original; `?download=1` sends it as a download). Photos on pages use `route('pages.photo', [$page, $photo, $conversion])`. Originals also need `SiteSettings::downloadsEnabled()` for visitors (see [Access model](../architecture/access-model.md#downloads-and-favourites)); `AlbumAccess::canViewPhoto()` is the check for seeing a photo, shared with the favourites. Never use `storage:link` or a public URL. For the site's photo grids, build items with `App\Support\PhotoGridItems::from()` (see [Blocks](../extending/blocks.md#photo-grids)).

Both controllers, and `AvatarController`, are in the `files` route group (`routes/files.php`): the session is read but never saved, and no cookies are sent (`ReadOnlySession`, see [Speed](performance.md#photo-files)). Both controllers check access first and then send the file through `App\Support\PhotoFileResponse::make()`: `Cache-Control: private, max-age=86400` (`PhotoFileResponse::MAX_AGE`), an `ETag` made from the file's path, size and modification time, and `Last-Modified`. A browser that asks again with `If-None-Match` or `If-Modified-Since` gets `304 Not Modified` for an unchanged file; a regenerated file gets a new `ETag` and is sent again. A visitor who lost access gets 404, never 304, because the checks run before the response is built. Photo files are never `public`: no shared cache may keep them.

## Conversions

`Photo::registerMediaConversions()` makes three WebP conversions with `Fit::Max` (`Photo::CONVERSION_SIZES`, longest side, never enlarged): `preview` (1920 px) for the lightbox and photos on pages, `thumb` (720 px) for grids and tiles, and `small` (360 px), which grids and tiles offer to phones through `srcset` (`App\Support\Srcset`). `preview` is registered first: `App\Support\ConversionsFromPreview`, the media library's image generator in `config/media-library.php`, starts `thumb` and `small` from the preview made a moment earlier in the same run instead of decoding the original again (a 24 MP upload: about 0.5 s instead of 0.9 s). Without a preview in the run, for example when only `thumb` is regenerated, the original is used. Photos uploaded before `small` existed get it from the background jobs (`SmallThumbnails::makeMissing()`, task `small-thumbnails`, made from their `thumb`). All use quality `Photo::CONVERSION_QUALITY` (80, GD's default, set so Imagick produces the same) and `->nonOptimized()`: the media library's image optimizers (`image_optimizers` in `config/media-library.php`, run only where binaries such as `cwebp` are installed) re-encoded the finished WebP at a higher quality, which made files up to twice as large and added seconds per upload. The image library turns photos upright by their EXIF orientation while converting; `AddPhotoToAlbum` stores `width` and `height` as the photo is seen (swapped for orientations 6 and 8, `Photo::TURNED_ORIENTATIONS`), so grids and `srcset` get the right shape. Conversions run during the upload unless `GALLERY_QUEUE_CONVERSIONS` is on (see [Uploads](uploads.md)).

## File sizes

The statistics page reports disk space from custom properties on the original media, through `App\Support\MediaSizes`:

- `original_size` (`MediaSizes::ORIGINAL`): the size in bytes before [compression](uploads.md#photo-compression) replaced the file. `AddPhotoToAlbum` and `PhotoCompression` set it through `MediaSizes::rememberOriginal()` and never overwrite it, so it stays the real upload size.
- `conversion_sizes` (`MediaSizes::CONVERSIONS`): conversion name => bytes of each generated WebP. A listener for the media library's `ConversionHasBeenCompletedEvent` in `AppServiceProvider` calls `MediaSizes::recordConversion()` for photos.

Photos stored before the sizes were recorded are filled by the background task `media-sizes` (`BackgroundJobs::tasks()`, every pass): `MediaSizes::fillMissing()` looks at `BATCH` (300) media for up to `SECONDS` (10 s), remembering where it stopped in the setting `statistics.media_sizes_cursor` and setting `statistics.media_sizes_done` at the end. `MediaSizes::filling()` tells the Statistics page to show its note while that is going on. Photos compressed before `original_size` existed cannot show what compression saved.
