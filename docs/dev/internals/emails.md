---
order: 50
---

# Notifications and e-mails

The gallery tells people about things that concern them through Laravel notifications in `app/Notifications`. Most of them are shown in the gallery (the `database` channel, table `notifications`) and also e-mailed while e-mail works; a few are e-mail only. Notices that can reach many people wait in the [mail queue](#the-mail-queue); urgent mails to one person go out right away. Everything is sent in the recipient's language (`User` implements `HasLocalePreference`). The mail layout comes from Laravel's mail components, which themes can replace under `views/vendor/mail` (see [Themes](../extending/themes.md#views)).

## Notifications in the gallery

A notification that is shown in the gallery extends `App\Notifications\InAppNotification`:

- `inAppData()` returns the plain values to keep (ids, names, keys, counts; no models). `toDatabase()` stores them under `data`, with `format: filament` (Filament's panel only lists records in its format) and the title as written at send time, only as a fallback.
- `static message(array $data): InAppMessage` makes the text from those values each time it is shown, so it follows the reader's current language and wording changes. `App\Notifications\InAppMessage` holds title, body, link, icon (`Heroicon`) and colour.
- `channels(bool $mail = true, bool $queued = true)` returns `['database', QueuedMailChannel::class]` while `Mailing::delivers()`, otherwise `['database']`. With `queued: false` the mail channel is `mail`, for urgent mail to one person. The database channel comes first, so a failing mail server never loses the notice. Call it from `via()`.
- `static messageFor(array $data, ?User $reader)` is what `Inbox::message()` calls; it returns `message($data)` unless a subclass overrides it. `App\Notifications\AlbumNotification` (`AlbumShared`, `AlbumCollaboratorAdded`) does: once the reader can no longer open the album (`Inbox::canOpenAlbum()`, based on `Album::isVisibleTo()`), the notice shows a neutral text without title or link. Its `shouldSend()` also drops a waiting e-mail in that case.

`App\Support\Inbox` reads them: `message($notification)` (falls back to the stored title when the class is gone, for example a removed plugin), `unreadCount($user)` (once per request), `loadAlbums($notifications)` (the albums of a page of notices in one query), `markHandled($type, $key, $value)` / `forget(...)` for notices about something that was dealt with or deleted, `prune()` (older than `Inbox::KEEP_DAYS`, 180, run by the background jobs) and `peopleWho(Permission $permission)` (holders of a permission, directly or through a role, plus super admins).

Two places show them, both through `Inbox::message()`:

- **Admin**: `App\Livewire\DatabaseNotifications` extends Filament's panel component (dates in `DateFormat::DATE_TIME`, text from `Inbox`). It is registered with `databaseNotificationsLivewireComponent()` in `AdminPanelProvider` but Filament's own placement stays off (`databaseNotifications()` is not called): `filament.partials.topbar-account` renders it before the light/dark switch, with the trigger `filament.partials.notifications-trigger`. It polls every 60 seconds (`databaseNotificationsPollingInterval`). Registering the class matters: Filament's component uses the same Livewire name and would otherwise replace it.
- **Site**: the component `<x-notification-bell />` (in every header layout, core and themes; renders nothing for guests) links to `notifications.index` (`NotificationController@index`, view `notifications/index.blade.php`, 20 per page). Opening the page marks the shown notifications as read.

When a user is deleted, `User`'s `deleted` hook removes their notifications and the `AccountCreated` notices about them.

## What is sent

| Notification | Sent by | To | Channels |
| --- | --- | --- | --- |
| `UserInvited` | `App\Support\Invitations::send()`: "Send an invitation" when creating a user (`CreateUser`), "Send password link" on a user's page (`EditUser`) | The invited user | mail |
| `AlbumShared` | `App\Support\ShareNotices::notifyNew()`: saving an album with "Tell people when they get access" (`CreateAlbum`, `EditAlbum`) | People who gained access to a restricted album, directly or through a group, except the person saving | gallery + queued mail |
| `GroupAlbumsShared` | `App\Support\GroupNotices::notifyNew()`: "Add members" on a group (switch on by default) and a person's user form (switch off unless turned on). Groups filled by an identity provider at sign-in stay quiet | People added to a group who can now reach more albums (shared or as a collaborator): one notice with the number of albums | gallery + queued mail |
| `AlbumCollaboratorAdded` | `App\Support\CollaboratorNotices::around()`: the album's Collaborators card, on the edit and create pages | People whose collaborator role is new or higher, directly or through a group, except the person making the change and the owner | gallery + queued mail |
| `AccountCreated` | `App\Support\AccountNotices::registered()`, from the `Registered` listener in `AppServiceProvider` (local and identity-provider sign-ups) | `Inbox::peopleWho(Permission::ManageUsers)`, while `SiteSettings::notifiesAboutRegistrations()` is on or the account awaits approval. `AccountApproval::approve()` marks them read (`Inbox::markHandled`) | gallery + queued mail |
| `AccountApproved` | `App\Support\AccountApproval::approve()` ("Approve" in the users list and on a user's page) | The approved user | mail |
| `SiteProblemFound` | `App\Support\SiteHealthNotices::check()`, right after `SiteHealth::refresh()` in the background jobs | `Inbox::peopleWho(Permission::ManageSettings)`, once per new problem or warning (not notes); marked read when the problem is gone | gallery; + queued mail for `danger` |
| `SessionsSignedOut` | `App\Support\SecurityNotices::sessionsSignedOut()`: `UserSessions` when the person signing out is not the account itself | The account | gallery + mail |
| `PasswordChanged` | `App\Support\SecurityNotices::passwordChanged()`: `PasswordController@update` (profile), `NewPasswordController@store` (reset link), `EditUser::afterSave()` (with the admin's name) | The account | gallery + mail |
| `EmailAddressChanged` | `SecurityNotices::emailChanged()`: `ProfileController@update`, `EditUser::afterSave()` | The account (gallery) and the old address (mail, on demand, in the account's language) | gallery / mail |
| `LoginBlocked` | `SecurityNotices::lockedOut()`, from the `Lockout` listener in `AppServiceProvider`; the notice itself is made by the next background pass | The account, at most once per `SecurityNotices::LOCKOUT_QUIET_SECONDS` (1 hour) | gallery + queued mail |
| Laravel's `VerifyEmail` | `User::sendEmailVerificationNotification()`: Laravel's `Registered` listener, a changed address in the profile, "Resend" on the confirmation page | The user, only while `Mailing::delivers()` | mail |

Security notices are sent where the change happens, never from model events: Laravel rehashes passwords on login by itself, and `InvitationController` fires `PasswordReset` for a first password, which is not a change. A blocked login queues the same job for known and unknown addresses (limited per address by a keyed hash), so the login page's response time does not reveal which addresses have an account. `SiteHealthNotices` keeps the problem keys of the last check in the setting `notices.site-health`; the very first check only stores them, so an update does not send a burst of notices about problems the dashboard already shows.

In the admin, report what happened with `App\Filament\Notifications\EmailResults`: `invitation($user, $result)` after `Invitations::send()` (sent, in the test mailbox, or the link to pass on) and `shareNotices($result)` after `ShareNotices::notifyNew()` (`told` in the gallery, `sent` for e-mails on their way, `failed`). Every page that sends these uses it, so the messages read the same.

### Adding a notification

Extend `InAppNotification`, keep `inAppData()` to plain values, build every text in `message()` and `toMail()` with `__()` (add the keys to every `lang/*.json`), and send it through `Mailing::send(fn () => $user->notify(...))`, or `Mailing::notifyEach($userIds, $make)` for many people (written in portions, each in one transaction). A notice about one album extends `AlbumNotification`. Plugins can do the same with their own namespaced texts; both bells show them without further registration.

## Sending safely

`App\Support\Mailing` holds the two rules every sender follows:

- `Mailing::delivers()` is false while mail only goes to the log (`MAIL_MAILER=log` with "Server configuration") or to the test `array` mailer. Senders then skip the mail (notifications in the gallery still go out); the admin shows invitation links to pass on instead. The test mailbox counts as delivering.
- `Mailing::send(fn () => ...)` reports a failing mail server (`report()`) and returns false instead of throwing, so a broken mail setup never stops a save or a sign-up. Callers tell the admin what was not sent.

Tests switch delivery on with `config(['mail.default' => 'smtp'])` and `Notification::fake()` (see `tests/Feature/Backend/UsersTest.php` and the "share notices" tests in `tests/Feature/Backend/AlbumsTest.php`). To run real sending through the queue, use the `array` transport for that mailer (see `tests/Feature/System/MailQueueTest.php`).

## The mail queue

`App\Support\MailQueue` keeps a save from waiting on a mail server and respects a host's sending limit, without a queue worker. It always uses the `database` queue connection, whatever `QUEUE_CONNECTION` says.

- `App\Notifications\QueuedMailChannel` stores a `SendQueuedNotifications` job for the `mail` channel on the queue `mail`, while the `database` channel writes the notice at once. The test mailbox has no limit and is read right away, so it skips the queue.
- Every background pass calls `MailQueue::work()`: first the queue `notices` (work that must not run during a request, such as blocked logins), then at most `PER_PASS` (50) e-mails for `MAIL_SECONDS` (10 s), within what is left of the hourly limit.
- The hourly limit is `SiteSettings::mailHourlyLimit()` (Admin → Settings → E-mail, default 200). Every message that leaves the server counts (`MessageSent`), also those sent right away; those are never held back.
- While `Mailing::delivers()` is false the e-mails wait. A refused e-mail is tried `MailQueue::TRIES` (5) times, after 5 minutes, 15 minutes, 1 hour and 6 hours, then lands in `failed_jobs` (site health: "Background jobs failed"). Its notice in the gallery stays.
- A waiting e-mail whose model was deleted is dropped (`deleteWhenMissingModels`). The SMTP connection times out after `MAIL_TIMEOUT` (15 s by default).
- While e-mails can be sent, AJAX passes start every `AJAX_QUEUE_SECONDS` (`BackgroundJobs::ajaxDue()`). Waiting e-mails do not count as stuck jobs in site health.

Password and e-mail change notices, invitations, approvals, confirmation and reset links go out right away: they concern one person and are urgent.

## The test mailbox

`App\Enums\MailDelivery::Mailbox` (Admin → Settings → E-mail → "Test mailbox", replacing the former `log` choice; migration `2026_11_15_121658_create_test_mails_table` switched stored `log` values) selects the mailer `mailbox` (`config/mail.php`), whose transport `App\Support\MailboxTransport` (registered with `Mail::extend()` in `AppServiceProvider`) stores each message in `test_mails` (`App\Models\TestMail`: from, to, subject, html, text) instead of sending it. `TestMail::trim()` keeps the newest `TestMail::KEEP` (100) after every message; `model:prune` removes mails older than `TestMail::KEEP_DAYS` (7). `Mailing::usesTestMailbox()` lets the admin say "is in the test mailbox" instead of "sent".

`App\Filament\Pages\TestMailbox` lists them, for super admins only: the mails hold working reset and invitation links for every account, so anything less would let people with "manage settings" take over other accounts. The HTML is shown in an `<iframe sandbox>` without scripts (`srcdoc`, links open in a new tab).

## Downloads as zip files

`App\Support\PhotoZip::make($name, $photos, $folder)` streams the original files as a zip with Spatie's `MediaStream` (ZipStream, part of the media library): nothing is written to disk, memory stays low, files are stored without compression and the time limit is lifted. It serves `AlbumController@download` (`albums.download`, needs `canView()` and `allow_downloads` or the right to edit the album) and `ProfileController@downloadPhotos` (`profile.photos.download`, the user's own uploads, one folder per first album via `PhotoZip::folderFor()`). These are not notifications, but they replace the data export the delete-account text used to promise.

## Invitations

Invitations use their own password broker, `invitations` in `config/auth.php` (table `user_invitation_tokens`, valid 7 days, no throttle). A separate table keeps a normal reset link (60 minutes, `password_reset_tokens`) from ever being accepted for that long.

- `Invitations::link($user)` creates a token (earlier invitation links stop working) and returns `route('invitation.show', [token, email])`.
- `InvitationController` shows the reset form in its invitation variant (`auth.reset-password` with `$invitation`), resets the password through the `invitations` broker, sets `email_verified_at` if empty, logs the person in and redirects to "My albums". Routes `invitation.show` / `invitation.store` are guest-only; the POST is throttled.
- Users created with an invitation get a random 64-character password until they choose one.
