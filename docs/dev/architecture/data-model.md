---
order: 30
---

# Data model

The tables OpenGallery creates, what each column means and how the models relate. The migrations in `database/migrations` are the source of truth; this page explains them.

## Overview

- A **user** owns **albums**, belongs to **groups** and has **roles**.
- An **album** has a parent album, many **photos** (through `album_photo`), is shared with users (`album_user`) and groups (`album_group`), and can have collaborators (`album_collaborators`, `album_group_collaborators`).
- A **photo** has one original file and its conversions in the **media** table, and is in one or more albums.
- **Pages**, **settings** and **activity events** stand on their own.

## Albums and photos

### `albums` (`App\Models\Album`, soft deletes)

| Column | Meaning |
| --- | --- |
| `parent_id` | Parent album, or null for a top-level album. `App\Support\AlbumDeletion` moves children to the deleted album's parent first; the `nullOnDelete` key is only a safety net. |
| `owner_id` | Owner (`users.id`), nullable. Deleting the user only clears it (`nullOnDelete`); `App\Support\AccountDeletion` decides beforehand what happens to the albums (see [Deleting users](access-model.md#deleting-users)). Albums without an owner are reachable by staff only. |
| `created_by` | Who created the album (`users.id`), nullable, `nullOnDelete`, set on create and not fillable. Lets someone delete a sub-album they created through a collaborator role, because `owner_id` is the parent's owner. |
| `title`, `description` | Shown on the site |
| `slug` | Unique; used in URLs (`getRouteKeyName()`). Generated from the title plus six random characters on create. |
| `visibility` | `App\Enums\Visibility` value (see [Access model](access-model.md)) |
| `share_token` | 40 characters while the album is unlisted, null otherwise |
| `password` | Hashed album password, or null |
| `access_expires_at` | End of sharing, or null |
| `allow_downloads` | Whether the album's visitors may download the original files (`AlbumAccess::canDownload()`); false by default and for private albums |
| `cover_photo_id` | Chosen cover, or null (the first photo is shown). Set to null when that photo is deleted. |
| `sort_order` | Position among its siblings |
| `photo_order` | `App\Enums\PhotoOrder` value; applied in `Album::photos()`. New albums take `SiteSettings::defaultPhotoOrder()`. |
| `tree_path` | Position in the whole tree, maintained by `Album::refreshTreePath()`; never set by hand |
| `deleted_at` | Trash |

Relations: `owner()`, `parent()`, `children()` (ordered by `sort_order`), `photos()`, `coverPhoto()`, `sharedUsers()`, `sharedGroups()`, `collaborators()`, `collaboratorGroups()`.

Every person can have two **system albums**, created on demand by `Album::inbox(User)` ("Unsorted uploads") and `Album::pageImages(User)` ("Page images"). `system_album` (`inbox`, `page-images` or null) marks them, with a unique index on `owner_id` + `system_album`; a trashed system album is restored when it is needed again. Before migration `2026_11_14_215133_give_everyone_their_own_system_albums` there was one of each for the whole site (slugs `unsorted-uploads`, `page-images`); the migration moved other people's photos into their own.

### `photos` (`App\Models\Photo`, soft deletes, implements `HasMedia`)

| Column | Meaning |
| --- | --- |
| `uploader_id` | Who uploaded it; null after that user is deleted |
| `title`, `description` | Shown on the site |
| `taken_at` | From the camera data, editable |
| `width`, `height` | Of the original |
| `exif` | Camera data read on upload (JSON) |
| `deleted_at` | Trash |

Relations: `albums()` (including trashed albums), `activeAlbums()` (a collection without them), `coverOf()` (albums using it as cover), `uploader()`. `original()` returns the file (`Photo::COLLECTION`, `original`).

### `album_photo` (pivot `App\Models\Pivots\AlbumPhoto`)

`album_id`, `photo_id` (primary key together), `sort_order` (position in the album with manual order), `created_at`. Both foreign keys cascade on delete. To keep every photo in at least one album, delete albums permanently only through `App\Support\AlbumDeletion::forceDelete()` (used by the admin's "Delete permanently" actions): photos left in no album move to "Unsorted uploads", or are deleted on request unless a page uses them (`Page::usedPhotoIds()`).

Add photos with `Album::addPhotos()` (no log entry; uploads are logged per batch, other callers log `photosAdded()`) or `$photo->albums()->attach()` (logged as "Albums: +X" through the pivot). Remove several photos from one album with `Album::removePhotos()`, which keeps every photo in at least one album and logs once; with `moveLastCopies: true` a photo in no other album moves to its uploader's "Unsorted uploads" instead of staying. Covers are kept valid by the pivot and by `Photo` model events.

### `media` (Spatie Media Library)

One row per original file, polymorphic to the photo (`model_type`, `model_id`). Files are on the `gallery` disk (`config('gallery.media_disk')`). Two conversions are registered in `Photo::registerMediaConversions()`, both WebP and never larger than the original:

| Conversion | Size |
| --- | --- |
| `thumb` | fits in 720 × 720 |
| `preview` | fits in 1920 × 1920 |

They are generated during the upload request unless `config('gallery.queue_conversions')` is true.

## People

### `users` (`App\Models\User`)

Laravel's columns (`name`, `email`, `email_verified_at`, `password`, `remember_token`) plus:

| Column | Meaning |
| --- | --- |
| `avatar_path` | Profile picture on the `local` disk under `avatars/`, written by `App\Support\Avatars::store()`; hidden, so changes are logged without values. The file is deleted when the user is deleted. |
| `locale` | Chosen language, or null for the site default |
| `awaiting_approval` | True for people who signed up while "New accounts need approval" is on, until someone approves them (`App\Support\AccountApproval`). Not mass assignable. |
| `dashboard_layout` | JSON, null = defaults: the person's arrangement of the admin dashboard (`{"v": 1, "widgets": [{"key", "hidden", "size"}]}`), read and written only by `App\Support\Dashboard\DashboardLayout`. Not mass assignable, saved quietly and ignored by the activity log (a personal preference); deleted with the account. |

`User` implements Filament's `FilamentUser` and `HasAvatar` and Laravel's `HasLocalePreference` and `MustVerifyEmail`. `User::mustConfirmEmail()` (no `email_verified_at` while `Mailing::delivers()`) and `awaiting_approval` are enforced by `App\Http\Middleware\EnsureAccountIsActive` on the site (web group) and in the admin panel: such accounts are redirected to `verification.notice` or `approval.notice`; logout, those pages and the profile stay open. `email_verified_at` is not mass assignable, so `CreateUser` / `EditUser` save it with `forceFill()`. `sendEmailVerificationNotification()` only sends while mail works and never throws. Migration `2026_11_15_120405_add_awaiting_approval_to_users_table` marked every older account as confirmed.

Relations: `roles()` (through the `UserRole` pivot), `groups()`, `albums()` (owned), `sharedAlbums()`, `collaborationAlbums()`, `socialAccounts()`.

### `groups` (`App\Models\Group`) and `group_user` (pivot `GroupUser`)

`name`, `slug` (unique), `description`. Members in `group_user` (`group_id`, `user_id`). Relations: `users()`, `albums()` (shares), `collaborationAlbums()`.

### `album_user` and `album_group` (pivots `AlbumUser`, `AlbumGroup`)

Shares of restricted albums: `album_id` plus `user_id` or `group_id`. A share only grants viewing (the former `can_upload` columns were removed).

### Album collaborators

People and groups who work on an album, in `album_collaborators` and `album_group_collaborators` (pivots `AlbumCollaborator`, `AlbumGroupCollaborator`). Never reuse the share tables for this: shares only grant viewing and only apply to restricted albums.

| Column | Meaning |
| --- | --- |
| `album_id` | The album; the role also applies to everything below it. Cascades on delete. |
| `user_id` / `group_id` | Who has the role. Cascades on delete. Unique together with `album_id` (primary key). |
| `role` | `App\Enums\CollaboratorRole` value |
| `added_by` | Who added them; null after that user is deleted |
| `created_at`, `updated_at` | When |

The pivots refuse rows for system albums and, for people, the album's owner. `Album` removes the new owner's direct row when `owner_id` changes. Both pivots log adding and removing through `RecordsPivotActivity`, and role changes through `ActivityLog::collaboratorRoleChanged()`. How a person's role is worked out: [Access model](access-model.md#collaboration).

### Roles and permissions

Spatie's tables `roles`, `permissions`, `model_has_roles`, `model_has_permissions` and `role_has_permissions`. `App\Models\Role` extends Spatie's role to use the logging pivots `UserRole` (a `MorphPivot`) and `RolePermission`. Permissions are only ever assigned to roles, not to users directly.

### `social_accounts` (`App\Models\SocialAccount`)

Identities linked by the identity-providers plugin: `user_id`, `provider` (the provider's identifier), `provider_user_id`, `email`, `avatar`, `token`, `refresh_token`, `token_expires_at`. Unique per provider and provider user, and one per user and provider. Core keeps the table and the unlink route (`linked-accounts.destroy`); everything else is in the plugin.

## Content and state

### `pages` (`App\Models\Page`)

`title`, `slug` (unique, used in URLs), `body` (rich editor HTML, rendered only through `RichContentRenderer`), `is_published`, `members_only`. No soft deletes. Photo and gallery blocks store their photo ids in the body (`data-config`), read by `Page::embeddedPhotoIds()`.

### `settings` (`App\Models\Setting`)

`key` (primary key) and `value` (JSON). Runtime settings: the active theme, theme options, enabled plugins, plugin settings and Admin → Settings. See [Site settings internals](site-settings.md).

### `activity_events` (`App\Models\ActivityEvent`, mass prunable)

| Column | Meaning |
| --- | --- |
| `type` | `App\Enums\ActivityType` value |
| `user_id` | Who did it; null for guests and the system |
| `album_id` | The album, for album views and album changes |
| `visitor` | Daily-rotating anonymous hash for guests; no IP address or user agent is ever stored |
| `subject_type`, `subject_id` | The changed record (polymorphic) |
| `properties` | Details of the change (JSON) |
| `created_at` | When |

`ActivityEvent::prunable()` removes entries older than `config('gallery.activity_retention_days')` when `model:prune` runs.

### `test_mails` (`App\Models\TestMail`, mass prunable)

E-mails kept by the test mailbox instead of being sent: `from`, `to`, `subject`, `html`, `text`, `created_at`. At most the newest 100, for 7 days. See [E-mails](../internals/emails.md#the-test-mailbox).

### `notifications` (Laravel's `DatabaseNotification`)

Notifications shown in the gallery: `id` (UUID), `type` (the notification class), `notifiable_type` / `notifiable_id`, `data` (JSON: `format`, fallback `title`, and the values of `InAppNotification::inAppData()` under `data`), `read_at`, timestamps (`created_at` indexed). Removed after 180 days by the background jobs and with the user. See [Notifications and e-mails](../internals/emails.md#notifications-in-the-gallery).

### `user_invitation_tokens`

`email` (primary key), `token` (hashed) and `created_at`, like `password_reset_tokens`, for the `invitations` password broker. See [E-mails](../internals/emails.md#invitations).

## Laravel's own tables

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` and `failed_jobs` come with Laravel. Sessions and the cache use the database by default (`.env.example`), so no extra services are needed.

With `SESSION_DRIVER=database`, sessions are written by `App\Support\PrivateDatabaseSessionHandler` (registered as the `database` driver in `AppServiceProvider` with `Session::extend()`). It is Laravel's handler without `addRequestInformation()`, so `sessions.ip_address` and `sessions.user_agent` always stay empty; the migration `2026_11_14_213203_clear_ip_addresses_and_user_agents_from_sessions` cleared what older versions stored. Do not register another session driver that records them. Covered by `tests/Feature/SessionPrivacyTest.php`.

## Pivots and the activity log

Every pivot that should appear in the activity log uses a pivot model with the `App\Models\Pivots\RecordsPivotActivity` trait, set with `->using()` on **both** sides of the relation: `AlbumPhoto`, `AlbumUser`, `AlbumGroup`, `AlbumCollaborator`, `AlbumGroupCollaborator`, `GroupUser`, `UserRole`, `RolePermission`. A new many-to-many relation that should be logged needs the same.
