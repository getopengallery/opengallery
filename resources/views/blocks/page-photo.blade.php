{{--
    Block: page-photo. A photo placed in page content (rich editor "Photo").
    Props: src, srcset (thumb and preview, may be null), alt, caption, wide, ratio. Clicking opens the lightbox.
--}}
@php($image = \App\Support\ImageLoading::next())

<figure @class(['og-figure', 'og-wide' => $wide]) x-data="ogLightbox(@js([['src' => $src, 'title' => $caption ?: $alt]]))">
    <a href="{{ $src }}" @click.prevent="open(0, $event)" class="block rounded-og">
        <img src="{{ $src }}" @if ($srcset ?? null) srcset="{{ $srcset }}" sizes="{{ $wide ? '(min-width: 1216px) 1216px, 100vw' : '(min-width: 720px) 672px, 100vw' }}" @endif alt="{{ $alt }}" loading="{{ $image['loading'] }}" fetchpriority="{{ $image['fetchpriority'] }}" decoding="{{ $image['decoding'] }}" class="w-full rounded-og bg-surface" @if ($ratio) style="aspect-ratio: {{ $ratio }}" @endif>
    </a>
    @if ($caption)
        <figcaption>{{ $caption }}</figcaption>
    @endif

    <x-block name="lightbox" />
</figure>
