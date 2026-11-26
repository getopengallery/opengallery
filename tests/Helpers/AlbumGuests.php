<?php

use App\Enums\Visibility;
use App\Models\Album;
use App\Support\AlbumGuests;
use Illuminate\Testing\TestResponse;

/**
 * A restricted album "Camp" shared by e-mail with $email, with e-mail delivered to memory.
 * The link is also the address the code form is posted to.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Album, 1: string}
 */
function guestAlbum(array $attributes = [], string $email = 'guest@example.com'): array
{
    deliverToMemory();
    $album = Album::factory()->visibility(Visibility::Restricted)->create(['title' => 'Camp', ...$attributes]);

    return [$album, AlbumGuests::invite($album, $email)];
}

/** The 6-digit code in the latest guest code e-mail the in-memory mailer received (null when there is none). */
function guestCode(): ?string
{
    $messages = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages();
    $subject = $messages->isEmpty() ? '' : $messages->last()->getOriginalMessage()->getSubject();

    return preg_match('/: (\d{6})$/', $subject, $match) ? $match[1] : null;
}

/** Opens the guest link, enters the code that was e-mailed and returns the code form's response. */
function enterGuestCode(string $link, ?string $code = null): TestResponse
{
    return test()->post($link, ['code' => $code ?? guestCode()]);
}
