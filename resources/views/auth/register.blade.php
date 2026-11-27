<x-guest-layout>
    <x-slot:title>{{ __('Create account') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('Create account') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('Already have one?') }}
        <a href="{{ route('login') }}" class="og-link rounded-og text-ink">{{ __('Log in') }}</a>
    </p>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="mt-1.5 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email" :value="__('E-mail')" />
            <x-text-input id="email" class="mt-1.5 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1.5 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Repeat password')" />
            <x-text-input id="password_confirmation" class="mt-1.5 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button class="w-full py-2.5">{{ __('Create account') }}</x-primary-button>
    </form>

    <x-login-options />
</x-guest-layout>
