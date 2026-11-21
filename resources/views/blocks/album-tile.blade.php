{{-- Block: album-tile. Props: album. --}}
@php
    $cover = $album->coverPhoto?->getFirstMedia(\App\Models\Photo::COLLECTION);
    $image = $cover ? \App\Support\ImageLoading::next() : null;
    $srcset = $cover ? \App\Support\Srcset::for($album->coverPhoto, [
        'small' => $cover->hasGeneratedConversion('small') ? route('media.show', [$cover, 'small']) : null,
        'thumb' => route('media.show', [$cover, 'thumb']),
    ]) : null;
@endphp

<a href="{{ route('albums.show', $album) }}" class="group block rounded-og">
    <div class="aspect-[3/2] overflow-hidden rounded-og bg-surface">
        @if ($cover)
            <img src="{{ route('media.show', [$cover, 'thumb']) }}" @if ($srcset) srcset="{{ $srcset }}" sizes="(min-width: 1024px) 25vw, (min-width: 640px) 34vw, 50vw" @endif alt="" loading="{{ $image['loading'] }}" fetchpriority="{{ $image['fetchpriority'] }}" decoding="{{ $image['decoding'] }}" class="h-full w-full object-cover">
        @else
            <div class="flex h-full items-center justify-center text-line">
                <svg viewBox="0 0 24 18" class="w-10" aria-hidden="true"><rect x="5" y="4" width="14" height="10" rx="0.5" fill="currentColor"/></svg>
            </div>
        @endif
    </div>
    <h3 class="mt-3 flex items-center gap-1.5 font-semibold leading-snug text-ink">
        <span class="decoration-1 underline-offset-4 group-hover:underline">{{ $album->title }}</span>
        @if ($album->hasPassword() && ! $album->hasDirectAccess(auth()->user()))
            <svg viewBox="0 0 20 20" class="h-3.5 w-3.5 shrink-0 text-muted" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="{{ __('Needs a password') }}"><rect x="4" y="9" width="12" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
        @endif
    </h3>
    <p class="mt-0.5 text-caption text-muted">{{ trans_choice('{0} Empty|{1} :count photo|[2,*] :count photos', $album->photos_count ?? 0) }}</p>
    @if ($collaboratorRole = $album->collaboratorRole(auth()->user()))
        <p class="mt-0.5 text-caption text-muted">{{ __('Your role: :role', ['role' => $collaboratorRole->getLabel()]) }}</p>
    @endif
</a>
