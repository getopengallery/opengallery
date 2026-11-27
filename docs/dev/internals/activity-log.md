---
order: 30
---

# Activity log internals

The activity log records visits (logins, album views), changes made in the admin and security events in the `activity_events` table. Everything goes through `App\Support\ActivityLog`; the admin shows it in the activity log resource and on the dashboard. What administrators see and configure is described in the user guide's [Dashboard and activity log](../../user/administration/activity.md).

## Privacy

- No IP address or user agent is ever written to `activity_events`.
- Guests are identified by `ActivityLog::visitor()`: an HMAC (keyed with `APP_KEY`) of IP, user agent and the current date. It changes every day, so it counts unique visitors per day but cannot link one person's visits across days.
- E-mail addresses typed into the login form are never stored; a failed login is only linked to an account if one matches.

Database sessions keep nearly the same promise: `App\Support\PrivateDatabaseSessionHandler` leaves `sessions.ip_address` empty and stores only the short `App\Support\UserAgentLabel` ("Firefox on macOS") in `sessions.user_agent`, never the raw user agent; see [Data model](../architecture/data-model.md#laravels-own-tables).

## Levels

`App\Enums\ActivityLevel` (Admin → System → Activity log → Log settings, read with `SiteSettings::activityLevel()`) decides what is written:

| Level | Value | `logsChanges()` | `logsSecurity()` | `logsLogins()` | `logsVisits()` |
| --- | --- | --- | --- | --- | --- |
| `Off` | `off` | no | no | no | no |
| `Changes` | `changes` | yes | yes | no | no |
| `ChangesAndLogins` | `logins` | yes | yes | yes | no |
| `All` | `all` | yes | yes | yes | yes |

`ActivityLog::write()` checks the level for every entry: security types need `logsSecurity()`, logins and album views check their own method before writing, and everything else needs `logsChanges()`. Writing never throws: errors are reported and the page, login or save carries on.

## Event types

`App\Enums\ActivityType` lists every type with `label()` for display. `isSecurity()` marks `LoginFailed`, `LockedOut`, `PasswordReset`, `AlbumPasswordFailed`, `AlbumGuestCodeFailed`, `AccountLinked`, `AccountUnlinked` and `SessionsSignedOut`; `isVisit()` marks `Login` and `AlbumView`. These two groups are the "Kind" filter of the activity log; everything else counts as a change.

## Where entries come from

| Source | Writes |
| --- | --- |
| `App\Observers\ActivityObserver` on `Album`, `User`, `Group`, `Role`, `Page`, `Photo`, `License` (registered in `AppServiceProvider`) | `created`, `updated`, `deleted`, `restored`. A photo's `created` is skipped; uploads are logged per batch. |
| `App\Models\Pivots\RecordsPivotActivity` on the pivot models | Adding and removing related records ("Albums: +X", members, roles, permissions, shares) through `ActivityLog::pivotChanged()` |
| Auth event listeners in `AppServiceProvider` | `Login`, `LoginFailed`, `LockedOut`, `PasswordReset` |
| `AlbumController` | `AlbumView` (once per album, visitor and day, remembered in the session), `AlbumPasswordFailed`, `AlbumGuestCodeFailed` (wrong code on a guest link) |
| Explicit calls in the admin | `photosUploaded()`, `photosAdded()`, `photosRemoved()`, `photosReordered()`, `albumsReordered()`, `settingsChanged()`, `extensionChanged()` (theme activated, plugin on or off, theme or plugin uploaded) |
| `App\Models\SocialAccount` events (`created`, `deleted`) | `AccountLinked`, `AccountUnlinked` |
| `App\Support\UserSessions` | `SessionsSignedOut` through `ActivityLog::sessionsSignedOut($user, $count, $by)`: the subject is the signed-out account, the actor the person who did it (the account itself when `$by` is null), `properties` holds `count`. Written at every level except `Off`, like all security types. |

## How changes are recorded

`ActivityLog::updated()` records the changed attributes with their old and new values:

- Attributes in `ActivityLog::IGNORED` (`id`, timestamps, `remember_token`, `share_token`, `slug`, `guard_name`, `tree_path`) are never reported.
- Hidden attributes (`$hidden` on the model, such as passwords and `avatar_path`) are recorded as "changed" without values.
- Field names are stored in English and translated when shown (`ActivityEvent::details()`), so every reader sees their own language.

### Merging

Changes to the same subject by the same person within `ActivityLog::MERGE_SECONDS` (10 seconds) are folded into the latest entry, so one form save (attributes plus relations) reads as one line. If merged changes cancel each other out (a member removed and added again), the entry is deleted.

### Bulk actions

Every Filament bulk action is wrapped in `ActivityLog::startBatch()` and `finishBatch()` (configured in `AppServiceProvider`). While a batch is open, created, updated, deleted and restored entries are collected by type and subject class instead of written, and the batch writes one entry per group ("Moved 200 photos to the trash", with the first three names). Side effects inside a batch, such as the album links of deleted photos, are not logged separately. Field changes made inside a batch (for example the media library's "Set license") are not logged by the observer (it skips `updated` entries while a batch is open); call `ActivityLog::changedInBulk($model)` for each record that changed, so the batch writes one "changed N photos" entry. Wrap your own loops with `ActivityLog::batch(fn () => ...)` to get the same behaviour.

### Upload batches

Chunked uploads finish one photo per request. Each drop in the photo picker has a batch id; `ActivityLog::photosUploaded($album, $count, $failed, $batch)` adds every finished photo to the same entry (under a short cache lock), so a drop of 500 photos is one "Uploaded photos" entry with a count.

## Rules for new code

- Log bulk operations once, not per item.
- Use `updateQuietly()` for automatic changes nobody made by hand (covers, tree paths), so they do not appear as someone's change.
- A new many-to-many relation that should be logged must use `->using(<Pivot with RecordsPivotActivity>)` on **both** sides, and needs a label in `ActivityLog::RELATIONS`.
- A new logged model needs the observer (`AppServiceProvider`) and an entry in `ActivityLog::NOUNS`.
- A new event type needs a case in `ActivityType` with a `label()` (and `isSecurity()` / `isVisit()` if it belongs there), and its texts in every `lang/*.json`.

## Retention

`ActivityEvent` is `MassPrunable`: `prunable()` selects entries older than `config('gallery.activity_retention_days')` (the same Log settings page, 1 to 3650 days, default 365). The scheduled `model:prune` (daily, `routes/console.php`) deletes them, so retention needs the cron job.

## Dashboard

The activity widgets (`App\Filament\Widgets\LibraryOverview`, `ActivityCalendar`, `ActivityTrends`, `RecentActivity`, `PopularAlbums`) are shown to people with `view activity` and read through `App\Support\DashboardStats`:

- `totals()`: counts of the whole library (only numbers; system albums are not counted), cached for `DashboardStats::CACHE_SECONDS` (10 minutes).
- `calendar($mode, $weeks)`: `DashboardStats::MEMBERS` or `GUESTS`, 53 weeks by default. The per-day counts are cached for 10 minutes; the widget is lazy-loaded.
- `trends($weeks)`: album views (members and guests) and logins per week, 12 weeks by default, grouped by `DATE(created_at)` in SQL and bucketed in PHP (portable between MySQL and SQLite). Cached, lazy.
- `recent(User $viewer, $limit)`: without `view any album`, entries whose `album_id` or album or photo subject the viewer cannot list (`Album::listableFor()`, `Photo::visibleTo()`) are left out. The activity log resource itself is not filtered.
- `popularAlbums(User $viewer, $days, $limit)`: 30 days, 6 albums, among those the viewer may list. Albums the viewer may update link to the album editor.

`ActivityEvent::subjectUrl()` links users, groups and roles only for people who may open those resources. The other dashboard widgets are described in [Dashboard widgets](../extending/dashboard-widgets.md).
