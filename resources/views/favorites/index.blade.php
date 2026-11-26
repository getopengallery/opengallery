<x-app-layout :meta="\App\Support\PageMeta::hidden()">
    <x-slot:title>{{ __('Favorites') }}</x-slot:title>

    <x-slot name="header">
        <div class="space-y-2">
            <p class="text-caption text-muted"><a href="{{ route('dashboard') }}" class="hover:text-ink hover:underline">{{ __('My albums') }}</a></p>
            <h1 class="text-heading font-semibold">{{ __('Favorites') }}</h1>
            <p class="text-sm text-muted">{{ __('Only you can see this album. Photos leave it when you remove the heart, or when you can no longer see them.') }}</p>
        </div>
    </x-slot>

    <div class="og-container space-y-14">
        @if ($photos->isEmpty())
            <x-block name="empty-state" :message="__('Photos you mark with the heart in the photo viewer appear here.')" />
        @else
            <section aria-label="{{ __('Photos') }}">
                <x-block name="photo-grid" :photos="$photos" />
                <div class="mt-14">{{ $photos->links() }}</div>
            </section>
        @endif
    </div>
</x-app-layout>
