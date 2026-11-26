{{-- Block: landing, layout "Full-screen image": text on the photo, darkened for legibility. Alone on the page it runs into the footer; above the album list ($onHome) it does not. --}}
@php($image = \App\Support\Theme::image('landing_image'))

<section @class(['relative isolate flex min-h-[calc(100dvh-4rem)] items-end overflow-hidden bg-black text-white', '-mb-24' => ! ($onHome ?? false)])>
    @if ($image)
        <img src="{{ $image }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover">
    @endif
    <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/75 via-black/30 to-black/10" aria-hidden="true"></div>

    <div class="og-container pb-20 pt-32">
        <div class="max-w-xl">
            @include('blocks.partials.landing-copy', ['inverse' => true])
        </div>
    </div>
</section>
