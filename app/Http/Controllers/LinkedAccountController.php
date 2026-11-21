<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Removes a sign-in method (linked identity provider account) from the
 * current user. Linking itself is done by the plugin that offers the provider.
 */
class LinkedAccountController extends Controller
{
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        // Deleted one by one so the removal reaches the activity log.
        $request->user()->socialAccounts()->where('provider', $provider)->get()->each->delete();

        return redirect()->route('profile.edit')->with('status', 'account-unlinked');
    }
}
