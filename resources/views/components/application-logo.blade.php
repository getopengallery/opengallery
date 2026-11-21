{{--
    Site logo. Uses the logo uploaded in Admin → Settings (with an optional
    dark-mode version); otherwise the built-in OpenGallery mark (x-logo-mark).
--}}
@php
    $logo = \App\Support\SiteSettings::assetUrl('logo');
    $logoDark = \App\Support\SiteSettings::assetUrl('logo-dark');
    $showName = ! $logo || \App\Support\SiteSettings::showName();
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 text-ink']) }}>
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $showName ? '' : config('app.name') }}" @class(['h-7 w-auto max-w-[12rem] object-contain', 'dark:hidden' => $logoDark])>
        @if ($logoDark)
            <img src="{{ $logoDark }}" alt="{{ $showName ? '' : config('app.name') }}" class="hidden h-7 w-auto max-w-[12rem] object-contain dark:block">
        @endif
    @else
        <x-logo-mark class="h-7 w-7 shrink-0" />
    @endif

    @if ($showName)
        <span class="text-[1.0625rem] font-semibold tracking-[-0.01em]">{{ config('app.name') }}</span>
    @endif
</span>
