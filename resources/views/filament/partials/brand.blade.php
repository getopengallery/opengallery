{{--
    Admin brand mark; mirrors components/application-logo (styles in
    theme-styles: og-brand). Rendered twice by Filament: $dark = true for the
    dark-mode variant.
--}}
@php
    $logo = ($dark ?? false ? \App\Support\SiteSettings::assetUrl('logo-dark') : null) ?? \App\Support\SiteSettings::assetUrl('logo');
    $showName = ! $logo || \App\Support\SiteSettings::showName();
@endphp
<span class="og-brand">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $showName ? '' : config('app.name') }}" class="og-brand-logo">
    @else
        <x-logo-mark class="og-brand-mark" />
    @endif
    @if ($showName)
        <span class="og-brand-name">{{ config('app.name') }}</span>
    @endif
</span>
