<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Password;

/**
 * Invitation links: a token of the "invitations" password broker (its own
 * table, valid for a week) that lets the person choose a password at
 * /invitation/{token} and logs them in (InvitationController).
 */
class Invitations
{
    public const BROKER = 'invitations';

    /** A new link for $user; earlier links stop working. */
    public static function link(User $user): string
    {
        $token = Password::broker(self::BROKER)->createToken($user);

        return route('invitation.show', ['token' => $token, 'email' => $user->email]);
    }

    /**
     * E-mails a new link to $user. Returns the link and whether it was sent,
     * so the admin can pass it on when e-mail does not work.
     *
     * @return array{url: string, sent: bool}
     */
    public static function send(User $user, ?User $invitedBy = null): array
    {
        $url = self::link($user);
        $sent = Mailing::delivers()
            && Mailing::send(fn () => $user->notify(new UserInvited($url, $invitedBy?->name)));

        return ['url' => $url, 'sent' => $sent];
    }

    public static function validForDays(): int
    {
        return max(1, intdiv((int) config('auth.passwords.'.self::BROKER.'.expire'), 60 * 24));
    }
}
