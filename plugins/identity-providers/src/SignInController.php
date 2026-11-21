<?php

namespace Plugins\IdentityProviders;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignInController
{
    /** Pending sign-ins expire after this many seconds. */
    private const STATE_TTL = 600;

    public function __construct(private ProviderRepository $providers) {}

    /** Starts sign-in (guests) or linking (logged-in users, via the "link" route). */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $idp = $this->providers->find($provider) ?? abort(404);
        $linking = $request->routeIs('idp.link');

        if ($request->user() && ! $linking) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put("idp.pending.{$state}", [
            'provider' => $idp->key(),
            'verifier' => $verifier,
            'link_user' => $linking ? $request->user()->id : null,
            'expires' => now()->addSeconds(self::STATE_TTL)->timestamp,
        ]);

        try {
            return redirect()->away((new OAuthClient($idp))->authorizationUrl($state, $verifier));
        } catch (SignInException $e) {
            report($e);

            return $this->fail($linking, $e->getMessage());
        }
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $pending = $request->session()->pull('idp.pending.'.$request->string('state'));
        $linking = filled($pending['link_user'] ?? null);

        if (! $pending || $pending['provider'] !== $provider || $pending['expires'] < now()->timestamp) {
            return $this->fail($linking, __('identity-providers::messages.sign_in_expired_or_was'));
        }

        $idp = $this->providers->find($provider) ?? abort(404);

        if ($request->filled('error')) {
            return $this->fail($linking, $request->input('error') === 'access_denied'
                ? __('identity-providers::messages.sign_in_with_provider_was', ['provider' => $idp->name()])
                : __('identity-providers::messages.provider_reported_error_error', ['provider' => $idp->name(), 'error' => Str::limit((string) $request->input('error_description', $request->input('error')), 160)]));
        }

        if ($linking && $request->user()?->id !== $pending['link_user']) {
            return $this->fail(false, __('identity-providers::messages.log_in_again_link_provider', ['provider' => $idp->name()]));
        }

        try {
            $claims = (new OAuthClient($idp))->claims((string) $request->input('code'), $pending['verifier']);
            $user = (new AccountResolver($idp))->resolve($claims, $linking ? $request->user() : null);
        } catch (SignInException $e) {
            if ($e->getPrevious()) {
                report($e);
            }

            return $this->fail($linking, $e->getMessage());
        }

        if ($linking) {
            return redirect()->route('profile.edit')->with('status', 'account-linked');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** The provider's uploaded icon, served from private storage. */
    public function icon(string $provider): StreamedResponse
    {
        $path = $this->providers->all()->get($provider)?->iconPath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $response = Storage::disk('local')->response($path);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    /** Back to the password form (never the default provider, to avoid a loop). */
    private function fail(bool $linking, string $message): RedirectResponse
    {
        return redirect()
            ->route($linking ? 'profile.edit' : 'login', $linking ? [] : ['local' => 1])
            ->withErrors(['sign_in' => $message]);
    }
}
