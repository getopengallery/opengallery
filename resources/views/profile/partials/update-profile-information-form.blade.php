<section>
    <header>
        <h2 class="text-title font-semibold text-ink">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-muted">
            {{ __("Update your account's profile information, email address and language.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div x-data="{ email: @js(old('email', $user->email)), saved: @js($user->email) }">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" x-model="email" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            <div class="mt-4" x-show="email !== saved" x-cloak>
                <x-input-label for="profile_current_password" :value="__('Current Password')" />
                <x-text-input id="profile_current_password" name="current_password" type="password" class="mt-1 block w-full" x-bind:required="email !== saved" x-bind:disabled="email === saved" autocomplete="current-password" />
                <p class="mt-1 text-sm text-muted">{{ __('Needed to change your e-mail address. If you only sign in through a provider, first set a password with "Forgot your password?" on the login page.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('current_password')" />
            </div>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-ink">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="og-link rounded-og text-sm text-muted hover:text-ink">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-muted">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="locale" :value="__('Language')" />
            <select id="locale" name="locale" class="og-input mt-1 block w-full">
                <option value="">{{ __('Site default (:language)', ['language' => \App\Support\Locales::name(\App\Support\Locales::default())]) }}</option>
                @foreach (\App\Support\Locales::available() as $code => $name)
                    <option value="{{ $code }}" lang="{{ str_replace('_', '-', $code) }}" @selected(old('locale', $user->locale) === $code)>{{ $name }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('locale')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-muted"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
