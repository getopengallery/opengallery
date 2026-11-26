<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/** Registers $email, then tries to log in (which e-mail confirmation blocks); returns the new user. */
function signUp(string $email = 'grace@example.com'): User
{
    test()->post('/register', [
        'name' => 'Grace',
        'email' => $email,
        'password' => 'a-long-password',
        'password_confirmation' => 'a-long-password',
    ]);

    // While e-mail works, signing up does not log in, so the form reveals no existing accounts.
    test()->post('/login', ['email' => $email, 'password' => 'a-long-password']);

    return User::where('email', $email)->sole();
}

/** Opens the signed confirmation link of $user. */
function confirmEmail(User $user): void
{
    test()->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));
}

/** Forgets who is logged in between requests, as a new request from that browser would. */
function nextRequest(): void
{
    Auth::forgetGuards();
    app()->forgetScopedInstances();
}
