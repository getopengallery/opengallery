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

`App\Actions\AddPhotoToAlbum::handle(Album, $path, $originalName, ?User)` turns a stored file into a photo: it reads the dimensions and EXIF (a short list of useful keys, and `taken_at`), creates the `Photo`, attaches the file to its `original` media collection on the `gallery` disk and adds it to the album, in one transaction. `App\Actions\StorePhotoUploads` calls it for every finished upload.

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
- `$mediaLayout` (trait `App\Filament\Resources\Photos\Pages\Concerns\HasMediaLayout`, used by `ListPhotos` and `ListTrashedPhotos`) switches between the list and the grid layout; both pages share the choice for the session. The "List" / "Grid" buttons (`mediaLayoutActions()`) are the table's `headerActions()`, set in `PhotosTable::configure()` so they sit in the toolbar next to search and filters, not in the page header. Both layouts use the same search and filters from `PhotosTable`; only columns, page sizes and record actions differ. In the list layout the title, file name, album path and uploader name are cut at `PhotosTable::TEXT_LIMIT` (40) characters with the full text as a tooltip. Each album links to `AlbumResource` edit when `AlbumResource::canEdit()` allows it, the uploader to `UserResource` edit when `UserResource::canEdit()` allows it; otherwise they are plain text.
- `PhotosTable::albumFilter()`, `uploaderFilter()` and `uploadedFilter()` are shared with the photo picker. Reuse them for any new photo list.
- `ViewPhoto` shows the preview and the infolist (`PhotoInfolist`): row 1 is the preview on the left with the "Where it is" and "History" sections on the right, row 2 a two-column grid with "File" (full width when `hasCameraData()` is false) and the "Camera" section (its entries in two columns). The albums, "Cover of" and "Uploaded by" entries link to the `AlbumResource` / `UserResource` edit page only when `AlbumResource::canEdit()` / `UserResource::canEdit()` allow it, and are plain text otherwise; `EditPhoto` the form (`PhotoForm`), which has the same layout: the preview (`og-fill` sections) with the editable title and description, "Where it is" (editable albums and tags, read-only "Cover of") and "History" (read-only uploader, uploaded and last changed, editable taken date) on the right, and the read-only "File" and "Camera" sections in a second row. `PhotoForm` reuses the public static helpers of `PhotoInfolist` (`previewImage()`, `coverOfEntry()`, `uploaderEntry()`, `uploadedEntry()`, `changedEntry()`, `fileSection()`, `cameraSection()`, `hasCameraData()`), so change those once and both pages follow. The form's album field only accepts albums the person may upload to and only removes the photo from albums they may edit.
- Bulk actions ("Add to album", "Remove from album", trash, restore, delete permanently) check `PhotoPolicy` per record and keep at least one album per photo. "New album from selection" (needs `create albums`) creates a private album owned by the current user with the selected visible photos, like `CreateAlbum`, and logs one `photosAdded()`.
- "Upload photos" (`ListPhotos`, needs `upload photos`) asks for a target first (`ListPhotos::uploadTargets()`: `inbox`, then `album:{id}` for every non-system album the person may upload to), then replaces itself with the `uploadInto` picker action (`replaceMountedAction()`), which uploads there. `?action=uploadPhotos` opens it on load (the dashboard's "Your albums" panel links to it; "Unsorted uploads" links to the list filtered with `?filters[albums][value]={id}`).
- Trashed photos are on their own page, `ListTrashedPhotos` (`/admin/media/trash`), opened with the "Trash" button on `ListPhotos`; `ListAlbums` and `ListTrashedAlbums` work the same way (see [The trash](admin-ui.md#the-trash)).
- Every photo grid (the media library's grid layout, an album's Photos section, the picker) is set up by `App\Filament\Tables\MediaGrid::configure($table, $tileData, $perPage)`: the same compact `media-tile` column and the page sizes of every other admin list (10, 25, 50, 100; 50 by default, 25 in the picker). Tiles are laid out in justified rows like the site's `photo-grid` block: `MediaGrid` puts the photo's shape on each record as a class (`MediaGrid::ratioClass()`, `og-r-30` = 1.50, steps of 0.05 from 0.25 to 5, because Filament's record wrapper takes classes but no inline style), and the admin styles (`filament/partials/theme-styles.blade.php`) turn Filament's content grid into wrapping flex rows (target row height 120 / 140 / 180 px by screen width) in which each record grows in proportion to its shape. `filament/partials/media-grid-script.blade.php` then splits each page of photos into the rows closest to that height for the current width and sets each tile's `--og-basis`, so every row ends flush, including the last one and rows on phones. The chosen photos of a `MediaPickerField` use the same layout, with the shape in each item's `ratio`. Only when all photos on a page would have to grow past 1.75 times the row height to fill a single row do they keep the normal height. The tile image uses `ImageLoading::next()` (first photos load at once) and offers `small` through `srcset`, like the site's grids. The ratio is `Photo::aspectRatio()`, shared with `PhotoGridItems`. Add `MediaGrid::selectionActions()` (Select all / Clear selection) to the toolbar; the tiles have no checkboxes.
- Build photo image addresses with `$photo->mediaUrl('thumb'|'preview')` (null until the conversion exists). It only makes the URL; `MediaController` still decides who gets the file.
- A photo outside the trash whose albums are all in the trash is hidden on the site. Its grid tile (`media-tile`) shows "Not on the site", and the list's albums column marks trashed albums.

## The photo picker

Choosing photos anywhere in the admin goes through one component. **Never build another photo chooser.**

- `App\Livewire\MediaPicker`: the picker itself, a Livewire table of `Photo::visibleTo()` (for albums and covers also `Photo::reusableBy()`) with the media library's filters and an upload drop zone. It only selects; it never changes data.
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
