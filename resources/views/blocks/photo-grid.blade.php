{{--
    Block: photo-grid (default variant "justified").
    Photos keep their real aspect ratio and are laid out in rows of equal
    height that fill the width, like a contact sheet. Props: photos.
    Core fallback; themes add variants as views/blocks/photo-grid/{variant}
    (the default theme ships "square").
--}}
@php($items = \App\Support\PhotoGridItems::from($photos))

<div x-data="ogLightbox(@js($items->pluck('lightbox')))">
    <ul role="list" class="og-justified">
        @foreach ($items as $i => $item)
            <li style="--r: {{ $item['ratio'] }}">
                <a href="{{ $item['lightbox']['src'] }}" @click.prevent="open({{ $i }}, $event)" class="block h-full rounded-[2px]">
                    <img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="(min-width: 1024px) {{ (int) ceil($item['ratio'] * 300) }}px, {{ (int) ceil($item['ratio'] * 180) }}px" @endif alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}" class="h-full w-full rounded-[2px] bg-surface object-cover">
                </a>
            </li>
        @endforeach
    </ul>

    <x-block name="lightbox" />
</div>
