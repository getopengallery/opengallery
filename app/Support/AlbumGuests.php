<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Models\Album;
use App\Models\AlbumGuest;
use App\Models\User;
use App\Notifications\AlbumGuestCode;
use App\Notifications\AlbumGuestInvited;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sharing a restricted album with people who have no account, by e-mail.
 * Each guest gets a personal link once; opening it e-mails a 6-digit code
 * that is valid for CODE_MINUTES and allows MAX_ATTEMPTS tries. The right
 * code opens the album for the browser session (AlbumAccess::rememberGuest()).
 * The album's own rules (password, end date, downloads, sub-albums) still
 * apply. Guests keep access for SiteSettings::albumGuestDays() after being
 * invited, while sharing by e-mail is on (Settings → Access). An address that belongs to an account is shared with that account
 * instead (AlbumSettings::syncShareKeys()).
 */
class AlbumGuests
{
    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Normalised e-mail address, or null when $value is not one. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = mb_strtolower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Makes the guests of $album exactly $emails: new addresses get a guest
     * and their link by e-mail, missing ones lose access.
     *
     * @param  list<string>  $emails  normalised addresses without an account
     * @return int how many invitations were sent
     */
    public static function sync(Album $album, array $emails, User $editor): int
    {
        $album->guests()->whereNotIn('email', $emails)->delete();
        RequestMemo::instance()->forget('album-guest:'.$album->id.':');

        $existing = $album->guests()->pluck('email')->all();
        $sent = 0;

        foreach (array_diff($emails, $existing) as $email) {
            $sent += self::invite($album, $email, $editor) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Adds $email as a guest and e-mails them their link (through the mail
     * queue). Returns the link, or null when the invitation could not be queued.
     */
    public static function invite(Album $album, string $email, ?User $editor = null): ?string
    {
        $token = Str::random(40);

        $guest = $album->guests()->create([
            'email' => $email,
            'token' => self::hash($token),
            'invited_by' => $editor?->id,
        ]);

        $url = route('albums.guest', [$album, $token]);

        $queued = Mailing::send(fn () => MailQueue::push(
            Notification::route('mail', $email),
            new AlbumGuestInvited($guest, $url, $editor?->name),
        ));

        return $queued ? $url : null;
    }

    /** The guest of $album whose link carries $token. */
    public static function findByToken(Album $album, string $token): ?AlbumGuest
    {
        return $album->guests()->where('token', self::hash($token))->first();
    }

    /**
     * Whether guests may use $guest's link at all: the feature is on (Settings
     * → Access), the album is still shared that way and the guest's time
     * (SiteSettings::albumGuestDays(), counted from the invitation) has not run out.
     */
    public static function isActive(AlbumGuest $guest): bool
    {
        $album = $guest->album;

        if (! SiteSettings::albumGuestsEnabled() || $album === null || $album->trashed() || $album->visibility !== Visibility::Restricted) {
            return false;
        }

        $days = SiteSettings::albumGuestDays();

        return $days === 0 || $guest->created_at === null || $guest->created_at->copy()->addDays($days)->isFuture();
    }

    /** Whether the guest has a code that can still be entered. */
    public static function hasActiveCode(AlbumGuest $guest): bool
    {
        return $guest->code !== null
            && $guest->code_expires_at?->isFuture()
            && $guest->code_attempts < self::MAX_ATTEMPTS;
    }

    /** Creates a new code and e-mails it right away. False when the mail could not be sent. */
    public static function sendCode(AlbumGuest $guest): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $guest->forceFill([
            'code' => Hash::make($code),
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'code_attempts' => 0,
        ])->save();

        return Mailing::send(fn () => Notification::route('mail', $guest->email)
            ->notifyNow(new AlbumGuestCode($guest->album, $code)));
    }

    /** Checks $code; the right one is used up, a wrong one counts as a try. */
    public static function verify(AlbumGuest $guest, string $code): bool
    {
        if (! self::hasActiveCode($guest)) {
            return false;
        }

        if (! Hash::check(trim($code), $guest->code)) {
            $guest->increment('code_attempts');

            return false;
        }

        $guest->forceFill(['code' => null, 'code_expires_at' => null, 'code_attempts' => 0, 'last_used_at' => now()])->save();

        return true;
    }

    /** The guest's address with most of the name hidden, for the code page. */
    public static function maskedEmail(AlbumGuest $guest): string
    {
        [$name, $domain] = explode('@', $guest->email, 2);

        return mb_substr($name, 0, 1).str_repeat('•', max(2, mb_strlen($name) - 1)).'@'.$domain;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
