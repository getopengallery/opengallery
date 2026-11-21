{{-- "Forbidden" page in the site's look. Themes can replace it with views/errors/403.blade.php. --}}
<x-guest-layout>
    <x-slot:title>{{ __('No access') }}</x-slot:title>

    <h1 class="text-heading font-semibold">{{ __('You don\'t have access to this page') }}</h1>
    <p class="mt-2 text-muted">
        {{ __('Your account does not allow this. If you think it should, ask the people who run this gallery.') }}
    </p>

    <a href="{{ route('home') }}" class="og-btn-secondary mt-8">{{ __('Go to the gallery') }}</a>
</x-guest-layout>
