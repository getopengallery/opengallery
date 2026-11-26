<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('shows the form to ask for a reset link', function () {
    $this->get('/forgot-password')->assertOk();
});

it('sends the link once to a known address and answers the same for an unknown one', function () {
    Notification::fake();
    $user = User::factory()->create();
    $expected = 'If an account exists for this address, we have sent a link to choose a new password.';

    foreach ([$user->email, $user->email, 'nobody@example.com'] as $email) {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', $expected)
            ->assertSessionHasNoErrors();
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

describe('links in e-mails', function () {
    it('builds the reset link from the site address when the Host header is forged', function () {
        config(['app.url' => 'https://gallery.example']);
        $user = User::factory()->create();

        $this->post('http://evil.test/forgot-password', ['email' => $user->email]);

        $mail = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
        expect($mail)->toContain('https://gallery.example/reset-password/')->not->toContain('evil.test');
    });

    it('keeps the address visitors came through when it belongs to the site', function (string $host) {
        config(['app.url' => 'https://gallery.example', 'gallery.trusted_hosts' => 'photos.example']);

        $this->get("https://{$host}/login")->assertSee("https://{$host}/login", false);
    })->with(['the configured address' => 'gallery.example', 'its www twin' => 'www.gallery.example', 'a trusted host' => 'photos.example']);
});
