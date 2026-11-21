<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Small helpers for e-mails the gallery sends on its own (invitations,
 * share notices, sign-up notices). Notices that can reach many people wait in
 * App\Support\MailQueue; the rest go out right away.
 */
class Mailing
{
    /** People told per database transaction by notifyEach(). */
    private const CHUNK = 200;

    /** Mailers that keep messages on the server instead of sending them. */
    private const NOT_DELIVERING = ['log', 'array'];

    /**
     * Whether e-mails are delivered with the current settings (Admin →
     * Settings → E-mail). The test mailbox counts as delivering, so every
     * feature can be tried without a mail server; only a log-file mailer
     * from .env (or the test "array" mailer) does not.
     */
    public static function delivers(): bool
    {
        return ! in_array(config('mail.default'), self::NOT_DELIVERING, true);
    }

    /** Whether e-mails are kept in the test mailbox instead of being sent. */
    public static function usesTestMailbox(): bool
    {
        return config('mail.default') === 'mailbox';
    }

    /**
     * Runs $send and reports instead of throwing when the mail server fails,
     * so a broken mail setup never stops a save or a sign-up.
     *
     * @param  Closure(): mixed  $send
     */
    public static function send(Closure $send): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Sends each of $userIds the notification $make builds for them. Written
     * in portions, each in one transaction, so telling a large group stays
     * fast. told: people told; sent: e-mails on their way (or in the test
     * mailbox); failed: people who could not be told.
     *
     * @param  array<int, int>  $userIds
     * @param  Closure(User): Notification  $make
     * @return array{told: int, sent: int, failed: int}
     */
    public static function notifyEach(array $userIds, Closure $make): array
    {
        $result = ['told' => 0, 'sent' => 0, 'failed' => 0];
        $mails = self::delivers();

        foreach (array_chunk(array_values($userIds), self::CHUNK) as $ids) {
            $users = User::query()->whereKey($ids)->get();

            $told = self::send(fn () => DB::transaction(function () use ($users, $make): void {
                foreach ($users as $user) {
                    $user->notify($make($user));
                }
            }));

            if ($told) {
                $result['told'] += $users->count();
                $result['sent'] += $mails ? $users->count() : 0;
            } else {
                $result['failed'] += $users->count();
            }
        }

        return $result;
    }
}
