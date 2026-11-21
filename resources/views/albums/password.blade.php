<x-guest-layout :meta="$meta ?? null">
    <x-slot:title>{{ $album->title }}</x-slot:title>

    <div class="mb-6 inline-flex h-11 w-11 items-center justify-center rounded-full bg-surface text-ink" aria-hidden="true">
        <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="12" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
    </div>

    <h1 class="text-heading font-semibold">{{ $album->title }}</h1>
    <p class="mt-2 text-muted">{{ __('This album is protected. Enter its password to see the photos.') }}</p>

    <form method="POST" action="{{ route('albums.unlock', $album) }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1.5 w-full" type="password" name="password" required autofocus autocomplete="off" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <x-primary-button class="w-full py-2.5">{{ __('Open album') }}</x-primary-button>
    </form>

    @guest
        <p class="mt-8 text-sm text-muted">
            {{ __('Have an account with access?') }}
            <a href="{{ route('login') }}" class="og-link text-ink">{{ __('Log in') }}</a>
        </p>
    @endguest
</x-guest-layout>
