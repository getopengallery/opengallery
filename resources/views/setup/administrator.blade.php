<x-setup.layout :step="$step" :reached="$reached" :title="__('Administrator')">
    @if ($existing)
        <p class="mt-3 text-muted">{{ __('Your administrator account is ready:') }}</p>
        <p class="mt-4 rounded-og bg-surface px-4 py-3">
            <span class="font-medium">{{ $existing->name }}</span>
            <span class="block text-sm text-muted">{{ $existing->email }}</span>
        </p>
        <p class="mt-4 text-sm text-muted">{{ __('More people can be added later in the admin area under Users.') }}</p>
        <a href="{{ route('setup.site') }}" class="og-btn-primary mt-8">{{ __('Continue') }}</a>
    @else
        <p class="mt-3 text-muted">
            {{ __('Create the first account. It becomes the super administrator, who can do everything in the gallery. Its e-mail address counts as confirmed.') }}
        </p>

        <form method="POST" action="{{ route('setup.administrator') }}" class="mt-8 space-y-5">
            @csrf

            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input id="name" name="name" class="mt-1.5 w-full" :value="old('name')" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" name="email" type="email" class="mt-1.5 w-full" :value="old('email')" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" name="password" type="password" class="mt-1.5 w-full" required autocomplete="new-password" />
                <p class="mt-1.5 text-caption text-muted">{{ __('At least 12 characters.') }}</p>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1.5 w-full" required autocomplete="new-password" />
            </div>

            <x-primary-button>{{ __('Create administrator') }}</x-primary-button>
        </form>
    @endif
</x-setup.layout>
