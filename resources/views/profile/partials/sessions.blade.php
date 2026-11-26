{{-- The browsers where this account is signed in (App\Support\UserSessions). --}}
<section>
    <header>
        <h2 class="text-title font-semibold text-ink">{{ __('Where you\'re signed in') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('If you do not recognise a device, sign it out and choose a new password.') }}</p>
    </header>

    @if (session('status') === 'session-signed-out')
        <p class="mt-4 text-sm text-muted" role="status">{{ __('Device signed out.') }}</p>
    @elseif (session('status') === 'sessions-signed-out')
        <p class="mt-4 text-sm text-muted" role="status">{{ __('All other devices signed out.') }}</p>
    @endif

    <ul class="mt-6 divide-y divide-line">
        @foreach ($sessions as $session)
            <li class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0">
                    <p class="font-medium text-ink">{{ $session->deviceLabel() }}</p>
                    <p class="text-sm text-muted">
                        @if ($session->isCurrent())
                            {{ __('This device') }}
                        @else
                            {{ __('Last active :time', ['time' => $session->lastActiveAt()->diffForHumans()]) }}
                        @endif
                    </p>
                </div>

                @unless ($session->isCurrent())
                    <form method="POST" action="{{ route('profile.sessions.destroy', $session->id) }}">
                        @csrf
                        @method('DELETE')
                        <x-secondary-button type="submit">{{ __('Sign out') }}</x-secondary-button>
                    </form>
                @endunless
            </li>
        @endforeach
    </ul>

    <x-secondary-button
        class="mt-4"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-sign-out-others')"
    >{{ __('Sign out all other devices') }}</x-secondary-button>

    <x-modal name="confirm-sign-out-others" :show="$errors->signOutOthers->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.sessions.destroy-others') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-title font-semibold text-ink">{{ __('Sign out all other devices?') }}</h2>

            <p class="mt-1 text-sm text-muted">
                {{ __('Every other browser is signed out, including ones that remember you. Enter your password to confirm.') }}
            </p>

            <div class="mt-6">
                <x-input-label for="sign_out_others_password" value="{{ __('Password') }}" class="sr-only" />
                <x-text-input id="sign_out_others_password" name="current_password" type="password" class="mt-1 block w-3/4" placeholder="{{ __('Password') }}" autocomplete="current-password" />
                <x-input-error :messages="$errors->signOutOthers->get('current_password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button>{{ __('Sign out other devices') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</section>
