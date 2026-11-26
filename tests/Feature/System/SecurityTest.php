<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

describe('sessions', function () {
    it('signs out other sessions when the password changes', function () {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password-123']);
        $this->get('/profile')->assertOk();

        $user->forceFill(['password' => Hash::make('new-password-456')])->save();
        nextRequest();

        $this->get('/profile')->assertRedirect(route('login'));
    });

    it('keeps this device signed in and replaces the remember token after a password change', function () {
        $user = User::factory()->create(['remember_token' => 'stolen-remember-token']);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertSessionHasNoErrors();

        expect($user->fresh()->remember_token)->not->toBe('stolen-remember-token');
        $this->get('/profile')->assertOk();
    });

    it('stores database sessions with a short browser label instead of IP address and user agent', function () {
        useDatabaseSessions();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0')
            ->get(route('profile.edit'))
            ->assertOk();

        $session = DB::table('sessions')->sole();

        expect($session->user_id)->toBe($user->id)
            ->and($session->ip_address)->toBeNull()
            ->and($session->user_agent)->toBe('Firefox on macOS');
    });
});

describe('rate limits', function () {
    it('limits forms that guests can repeat', function (string $url, Closure $input, int $allowed) {
        config(['gallery.registration_enabled' => true]);
        Notification::fake();

        foreach (range(1, $allowed) as $attempt) {
            expect($this->post($url, $input($attempt))->status())->not->toBe(429);
            Auth::logout();
        }

        $this->post($url, $input($allowed + 1))->assertTooManyRequests();
    })->with([
        'login across accounts' => ['/login', fn (int $i) => ['email' => "u{$i}@example.com", 'password' => 'Summer2026!'], 30],
        'forgotten password' => ['/forgot-password', fn (int $i) => ['email' => "u{$i}@example.com"], 5],
        'reset form' => ['/reset-password', fn (int $i) => ['token' => "t{$i}", 'email' => 'u@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'], 10],
        'registration' => ['/register', fn (int $i) => ['name' => 'Bot', 'email' => "bot{$i}@example.com", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'], 5],
    ]);

    it('counts password guesses per account across forms', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (range(1, 3) as $attempt) {
            $this->post('/confirm-password', ['password' => "wrong-{$attempt}"]);
            $this->put('/password', ['current_password' => "wrong-{$attempt}", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
        }

        $this->delete('/profile', ['password' => 'wrong'])->assertTooManyRequests();
        expect(User::find($user->id))->not->toBeNull();
    });
});

it('protects pages in the browser, pinning HTTPS only over HTTPS and only while it is turned on', function () {
    $this->get('/login')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
        ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

    config(['gallery.hsts_max_age' => 0]);

    $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});
