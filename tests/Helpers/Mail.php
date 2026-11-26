<?php

use App\Enums\MailDelivery;
use App\Enums\Visibility;
use App\Models\Album;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AlbumShared;
use App\Support\MailQueue;
use App\Support\ShareNotices;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/** E-mail that really leaves the server (the test default "array" counts as not sending), with notifications faked. */
function mailWorks(): void
{
    config(['mail.default' => 'smtp']);
    Notification::fake();
}

/** E-mail that counts as delivered, kept in memory by the mailer. */
function deliverToMemory(): void
{
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'array']]);
    Mail::purge();
}

/** Admin → Settings → E-mail set to "Test mailbox", applied like at boot. */
function useTestMailbox(): void
{
    Setting::put(SiteSettings::MAIL_DELIVERY, MailDelivery::Mailbox->value);
    SiteSettings::apply();
    Mail::purge();
}

/** How many messages the in-memory mailer has received. */
function mailsSent(): int
{
    return app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()->count();
}

/** How many mails wait in the mail queue. */
function mailJobs(): int
{
    return DB::table('jobs')->where('queue', MailQueue::MAIL)->count();
}

/**
 * A new user named $name and a new restricted album "Camp".
 *
 * @return array{0: User, 1: Album}
 */
function restrictedAlbumBy(string $name = 'Ada'): array
{
    return [User::factory()->create(['name' => $name]), Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp'])];
}

/**
 * Shares $album with $people and sends the "new access" notices as $by.
 *
 * @param  list<User>  $people
 */
function shareWith(Album $album, array $people, User $by): void
{
    $album->sharedUsers()->attach(collect($people)->pluck('id')->all());
    ShareNotices::notifyNew($album, [], $by);
}

/** Shares a new restricted album "Camp" with $with and sends them the notification. */
function sharedCamp(User $with): void
{
    $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp']);
    $album->sharedUsers()->attach($with);
    $with->notify(new AlbumShared($album, 'Ada'));
}
