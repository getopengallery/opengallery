{{--
    Light / dark mode. Uses the same storage key as the Filament admin
    ("filament:theme:mode": light | dark | system), so switching in one place
    switches both. Runs before paint to avoid a flash of the wrong mode.
--}}
<script>
    (() => {
        const apply = () => {
            const mode = localStorage.getItem('filament:theme:mode') ?? 'system';
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        };
        apply();
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', apply);
    })();
</script>

<link rel="icon" href="{{ \App\Support\SiteSettings::faviconUrl() }}">
@include('partials.web-app')

@php($themes = app(\App\Extensions\ThemeManager::class))
<style>{!! $themes->cssVariables() !!}</style>
@foreach ($themes->stylesheets() as $href)
    <link rel="stylesheet" href="{{ $href }}">
@endforeach
@if ($customCss = $themes->customCss())
    <style id="og-custom-css">{!! $customCss !!}</style>
@endif
@stack('head')
