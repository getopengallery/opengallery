{{--
    Block: landing, layout "Text only". Shown to guests at "/" while public
    albums are off. Content and layout are set in the theme settings.
    Optional prop onHome: rendered above the album list (the Default theme's
    "landing_on_home" setting); its buttons then lead to the albums.
    Core fallback; themes add layouts as views/blocks/landing/{variant}
    (the default theme ships "split" and "cover").
--}}
<section class="og-container flex min-h-[calc(100vh-14rem)] items-center py-16">
    <div class="max-w-xl">
        @include('blocks.partials.landing-copy')
    </div>
</section>
