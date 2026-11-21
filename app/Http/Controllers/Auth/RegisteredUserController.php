<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Mailing;
use App\Support\SecurityNotices;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request. While e-mail works, the form
     * answers the same for new and existing addresses: new accounts confirm
     * by e-mail and log in afterwards, the owner of an existing one is told.
     * Without e-mail nobody could confirm, so a taken address is reported.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $hidesExistingAccounts = Mailing::delivers();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', ...($hidesExistingAccounts ? [] : ['unique:'.User::class])],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $password = Hash::make($request->password);
        $existing = $hidesExistingAccounts ? User::where('email', $request->email)->first() : null;

        if ($existing) {
            SecurityNotices::signUpAttempted($existing);
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $password,
            ]);

            event(new Registered($user));
        }

        if ($hidesExistingAccounts) {
            return redirect()->route('login')->with('status', __('Almost done. If this address can be used for a new account, we have sent it an e-mail. Open the link in it and log in to finish.'));
        }

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
