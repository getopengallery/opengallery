{{-- Block: album-tile (Scrapbook theme). A taped-on polaroid with the title written underneath and the photo count on a sticker. Props: album. --}}
@php
    $cover = $album->coverPhoto?->getFirstMedia(\App\Models\Photo::COLLECTION);
    $count = $album->photos_count ?? 0;
    $locked = $album->hasPassword() && ! $album->hasDirectAccess(auth()->user());
    $image = $cover ? \App\Support\ImageLoading::next() : null;
    $srcset = $cover ? \App\Support\Srcset::for($album->coverPhoto, [
        'small' => $cover->hasGeneratedConversion('small') ? route('media.show', [$cover, 'small']) : null,
        'thumb' => route('media.show', [$cover, 'thumb']),
    ]) : null;
@endphp

<a href="{{ route('albums.show', $album) }}" class="sb-polaroid">
    <span class="sb-tape" aria-hidden="true"></span>

    <span class="sb-polaroid-photo">
        @if ($cover)
            <img src="{{ route('media.show', [$cover, 'thumb']) }}" @if ($srcset) srcset="{{ $srcset }}" sizes="(min-width: 640px) 11rem, 10rem" @endif alt="" loading="{{ $image['loading'] }}" fetchpriority="{{ $image['fetchpriority'] }}" decoding="{{ $image['decoding'] }}">
        @else
            <svg viewBox="0 0 24 24" class="sb-polaroid-empty" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        @endif
    </span>

    <h3 class="sb-polaroid-caption">
        <span>{{ $album->title }}</span>
        @if ($locked)
            <svg viewBox="0 0 20 20" class="sb-lock" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="{{ __('Needs a password') }}"><rect x="4" y="9" width="12" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
        @endif
    </h3>

    @if ($collaboratorRole = $album->collaboratorRole(auth()->user()))
        <span class="block text-center text-caption text-muted">{{ __('Your role: :role', ['role' => $collaboratorRole->getLabel()]) }}</span>
    @endif

    @if ($count > 0)
        <span class="sb-sticker" aria-hidden="true">{{ $count }}</span>
    @endif
    <span class="sr-only">{{ trans_choice('{0} Empty|{1} :count photo|[2,*] :count photos', $count) }}</span>
</a>
