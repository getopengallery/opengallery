<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the current user out of their other browsers (profile, "Where you're
 * signed in"). The list itself is shown by ProfileController::edit().
 */
class SessionController extends Controller
{
    /** Sign out one other browser. The current one signs out with "Log out" instead. */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        abort_if($session === $request->session()->getId(), 404);
        abort_unless(UserSessions::signOut($request->user(), $session), 404);

        return redirect()->route('profile.edit')->with('status', 'session-signed-out');
    }

    /**
     * Sign out every other browser, remembered ones too: the "remember me"
     * token is replaced, and this browser gets a new cookie if it had one.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('signOutOthers', [
            'current_password' => ['required', 'current_password'],
        ]);

        UserSessions::signOutAll($request->user(), $request->session()->getId());

        // Gives this browser a new "remember me" cookie (when it had one) for the new token. The
        // password is only rehashed, not changed, so that save stays out of the activity log.
        User::withoutEvents(fn () => Auth::guard('web')->logoutOtherDevices($validated['current_password']));

        return redirect()->route('profile.edit')->with('status', 'sessions-signed-out');
    }
}
