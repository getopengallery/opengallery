<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Mailing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request. The answer is the same
     * whether or not the address has an account, and the e-mail goes out after
     * the response, so its sending time does not give that away either.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $credentials = $request->only('email');

        defer(fn () => Mailing::send(fn () => Password::sendResetLink($credentials)));

        return back()->with('status', __('If an account exists for this address, we have sent a link to choose a new password.'));
    }
}
