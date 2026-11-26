{{-- Block: landing, layout "Text and image": text left, photo right (stacked on phones). --}}
@php($image = \App\Support\Theme::image('landing_image'))

<section class="og-container grid min-h-[calc(100dvh-14rem)] items-center gap-10 py-12 lg:grid-cols-2 lg:gap-16">
    <div class="max-w-xl">
        @include('blocks.partials.landing-copy')
    </div>

    <div class="aspect-[4/3] overflow-hidden rounded-og bg-surface lg:aspect-[4/5]">
        @if ($image)
            <img src="{{ $image }}" alt="" class="h-full w-full object-cover">
        @endif
    </div>
</section>
