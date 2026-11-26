{{--
    Heading, text and buttons of the landing page; shared by its layouts.
    $inverse: on a photo. $onHome: shown above the album list (the buttons
    then lead down to the albums instead of to the login).
--}}
@php
    $inverse ??= false;
    $onHome ??= false;
    $heading = \App\Support\Theme::option('landing_heading', config('app.name'));
    $text = \App\Support\Theme::option('landing_text', $onHome ? null : __('The albums here are private. Log in to see the ones shared with you.'));
    $register = config('gallery.registration_enabled') && auth()->guest() && \App\Support\Theme::enabled('landing_show_register');
    $primary = $onHome ? ['href' => '#albums', 'label' => __('View albums')] : ['href' => route('login'), 'label' => __('Log in')];
@endphp

<h1 class="text-[2.5rem] font-semibold leading-[1.1] tracking-[-0.025em] sm:text-[3.25rem]">{{ $heading }}</h1>

@if ($text)
    <div @class(['mt-5 max-w-[34rem] whitespace-pre-line text-lg leading-relaxed', 'text-muted' => ! $inverse, 'text-white/85' => $inverse])>{{ $text }}</div>
@endif

<div class="mt-9 flex flex-wrap gap-3">
    @if ($inverse)
        <a href="{{ $primary['href'] }}" class="og-btn bg-white px-5 py-2.5 text-black hover:bg-white/90">{{ $primary['label'] }}</a>
        @if ($register)
            <a href="{{ route('register') }}" class="og-btn bg-white/15 px-5 py-2.5 text-white ring-1 ring-white/40 backdrop-blur hover:bg-white/25">{{ __('Create account') }}</a>
        @endif
    @else
        <a href="{{ $primary['href'] }}" class="og-btn-primary px-5 py-2.5">{{ $primary['label'] }}</a>
        @if ($register)
            <a href="{{ route('register') }}" class="og-btn-secondary px-5 py-2.5">{{ __('Create account') }}</a>
        @endif
    @endif
</div>
