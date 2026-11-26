{{-- Block: favorites-tile. The "Favorites" entry among the albums on "My albums", styled like album-tile. Props: favorites (paginator of the person's favourite photos). --}}
@php($cover = $favorites->first()?->getFirstMedia(\App\Models\Photo::COLLECTION))

<a href="{{ route('favorites.index') }}" class="group block rounded-og">
    <div class="aspect-[3/2] overflow-hidden rounded-og bg-surface">
        @if ($cover)
            <img src="{{ route('media.show', [$cover, 'thumb']) }}" alt="" loading="lazy" class="h-full w-full object-cover">
        @endif
    </div>
    <h3 class="mt-3 flex items-center gap-1.5 font-semibold leading-snug text-ink">
        <svg viewBox="0 0 20 20" class="h-4 w-4 shrink-0 text-primary" fill="currentColor" aria-hidden="true"><path d="M10 17.5s-6.5-3.9-6.5-8.6A3.6 3.6 0 0 1 10 6.6a3.6 3.6 0 0 1 6.5 2.3c0 4.7-6.5 8.6-6.5 8.6Z"/></svg>
        <span class="decoration-1 underline-offset-4 group-hover:underline">{{ __('Favorites') }}</span>
    </h3>
    <p class="mt-0.5 text-caption text-muted">{{ trans_choice('{0} Empty|{1} :count photo|[2,*] :count photos', $favorites->total()) }}</p>
</a>
