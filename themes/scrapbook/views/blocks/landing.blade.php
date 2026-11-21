{{--
    Block: landing (Scrapbook theme). Shown to guests at "/" while public
    albums are off: the core landing copy next to a collage of sticky notes
    and, if one is set, the landing image taped in as a polaroid.
--}}
@php($image = \App\Support\Theme::image('landing_image'))

<section class="og-container sb-landing sb-doodled">
    @include('blocks.partials.scrapbook-doodles')

    <div class="sb-landing-copy">
        @include('blocks.partials.landing-copy')
    </div>

    <div class="sb-collage" aria-hidden="true">
        @if ($image)
            <figure class="sb-polaroid sb-collage-photo">
                <span class="sb-tape"></span>
                <span class="sb-polaroid-photo"><img src="{{ $image }}" alt=""></span>
                <figcaption class="sb-polaroid-caption">{{ __('theme-scrapbook::site.say_cheese') }}</figcaption>
            </figure>
        @else
            <span class="sb-polaroid sb-collage-blank sb-collage-blank--1"><span class="sb-polaroid-photo"></span></span>
            <span class="sb-polaroid sb-collage-blank sb-collage-blank--2"><span class="sb-tape"></span><span class="sb-polaroid-photo"></span></span>
        @endif

        <span class="sb-note sb-note--1">{{ __('theme-scrapbook::site.hello') }}</span>
        <span class="sb-note sb-note--2">{{ __('theme-scrapbook::site.wow') }}</span>
        <span class="sb-note sb-note--3">★</span>
    </div>
</section>
