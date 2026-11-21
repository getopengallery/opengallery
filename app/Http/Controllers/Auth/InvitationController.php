<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Invitations;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * Accepting an invitation (App\Support\Invitations): the person chooses a
 * password and is logged in. The link came by e-mail (or from an admin who
 * passed it on), so the address counts as confirmed.
 */
class InvitationController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request, 'invitation' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $accepted = null;
        $status = Password::broker(Invitations::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$accepted) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
                $accepted = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET || ! $accepted) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('This invitation link is no longer valid. Ask for a new one.')]);
        }

        Auth::login($accepted);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
