---
order: 20
---

# Access model

Who may see and change what is decided in two layers: site-wide **permissions** (what someone may do anywhere) and per-album **visibility and shares** (who may see one album). Photos have no access settings of their own; they inherit it from their albums. Read this page before touching albums, photos or anything that serves a file.

## Permissions and roles

Site-wide capabilities are Spatie permissions. Every permission is a case of `App\Enums\Permission`:

| Case | Permission name | Allows |
| --- | --- | --- |
| `AccessAdmin` | `access admin panel` | The full admin; needed for every other admin permission. `User::opensAdmin()` also lets people with a collaborator role in, to the Albums section only (see [Collaboration](#collaboration)) |
| `ViewActivity` | `view activity` | Dashboard figures and the activity log |
| `ManageUsers` | `manage users` | The user resource |
| `ManageGroups` | `manage groups` | The group resource |
| `ManageRoles` | `manage roles` | The role resource |
| `ManageSettings` | `manage settings` | The pages under Admin → Settings, plus Background jobs, Updates and Log settings under System |
| `ManagePages` | `manage pages` | The page resource |
| `ManageThemes` | `manage themes` | Themes and theme settings |
| `ManagePlugins` | `manage plugins` | Plugins and plugin settings pages |
| `InstallExtensions` | `install extensions` | Uploading themes and plugins as zip files on the Themes / Plugins pages (with `ManageThemes` / `ManagePlugins`, and only while `config('gallery.extension_uploads')` is on; `ExtensionInstaller::allowed()`) |
| `CreateAlbums` | `create albums` | `AlbumPolicy::create()` |
| `UploadPhotos` | `upload photos` | `AlbumPolicy::upload()` |
| `ViewAnyAlbum` | `view any album` | Every album, including private, password and expired ones ("staff") |
| `EditAnyAlbum` | `edit any album` | `AlbumPolicy::update()` for every album; reordering albums |
| `DeleteAnyAlbum` | `delete any album` | `AlbumPolicy::delete()` for every album |
| `InviteCollaborators` | `invite collaborators` | `AlbumPolicy::manageCollaborators()` for own albums; `AlbumPolicy::searchDirectory()` for Editors |

The built-in roles are the cases of `App\Enums\Role` (`super-admin`, `admin`, `moderator`, `uploader`, `user`). `Database\Seeders\RolesAndPermissionsSeeder` creates every permission and gives each built-in role its set; it runs in `gallery:install` and before every feature test.

- `super-admin` has no permissions assigned. `Gate::before()` in `AppServiceProvider` lets it pass every check.
- New accounts get `user` (the `Registered` listener in `AppServiceProvider`).
- The standard sets are `RolesAndPermissionsSeeder::matrix()`. The seeder never removes a permission and never re-grants one an administrator took away: it remembers what it has given each role in the setting `roles.seeded_permissions` (`RolesAndPermissionsSeeder::SEEDED_SETTING`) and on every run (`gallery:install`, the upgrade step) only gives what is in the matrix but not in that record. A built-in role created in this run gets its full set. Installations from before the record are treated as having received the current set, except permissions created in the same run. Covered by `tests/Feature/System/RolesSeederUpgradeTest.php`.
- The `uploader` role includes `access admin panel`, because album creation and uploads only exist in the admin. Installations from before the seeder kept track get it on their next `gallery:install` (`RolesAndPermissionsSeeder::untrackedMatrix()` is the baseline for them).

The admin's resources and pages check permissions in their `canAccess()` methods. `Album`, `Photo` and `User` also have policies.

### Managing users without escalation

`manage users` must never lift anyone above the editor (unlike `manage roles`, which is all-powerful by design):

- `Role::scopeAssignableBy($user)` returns the roles someone may give or take away: every role for super admins, otherwise only roles whose permissions all belong to the editor, never `super-admin`. The roles select in `UserForm` uses it as its relationship query, so Filament rejects other role ids on save and never detaches roles outside it.
- `User::canManage($other)` is true when every role of `$other` is in that set. `UserPolicy::update()` and `delete()` require it; the users table's bulk delete checks each record (`authorizeIndividualRecords('delete')`).

Covered by `tests/Feature/Backend/UsersTest.php` (the user manager tests).

### Deleting users

`albums.owner_id` is nullable and `nullOnDelete`, so the database never deletes albums with their owner. Every place that deletes a user goes through `App\Support\AccountDeletion` first:

- `deleteKeepingAlbums($user, ?$newOwner)`: the admin's delete actions (`EditUser`, the users table's bulk delete, both using `UserDeletionForm`). Every album goes to `$newOwner` (default: the person deleting); the system albums are merged into the new owner's own. Without a new owner, albums ("Unsorted uploads" included) are trashed and the user's deletion clears their owner; "Page images" is never trashed.
- `deleteWithAllData($user)`: the profile's "Delete account", only after the person confirms (`delete_albums`). Force-deletes their albums ("Unsorted uploads" included, "Page images" kept for the pages) and every photo left in no album, after `AlbumCollaboration::keepCollaboratorUploads()` moved collaborators' uploads to their own "Unsorted uploads". Other people's sub-albums move to the nearest surviving ancestor, so `tree_path` stays valid.

`AccountDeletion::ownedAlbums()` lists the albums concerned (trash and "Unsorted uploads" included, "Page images" excluded) for the confirmation dialogs. Albums without an owner pass `AlbumPolicy` only for staff. Covered by `tests/Feature/Frontend/ProfileTest.php` ("deleting the account") and `tests/Feature/System/OwnershipOnDeleteTest.php`.

### Adding a permission

1. Add a case to `App\Enums\Permission`, and to `grouped()` (section in the role form), `label()` and, if useful, `description()`. Labels go through `__()`, so add the texts to every `lang/*.json`.
2. Assign it to the right built-in roles in `RolesAndPermissionsSeeder::matrix()`. Existing installations get it on their next `gallery:install`; adding an existing permission to a role's set works the same way.
3. Check it with `$user->can(Permission::YourCase->value)`, in `canAccess()`, a policy or a Gate.
4. Run the seeder (or `php artisan gallery:install`) so existing installations get it.

## Album visibility

Every album has a `visibility` (`App\Enums\Visibility`):

| Case | Value | Who may see the album |
| --- | --- | --- |
| `Public` | `public` | Everyone. With public albums turned off (`config('gallery.public_albums_enabled')`), only logged-in users. |
| `Unlisted` | `unlisted` | People who opened its share link `/s/{share_token}` in this session (the album is shown at that address). Never listed. A blurred preview card of its cover is served without a session at `/s/{share_token}/cover` for link previews until it ends (`App\Support\AlbumPreviewImage`). |
| `Registered` | `registered` | Logged-in users |
| `Restricted` | `restricted` | Users and groups it is shared with (`album_user`, `album_group`), and [album guests](#album-guests) |
| `Private` | `private` | Only the owner |

On top of the visibility, an album can have:

- a **password** (`albums.password`, hashed): visitors must enter it once per session;
- an **end date** (`albums.access_expires_at`): afterwards the share link, the password and the visibility no longer grant access.

`Album::saving` gives unlisted albums a 40-character `share_token` and removes it from every other visibility, so switching away from unlisted invalidates the link and switching back creates a new one.

### Direct access

`Album::hasDirectAccess(?User)` is true for the owner, for staff (`view any album`), for [collaborators](#collaboration) whatever the visibility and, for restricted albums, for people the album is shared with directly or through a group (`isSharedWith()`). Direct access bypasses the password and the end date.

`isSharedWith()` looks the album up in `Album::idsSharedWith($user)`: the ids of all albums shared with that person directly or through their groups, worked out with two queries once per request and kept in `App\Support\RequestMemo`. Checking many albums or photos (album tiles, media grid tiles, `PhotoPolicy::view()`) therefore costs no extra queries. `RequestMemo` is a scoped instance that starts empty for every HTTP request, queued job and test, and is never written to the cache: access decisions must never outlive the request. `AppServiceProvider` forgets the shares whenever an `AlbumUser`, `AlbumGroup` or `GroupUser` pivot is created or deleted or a group is deleted, so a share changed during a request counts at once. Shares written past the pivot models (raw `DB::table()` inserts) are only seen by the next request.

### The four places that decide

These must stay consistent. A change to one usually needs the same change in the others:

| Where | Answers |
| --- | --- |
| `Album::isVisibleTo(?User)` (used by `AlbumPolicy::view()`) | May this person see this album by its visibility? Direct access first, then the end date, then the visibility. Does not know about share links or passwords. |
| `Album::scopeListableFor(?User)` | Which albums may be listed for this person? The query version of the rules: staff see everything, guests nothing while public albums are off, otherwise public and not expired, plus (logged in) own, registered and shared restricted albums and albums they collaborate on. Unlisted albums are never listed, except to their collaborators. The second argument `false` leaves collaboration out (`Photo::scopeVisibleTo()`). |
| `App\Support\AlbumAccess` | May this visitor open the album right now? `canView()` = direct access, or reachable (visible by policy or unlocked by share link in this session, and not expired) and, if there is a password, the right password entered in this session. `requiresPassword()` decides when to show the password form. |
| `MediaController` | May this visitor get the file? Thumbnails and previews are served if `AlbumAccess::canViewPhoto()` allows it: the photo is not trashed and `canView()` allows one of its non-trashed albums, or the person may update the photo. The original file (no conversion) needs `AlbumAccess::canDownload()`: the person may update the photo, or `SiteSettings::downloadsEnabled()` (Settings → Photos, off by default) and one of its non-trashed albums has `allow_downloads` and `canView()` allows it. `?download=1` sends the original as an attachment. Files go out through `App\Support\PhotoFileResponse` only after these checks: `Cache-Control: private, max-age=86400` with an `ETag` and `Last-Modified`, and `304 Not Modified` for an unchanged file. |
| `App\Support\AlbumSearch` | Which albums does a search on the site find? `scopeListableFor()` matches on title or description, then only those whose ancestors are all listable too, so search never reveals an album that browsing hides. |

Photo files are served in the `files` route group (`routes/files.php`), which reads the session without saving it (`ReadOnlySession`): logins and unlocks count as on every page, but a file request never creates or extends a session and never signs anyone in from a "remember me" cookie. Session state lives in `AlbumAccess`: `unlocked_albums` (album ids opened through a share link), `album_guests.{id}` (see [Album guests](#album-guests)) and `album_passwords.{id}` (a fingerprint of the password hash, `Album::passwordFingerprint()`). Changing an album's password changes the fingerprint, so everyone who entered the old one is locked out.

`AlbumController@show` answers with the password form (HTTP 401) when `requiresPassword()` is true, and with 404 for guests or the `albums.forbidden` page (HTTP 403, without album data) for logged-in users when `canView()` is false. `AlbumController@shared` answers 410 with the "expired" page after the end date.

### Album guests

A restricted album can also be shared by e-mail with people who have no account (`album_guests`, `App\Models\AlbumGuest`, `Album::guests()`), while `SiteSettings::albumGuestsEnabled()` is on. Guests are not `User`s, so `isVisibleTo()`, `scopeListableFor()` and `AlbumPolicy` do not know them: they never list or find an album, and have no direct access. Only `AlbumAccess::reachable()` accepts them, and only for a restricted album.

`App\Support\AlbumGuests` holds the rules: `normalize()` (trimmed, lower-case, valid address or null), `sync()` and `invite()` (create the guest and queue `AlbumGuestInvited` with the personal link), `findByToken()` (only the SHA-256 of the link token is stored), `isActive()`, `hasActiveCode()`, `sendCode()` and `verify()`. Opening `/albums/{album}/guest/{token}` (`albums.guest`, `AlbumController@guest`) sends a 6-digit code unless one is still valid; it is stored hashed with `code_expires_at` (`AlbumGuests::CODE_MINUTES`, 10) and `code_attempts` (`AlbumGuests::MAX_ATTEMPTS`, 5), and the right code is used up. `AlbumGuestCode` is sent at once with `notifyNow()`, so no queue worker is needed; `AlbumGuestInvited` goes through `MailQueue` and is dropped when the album is trashed, no longer restricted or expired. Both use the site's default language. Routes: `POST` to the same address (`albums.guest.verify`) and `.../resend` (`albums.guest.resend`).

A guest is active (`AlbumGuests::isActive()`) while the album is not trashed and still restricted, sharing by e-mail is on, and `SiteSettings::albumGuestDays()` (0 = no limit) have not passed since `created_at`. Otherwise the link answers 410 with `albums/guest-expired.blade.php`; after the album's end date it shows the usual expired page.

The right code stores `album_guests.{albumId}` = `{guestId}:{fingerprint}` in the session (`AlbumAccess::rememberGuest()`, `AlbumGuest::fingerprint()`, derived from the id and token hash) and regenerates the session id. `reachable()` accepts it only while the guest still exists, the fingerprint matches and the guest is active, so removing the guest ends the session's access. The end date, the password and `allow_downloads` apply as for any visitor, and sub-albums are not opened.

In the album form, the "Shared with" picker (`PeopleAndGroupsSelect::allowEmails()`, only while guests are enabled and `Mailing::delivers()`) takes `email:{address}` keys (`PeopleAndGroups::emailKey()`, `emailOption()`; `split()` also returns `emails`). `AlbumSettings::syncShareKeys()` turns an address that belongs to an account (compared lower-case) into a share with that account and passes the rest to `AlbumGuests::sync()`; with guests turned off it only keeps addresses that are guests already. `AlbumSettings::shareKeys()` lists guests as e-mail keys. Wrong codes are logged as `ActivityType::AlbumGuestCodeFailed` (security, no IP address).

### Album trees

Albums nest through `parent_id`. Each album has its own visibility; a parent's visibility does not restrict its children. The home page lists only top-level albums (`whereNull('parent_id')`), an album page lists its `children()` filtered with `listableFor()`. A visible album inside a hidden one is therefore reachable by its address but listed nowhere; this is intended (the parent's visibility hides what is inside from browsing). Trash is different: `Album` events move all `descendants()` to the trash with an album (same `deleted_at`) and restore those trashed at or after it, so nothing is left behind under a trashed parent.

`albums.tree_path` holds the album's position in the whole tree (built from the sort order, title and id of every ancestor). It is maintained by `Album::refreshTreePath()` after every save, cascading to sub-albums, and is used to list albums as a tree in the admin. Never set it by hand. The order among siblings (`sort_order`) is only changed by drag and drop in the admin's album list (`ListAlbums::reorderTable`), for people with `edit any album`.

## Album policy

`App\Policies\AlbumPolicy`:

| Ability | Rule |
| --- | --- |
| `viewAny` | Always true; lists must filter themselves: `listableFor()` on the site, `workableBy()` in the admin |
| `view` | `Album::isVisibleTo()` |
| `create` | `create albums` |
| `update` | Owner, or `edit any album` |
| `delete` | Owner, `delete any album`, or the person who created this sub-album through a role while they still have a role on its parent (`AlbumCollaboration::createdThroughRole()`) |
| `restore`, `forceDelete` | Same as `delete`. Filament allows any action whose policy method is missing, so every action offered on albums needs a method here; bulk actions check each album (`authorizeIndividualRecords()`). |
| `workOn` | `update`, or any collaborator role. Opens the admin edit page (`AlbumResource::getEditAuthorizationResponse()`); changes nothing by itself |
| `upload` | `upload photos` and `update`, or any collaborator role |
| `createSubAlbum` | `create albums` and `update`, or Organizer and up |
| `removePhotos` | `update`, or Organizer and up; Contributors only their own uploads (`[$album, $photo]`) |
| `editSettings` | `update`, or Editor |
| `searchDirectory` | `editSettings`, and `invite collaborators` or staff |
| `manageCollaborators` | Owner with `invite collaborators`, or `edit any album` |
| `viewCollaborators` | Owner, staff and collaborators |
| `leave` | A direct role on this album |

Shares (`album_user`, `album_group`) only grant viewing; they never grant uploading. All abilities from `createSubAlbum` on except `viewCollaborators` are false for trashed and system albums. `Gate::before` lets super admins pass them anyway, so code that acts on an album also checks `Album::acceptsContributions()`.

## Collaboration

Collaborators work on an album with a role, `App\Enums\CollaboratorRole` (`Contributor` < `Organizer` < `Editor`), stored in `album_collaborators` and `album_group_collaborators` (see [Data model](data-model.md#album-collaborators)). Covered by `tests/Feature/Backend/AlbumCollaboratorsTest.php` and `tests/Feature/System/AccessModelTest.php`.

### Resolving a role

`App\Support\AlbumCollaboration::rolesFor($user)` returns every album the person works on with their effective role: the highest of their direct roles and their current groups' roles, on the album and its ancestors. It takes four queries however many albums and groups are involved and is kept in `RequestMemo` (`AlbumCollaboration::MEMO`), never in the cache. Trashed and system albums are left out. `Album::collaboratorRole($user)` reads from it and is null for the owner, whose rights always win.

`AppServiceProvider` forgets the memo when a collaborator pivot is created, updated or deleted, when `GroupUser` changes, when a group is deleted, and on every album save, delete and restore. It cannot listen for `wasChanged('parent_id')`: `refreshTreePath()` saves the album again, which clears the changed attributes.

### What a role never grants

Collaborators do **not** pass `AlbumPolicy::update()`, including Editors. That ability also drives `PhotoPolicy` edit and trash, access to trashed photos and original downloads. `Album::manageableBy()` ignores roles too. An original is served to a collaborator only as to any visitor: when downloads are on site-wide and the album has `allow_downloads`, or for their own upload.

### Downloads and favourites

`SiteSettings::downloadsEnabled()` is a master switch above the per-album `allow_downloads`. While it is off, `canDownload()` is true only for people who may update the photo; `PhotoGridItems` gives no `download` address, the album page hides **Download all** and `AlbumController::download()` (the album zip) answers 404 for everyone. People who may update a photo still reach its original through `MediaController` and the admin. The album form hides `allow_downloads` while the switch is off; the stored values are kept (migration `2026_11_26_194554_keep_downloads_on_where_albums_allow_them` turns the switch on for existing sites where any album already allowed downloads).

Favourites (`App\Support\Favorites`, table `photo_favorites`) are not an access path. Liking a photo grants nothing: `PhotoPolicy`, `canDownload()` and `MediaController` never look at them. `FavoriteController::toggle()` lets someone like a photo only if `AlbumAccess::canViewPhoto()` allows it (they can always remove a like), and `Favorites::page()` filters through `canViewPhoto()` again on every read, so a photo drops out of a person's Favorites as soon as they lose access to it or it is trashed. Do not store favourites in `album_photo` or a system album: that would turn a like into a right on someone else's photo.

`Photo::scopeVisibleTo()` leaves collaboration out on purpose. It decides what can be reused in the admin (own albums, covers, page blocks), and placing a photo publishes it there. Without this, a collaborator with admin access could copy a private album's photos into their own public album.

### Adding photos

Adding a photo to a more open album publishes it. Someone who can upload to an album only through a role may add only their own uploads: `AlbumCollaboration::addablePhotoIds()` / `mayAddPhoto()`, enforced in `PhotosTable::addToAlbum`, `PhotosRelationManager::addPickedPhotos` and `PhotoForm::albumChangesAllowed`. `AlbumSettings::coverAllowed()` applies the same rule to covers: a photo in the album or their own upload.

Everyone else may add only photos they may already edit: `Photo::scopeReusableBy()` is `PhotoPolicy::update()` in SQL. Seeing a photo is not enough, because an album's owner may update its photos and download their originals, so adding a photo to one's own album would raise one's rights on it.

`PhotoUploads::allows()` lets `album:{id}` targets through for collaborators without `access admin panel` or `upload photos`; `inbox` and `page-images` still need both. The upload route only requires `auth`.

### In the admin

Collaborators use the standard album pages; there is no separate workflow on the site. The album page only links to them.

- **Entry:** `User::canAccessPanel()` is `opensAdmin()`: `access admin panel`, or at least one role. Without the permission only the Albums section opens. Every other resource and page has its own `canAccess()`; `Documentation` checks `access admin panel`, `Dashboard` redirects to Albums, and `AdminPanelProvider` turns the global search off.
- **List and edit page:** `AlbumResource::getEloquentQuery()` uses `Album::workableBy()` (`manageableBy()` plus the person's role albums) and `getEditAuthorizationResponse()` uses `workOn`. `AlbumPolicy::update()` stays closed, so never replace a check on it with `workOn`.
- **Form (`AlbumForm`, `EditAlbum`):** read-only without `update` or `editSettings`, and `save` answers 403. For Editors, `mutateFormDataBeforeSave()` keeps only `AlbumSettings::EDITOR_FIELDS`, because `Album` has `owner_id` and `parent_id` in `$fillable`. Editors without `searchDirectory` get `PeopleAndGroupsSelect::onlyKeys()` and `syncShareKeys()` only removes for them.
- **Photos section (`PhotosRelationManager`):** the same actions, each with its own ability. Role holders get no trash and no link to the media library. A photo in no other album leaves through `removePhotos(moveLastCopies: true)`.
- **Media picker:** `AlbumCollaboration::pickerAlbumId()` limits it (`limitToAlbum()`, `MediaPicker::$limitToAlbumId`) to this album's photos and the person's uploads, without the album and uploader filters, which would name other people.
- **Deleting sub-albums:** their creator passes `delete`, `restore` and `forceDelete` through `createdThroughRole()`, based on `albums.created_by` and a current role on the parent. `workableBy()` keeps those albums in their trash list. `AlbumDeletionForm` removes the "delete these photos" choice for them, so their deletion never removes photos.
- **Sub-albums:** "Add album inside" opens `CreateAlbum` with `?parent=`. Through a role it stores the parent in `$sharedParentId`, shows only the album card and saves the parent's `owner_id` and `Private`, whatever the request says.

The album form uses the dashboard's grid (`DashboardLayout::GRID_COLUMNS`, `WIDE_SPAN`, `BESIDE_WIDE_SPAN`), with the same cards on the create and edit pages. The edit page's Collaborators card renders `filament.albums.collaborators`; its actions are on the page (`Concerns\ManagesCollaborators`), because their modals hold forms and cannot be nested in the album's form. Each one re-checks `manageCollaborators`, and changes apply at once through `App\Support\AlbumCollaborators`, which lists people and groups as one paginated list, including roles from parent albums. On the create page the same card collects `invitees`, added by `CreateAlbum::afterCreate()`.

`App\Filament\Forms\Components\PeopleAndGroupsSelect` is the one picker for people and groups (sharing and collaborators). Its state is a list of keys (`user:12`, `group:3`); read them only with `App\Support\PeopleAndGroups::split()`, which drops anything that does not exist. People are found by search and never preloaded.

### Removing photos

The site uses `Album::removePhotos($photos, moveLastCopies: true)`: a photo in no other album moves to the "Unsorted uploads" of its uploader, or of the album's owner if the uploader is gone, so the rule that every photo stays in one album holds and nothing goes to the trash. Before albums are deleted for good, `AlbumCollaboration::keepCollaboratorUploads()` moves collaborators' uploads to their own "Unsorted uploads", also when "delete these photos" is chosen.

### Media library bulk actions

The media library's bulk actions (`PhotoBulkActions`, see [Bulk actions](../internals/media-library.md#bulk-actions)) check `PhotoPolicy` per record, so they only touch what the person may change and report the rest as left out. Three rules sit on top:

- "Change uploader" needs `Permission::EditAnyAlbum`, and the new uploader must hold `Permission::UploadPhotos` or be a super admin. The uploader is the basis of `PhotoPolicy::update()` and `delete()`, so the new uploader gains those rights and the old one keeps them only through albums they may edit.
- "Remove from all albums" needs `PhotoPolicy::delete()` for each photo, because it takes the photo out of every album (what trashing does, without the trash). Like the site's removal above, it keeps each photo in one album: the "Unsorted uploads" of its uploader, or of the person acting when the uploader is gone.
- "Download" only includes photos that pass `AlbumAccess::canDownload()` and hides GPS data with `PhotoLocation::isHiddenFrom()`, like the other downloads.

### Telling people

`App\Support\CollaboratorNotices::around()` compares effective roles before and after a change and sends `AlbumCollaboratorAdded` to people whose role is new or higher, never to the person making the change or the owner. Mail goes out during the request, like share notices, so adding a very large group can take long on shared hosting.

## Photos

Photos and albums are many-to-many (`album_photo`, pivot model `App\Models\Pivots\AlbumPhoto`, per-album order in `sort_order`). `Photo::albums()` includes trashed albums; `Photo::activeAlbums()` leaves them out.

- A photo can be **seen** by anyone who can see one of its non-trashed albums. Its uploader can always see it.
- `Photo::scopeVisibleTo(User)` is the query version for the admin: staff see every photo, everyone else the photos they uploaded plus the photos in albums `listableFor()` them, without albums they reach only through a collaborator role. **Always scope photo queries in the admin with it**, and re-check photo ids that come from the browser with it.
- `Photo::scopeReusableBy(User)` is the stricter query for adding a photo to an album or making it a cover: the photos the person may update.
- `App\Policies\PhotoPolicy`: `update` = uploader, or may update any of the photo's albums; `delete`, `restore` and `forceDelete` = uploader, or may update all of its albums. `viewAny` (the media library) = `access admin panel`; bulk abilities are granted there and each record is checked again.
- Photos soft-delete. Trashed photos, and photos whose albums are all trashed, are only served to people who may update them.

## Photos on pages

Pages have their own rule. `Page::isVisibleTo(?User)` is true for published pages, and for members-only pages only when logged in. A photo placed on a page with the photo or gallery block is served by `PageController@photo` (`pages.photo`) to everyone who may see the page, whatever its albums say, but only if `Page::embeddedPhotoIds()` finds its id in the page body. Only the `thumb` and `preview` conversions are served this way, never the original. They go out through `App\Support\PhotoFileResponse` like media files: always `Cache-Control: private` (with `ETag` and `304 Not Modified`), so no shared cache keeps serving a photo after its page is unpublished.

Because placing a photo publishes it, `CreatePage` and `EditPage` re-check the body before saving (`PageForm::ensurePhotosAreUsable()` → `Page::unusablePhotoIds()`): every photo id in a block that is not already on the saved page must be in `Photo::visibleTo($editor)`, otherwise the save fails with an error on the content field. Photos placed earlier, for example by staff, may stay when someone else edits the page. Covered by `tests/Feature/Backend/PagesTest.php`.

## Checklist for new code

- Listing albums on the site: `Album::listableFor($user)`. Showing one: `AlbumAccess::canView()`. Never compare `visibility` by hand.
- Listing albums in the admin: `Album::manageableBy($user)` (every album with "view/edit/delete any album", `Album::managesEveryAlbum()`, otherwise own albums), or `Album::workableBy($user)`, which adds albums with a collaborator role. `AlbumResource::getEloquentQuery()` applies `workableBy()` to the list, edit pages (other albums are 404) and global search; the "Inside album" select offers own albums (plus the current parent) unless the person may edit any album. Covered by `tests/Feature/Backend/AlbumsTest.php`.
- Listing or picking photos in the admin: `Photo::visibleTo($user)`, and re-check ids on the server. Adding them to an album or using them as a cover also needs `Photo::reusableBy($user)`.
- Serving a photo file: `route('media.show', [$media, 'thumb'|'preview'])`. Never link to storage paths or use `storage:link`. A new controller that sends photo files checks access first and then returns `PhotoFileResponse::make()`; photo files are never `public` in `Cache-Control`.
- Keeping a value for the rest of a request: `RequestMemo::instance()->remember($key, ...)`, and forget it (`forget($prefix)`) where its inputs change. Never put access decisions into the cache.
- Changing the visibility rules: update `isVisibleTo()`, `scopeListableFor()`, `AlbumAccess` and `MediaController` together, and extend `tests/Feature/System/AccessModelTest.php`.
