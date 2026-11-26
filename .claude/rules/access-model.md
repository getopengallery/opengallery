---
paths:
  - "app/Models/**"
  - "app/Policies/**"
  - "app/Http/Controllers/**"
  - "app/Support/Album*.php"
  - "app/Support/Photo*.php"
  - "app/Support/Inbox.php"
  - "app/Filament/**"
  - "app/Livewire/**"
  - "database/migrations/**"
---

## Access model (read before touching albums/photos/media)
- Site-wide capabilities = Spatie roles/permissions, listed in `App\Enums\Permission` and seeded by `RolesAndPermissionsSeeder` (idempotent; add new permissions to the enum, incl. `grouped()` / `label()` for the role form, and re-seed). `super-admin` bypasses all checks via `Gate::before`.
- Per-album access = `Album::$visibility` (`App\Enums\Visibility`) + explicit shares (`album_user`, `album_group`). Logic lives in `Album::isVisibleTo()` / `Album::scopeListableFor()` / `AlbumPolicy`. Keep those three consistent. Photos inherit access from their album.
- Unlisted albums: `/s/{share_token}` unlocks the album for the session (`App\Support\AlbumAccess`).
- Albums: tree order via `albums.tree_path` (maintained in `Album::refreshTreePath()`, cascades to sub-albums; never set by hand). Album order among siblings (`sort_order`) is changed only by drag and drop in the albums list (`ListAlbums::reorderTable`, siblings only, staff with "edit any album"). Photo order per album: `albums.photo_order` (`App\Enums\PhotoOrder`), applied in `Album::photos()`. Access: optional `password` (hashed) and `access_expires_at`; `Album::hasDirectAccess()` (owner, staff, direct shares) bypasses both. Visitors go through `App\Support\AlbumAccess` (session unlocks; a password change invalidates them). Keep `isVisibleTo`, `scopeListableFor`, `AlbumAccess` and `MediaController` consistent.
- Photo files live on the private `gallery` disk and MUST only be served via `MediaController` (`route('media.show', [$media, 'thumb'|'preview'])`). Never expose them through `storage:link` or public URLs.
- Photos ↔ albums is many-to-many (`album_photo`, pivot `App\Models\Pivots\AlbumPhoto`, order in `sort_order`). A photo is visible if any of its non-trashed albums is; add with `Album::addPhotos()` (no log; uploads are logged per batch) or `$photo->albums()->attach()` (logged as "Albums: +X"). Covers are kept valid by the pivot and `Photo` events. Every photo stays in at least one album: delete albums permanently only with `App\Support\AlbumDeletion::forceDelete()` and users with `App\Support\AccountDeletion` (`albums.owner_id` is `nullOnDelete`).
- Media library: `App\Filament\Resources\Photos` (slug `media`, list/grid layout in `ListPhotos::$mediaLayout`). Always scope photo queries for staff with `Photo::visibleTo($user)`; `PhotoPolicy`: edit = uploader or manager of any album, trash = uploader or manager of all its albums. Photos soft-delete (trash); `Photo::albums()` includes trashed albums. Album covers are kept valid by `Photo` model events (delete/restore/move); use `updateQuietly` for automatic cover changes. `MediaController` only serves trashed photos (or photos in trashed albums) to users who may update them.
- Choosing photos anywhere in the admin goes through ONE media pool picker: `App\Livewire\MediaPicker` (selection only; dispatches browser event `media-picked` {key, items}), opened by `App\Filament\Actions\PickMediaAction` (config: pickerKey, multiple, confirmLabel, scopeAlbum(id, 'only'|'exclude'), uploadTo('album:{id}'|'inbox'|'page-images'), onPicked(js with `ids`/`items`)). In forms use `App\Filament\Forms\Components\MediaPickerField` (state: id or list of ids). Never build another photo chooser. Uploads made in the picker land in that album or the uploader's own private system albums "Unsorted uploads" (`Album::inbox`, photos leave it when added to any other album, via `AlbumPhoto::created`) / "Page images" (`Album::pageImages`), marked by `albums.system_album` (never by slug). Consumers must re-check picked ids with `Photo::visibleTo()` on the server; adding photos to an album or using them as a cover also needs `Photo::reusableBy()` (only photos the person may edit).
- Lists of users on a record (group members, role holders) use `App\Filament\RelationManagers\UsersRelationManager` (searchable, paginated, attach by search; never preload all users or use a multi-select for members) and, in tables, `App\Filament\Tables\Columns\UserStackColumn` with `UserStackColumn::withUsers($query)` (loads only the first few users per row).
- Filament closures receive values by parameter *name*: use `$query`, `$data`, `$record`, `$state`, `$get`. A differently named Builder parameter (e.g. `$q`) gets a new, unrelated query and the filter silently does nothing.
- Single sign-on lives in the `identity-providers` plugin, not in core. Core only keeps `social_accounts` (linked identities), the unlink route and the `LoginOptions` registry. An identity provider login must never take over an existing account by e-mail unless that provider is explicitly configured to, and confirms the address is verified.

