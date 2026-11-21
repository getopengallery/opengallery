{{-- Block: photo-grid, variant "polaroids" (Scrapbook theme). Square prints with the photo title written underneath. Chosen with the "Photo layout" setting. Props: photos. --}}
@php
    $items = \App\Support\PhotoGridItems::from($photos);
    $wobble = \App\Support\Theme::enabled('wobble');
@endphp

<div x-data="ogLightbox(@js($items->pluck('lightbox')))">
    <ul role="list" @class(['sb-board', 'sb-wobble' => $wobble])>
        @foreach ($items as $i => $item)
            <li>
                <a href="{{ $item['lightbox']['src'] }}" @click.prevent="open({{ $i }}, $event)" class="sb-polaroid">
                    <span class="sb-tape" aria-hidden="true"></span>
                    <span class="sb-polaroid-photo">
                        <img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 640px) 11rem, 10rem" @endif alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}">
                    </span>
                    <span class="sb-polaroid-caption" aria-hidden="true">{{ $item['lightbox']['title'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <x-block name="lightbox" />
</div>
