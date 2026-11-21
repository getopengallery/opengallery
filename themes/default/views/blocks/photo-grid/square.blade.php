{{-- Block: photo-grid, variant "square". Uniform cropped squares. Chosen with the "Photo layout" setting. --}}
@php($items = \App\Support\PhotoGridItems::from($photos))

<div x-data="ogLightbox(@js($items->pluck('lightbox')))">
    <ul role="list" class="grid grid-cols-3 gap-1 sm:grid-cols-4 lg:grid-cols-6">
        @foreach ($items as $i => $item)
            <li>
                <a href="{{ $item['lightbox']['src'] }}" @click.prevent="open({{ $i }}, $event)" class="block">
                    <img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 1024px) 17vw, (min-width: 640px) 25vw, 34vw" @endif alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}" class="aspect-square w-full bg-surface object-cover">
                </a>
            </li>
        @endforeach
    </ul>

    <x-block name="lightbox" />
</div>
