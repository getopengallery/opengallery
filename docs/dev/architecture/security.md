---
order: 50
---

# Security measures

Protections that apply across the application. Who may see and change what is described in the [access model](access-model.md).

## Site address

Laravel builds absolute links from the request's `Host` header. `App\Http\Middleware\UseConfiguredSiteAddress` (first global middleware) keeps a forged header out of links, above all out of password reset e-mails.

A request is on one of the site's addresses when its host is `APP_URL`, the www or non-www twin of `APP_URL`, or listed in `GALLERY_TRUSTED_HOSTS` (`config('gallery.trusted_hosts')`). Any other request gets its links from `APP_URL` through `URL::forceRootUrl()` and `URL::forceScheme()`. Nothing is pinned in the `local` environment, before the installation is finished, or while `APP_URL` is a loopback address.

## Security headers

`App\Http\Middleware\AddSecurityHeaders` (global) adds these to every response unless the controller already set them:

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | `base-uri 'self'; object-src 'none'; frame-ancestors 'self'` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Strict-Transport-Security` | `max-age` from `config('gallery.hsts_max_age')`, on HTTPS only; `0` turns it off |

The content security policy does not limit scripts or styles: Alpine and Filament use inline code and `eval`, and themes and plugins may load their own files. Controllers that serve uploaded files keep their stricter policy.

## Sessions and passwords

`AuthenticateSession` runs in the `web` group and the admin panel, so a session or "remember me" cookie from before a password change stops working. `PasswordController` also replaces the remember token and calls `logoutOtherDevices()`, which keeps the current device signed in.

### Where people are signed in

Profile (`SessionController`, routes `profile.sessions.destroy` and `profile.sessions.destroy-others`) and the admin's user page (`App\Filament\Resources\Users\RelationManagers\SessionsRelationManager`) list and end sessions through `App\Support\UserSessions`. Deleting the `sessions` row signs that browser out on its next request. Everything is hidden unless `UserSessions::available()` (the `database` driver).

- `signOut()` ends one session. It does not touch the remember token, so a remembered browser can sign itself back in.
- `signOutAll()` ends every session except an optional current one and replaces `remember_token`; the profile action then calls `logoutOtherDevices()` (without events) so this browser gets a fresh cookie. The route has `throttle:password-check` and asks for the current password.
- The admin's relation manager is only visible to people who pass `UserResource::canEdit()` (`ManageUsers` plus the role hierarchy; super admins always).
- Both write `ActivityType::SessionsSignedOut` through `ActivityLog::sessionsSignedOut()`. When someone else signs a person out, `SecurityNotices::sessionsSignedOut()` sends `App\Notifications\SessionsSignedOut` in the gallery and by e-mail.

The `files` route group uses `App\Http\Middleware\IgnoreOutdatedFileSession` instead: an outdated session counts as a guest there rather than being redirected, because an image cannot follow a redirect and public photos should still load.

Changing the e-mail address on the profile needs the current password (`ProfileUpdateRequest`).

## Hiding which accounts exist

- `PasswordResetLinkController` always gives the same answer and sends the e-mail after the response with `defer()`, so the sending time reveals nothing either.
- `NewPasswordController` and `InvitationController` answer an unknown address like a wrong link.
- `RegisteredUserController` gives the same answer for new and taken addresses while `Mailing::delivers()` is true: a new account confirms by e-mail and logs in afterwards, and the owner of a taken address gets `App\Notifications\SignUpAttempted` (at most once per hour). Without working e-mail nobody could confirm, so a taken address is reported as a validation error.

The e-mail field on the profile and the admin's user form still report a taken address, because only logged-in people reach them.

## Rate limits

Named limiters are defined in `AppServiceProvider::configureRateLimiting()`. Use named limiters for new forms: plain `throttle:6,1` limits with the same signature share one counter per visitor across routes.

| Limiter | Routes | Limit |
| --- | --- | --- |
| `login` | `POST /login` | 30 per minute and IP, on top of 5 failures per address and IP (`LoginRequest`) |
| `password-email` | `POST /forgot-password` | 5 per minute and 30 per hour per IP |
| `password-reset` | `POST /reset-password` | 10 per minute per IP |
| `register` | `POST /register` | 5 per minute and 20 per hour per IP |
| `password-check` | confirm password, change password, update and delete profile, sign out all other devices | 6 per minute per account, only for requests that send a password |

## Location data

`App\Support\PhotoLocation` removes GPS positions from photo files while they are sent. The stored file never changes. It overwrites the GPS block of EXIF (JPEG, PNG, WebP, HEIC, AVIF) and GPS values in XMP with as many bytes as were there, and updates PNG checksums.

`PhotoLocation::isHiddenFrom()` decides per photo: on while `SiteSettings::removesPhotoLocation()` is true, for everyone who may not update the photo. Conversions are only processed with the Imagick driver, because GD writes no metadata. `PhotoFileResponse` then streams through PHP instead of `GALLERY_SENDFILE`, and album zips go through `App\Support\PhotoZipStream`.

## Updates

One-click updates run new code on the server, so a release is only installed with a valid Ed25519 signature made by the key of its channel. Only super administrators can start one, with their password. See [One-click updates](../internals/updates.md).

## Protecting the project folder

The `.htaccess` in the project root refuses every request on Apache-compatible servers, in case the document root points there by mistake. `public/.htaccess` turns the rewrite engine on with its own rules, which replace the parent's, so the site itself is not affected. nginx ignores these files and must use `public` as its root.
