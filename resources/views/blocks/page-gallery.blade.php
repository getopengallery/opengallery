{{--
    Block: page-gallery. Several photos in page content (rich editor "Gallery").
    Props: items (thumb, ratio, lightbox{src,title}), layout (grid|justified),
    columns (2-4, grid only), caption, wide. Each photo opens the lightbox.
--}}
<figure @class(['og-figure', 'og-wide' => $wide]) x-data="ogLightbox(@js($items->pluck('lightbox')))">
    <ul role="list" @class([
        'og-justified' => $layout === 'justified',
        'og-gallery-grid' => $layout === 'grid',
    ]) @if ($layout === 'grid') style="--cols: {{ $columns }}" @endif>
        @foreach ($items as $i => $item)
            <li @if ($layout === 'justified') style="--r: {{ $item['ratio'] }}" @endif>
                <a href="{{ $item['lightbox']['src'] }}" @click.prevent="open({{ $i }}, $event)" class="block h-full rounded-[2px]">
                    <img src="{{ $item['thumb'] }}" @if ($item['srcset']) srcset="{{ $item['srcset'] }}" sizes="{{ $layout === 'grid' ? '(min-width: 640px) '.(int) ceil(76 / $columns).'rem, 50vw' : '(min-width: 1024px) '.(int) ceil($item['ratio'] * 300).'px, '.(int) ceil($item['ratio'] * 180).'px' }}" @endif alt="{{ $item['lightbox']['title'] }}" loading="{{ $item['loading'] }}" fetchpriority="{{ $item['fetchpriority'] }}" decoding="{{ $item['decoding'] }}" class="h-full w-full rounded-[2px] bg-surface object-cover">
                </a>
            </li>
        @endforeach
    </ul>
    @if ($caption)
        <figcaption>{{ $caption }}</figcaption>
    @endif

    <x-block name="lightbox" />
</figure>
