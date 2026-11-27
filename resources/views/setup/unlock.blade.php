<x-setup.layout :step="$step" :reached="$reached" :title="__('Continue the setup')">
    <p class="mt-3 text-muted">
        {{ __('An administrator account already exists. To continue the setup, log in with it.') }}
    </p>

    <form method="POST" action="{{ route('setup.unlock') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label for="email" :value="__('E-mail')" />
            <x-text-input id="email" name="email" type="email" class="mt-1.5 w-full" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" name="password" type="password" class="mt-1.5 w-full" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-primary-button>{{ __('Continue') }}</x-primary-button>
    </form>
</x-setup.layout>
