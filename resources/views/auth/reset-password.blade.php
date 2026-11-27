{{-- Also used to accept an invitation ($invitation): same fields, its own heading and target. --}}
<x-guest-layout>
    @if ($invitation ?? false)
        <x-slot:title>{{ __('Choose your password') }}</x-slot:title>
        <h1 class="text-heading font-semibold">{{ __('Choose your password') }}</h1>
        <p class="mb-6 mt-2 text-muted">{{ __('Welcome to :site. Choose a password for your account; you are logged in right after.', ['site' => config('app.name')]) }}</p>
    @endif

    <form method="POST" action="{{ ($invitation ?? false) ? route('invitation.store') : route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('E-mail')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ ($invitation ?? false) ? __('Save and log in') : __('Reset password') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
