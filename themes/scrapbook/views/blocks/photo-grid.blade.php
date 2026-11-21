{{--
    Block: photo-grid (Scrapbook theme, layout "Pile"). Photos keep their shape
    and are taped into narrow, crowded columns. Props: photos. The "Polaroid
    wall" layout is photo-grid/polaroids.
--}}
@php
    $items = \App\Support\PhotoGridItems::from($photos);
    $wobble = \App\Support\Theme::enabled('wobble');
@endphp

<div x-data="ogLightbox(@js($items->pluck('lightbox')))">
    <ul role="list" @class(['sb-pile', 'sb-wobble' => $wobble])>
        @foreach ($items as $i => $item)
            <li>
                <a href="{{ $item['lightbox']['src'] }}" @click.prevent="open({{ $i }}, $event)" class="sb-print">
                    <span class="sb-tape" aria-hidden="true"></span>
                    <img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 640px) 13rem, 10rem" @endif alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}" style="aspect-ratio: {{ $item['ratio'] }}">
                </a>
            </li>
        @endforeach
    </ul>

    <x-block name="lightbox" />
</div>
