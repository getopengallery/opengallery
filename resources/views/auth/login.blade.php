<x-guest-layout>
    <x-slot:title>{{ __('Log in') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('Log in') }}</h1>
    @if (config('gallery.registration_enabled'))
        <p class="mt-2 text-muted">
            {{ __('New here?') }}
            <a href="{{ route('register') }}" class="og-link rounded-og text-ink">{{ __('Create an account') }}</a>
        </p>
    @endif

    <x-auth-session-status class="mt-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1.5 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-baseline justify-between">
                <x-input-label for="password" :value="__('Password')" />
                @if (Route::has('password.request'))
                    <a class="og-link rounded-og text-sm text-muted hover:text-ink" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
                @endif
            </div>
            <x-text-input id="password" class="mt-1.5 w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-muted">
            <input id="remember_me" type="checkbox" class="og-checkbox" name="remember">
            {{ __('Keep me logged in') }}
        </label>

        <x-primary-button class="w-full py-2.5">{{ __('Log in') }}</x-primary-button>
    </form>

    <x-login-options />
</x-guest-layout>
