<?php

namespace App\Http\Controllers\Auth;

use App\Extensions\LoginOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    /**
     * Show the login page, or go straight to the default sign-in method a
     * plugin provides. "?local=1" always shows the password form, so staff
     * can still sign in when the identity provider is unavailable.
     */
    public function create(Request $request, LoginOptions $options): View|RedirectResponse
    {
        $default = $options->default();

        if ($default && ! $request->boolean('local') && ! $request->session()->has('errors')) {
            return redirect()->to($default->url);
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
