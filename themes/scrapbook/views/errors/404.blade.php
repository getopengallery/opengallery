{{--
    Scrapbook theme: "not found" page. Self-contained on purpose (error pages
    may render without a database): core CSS plus the theme stylesheet, whose
    fallback tokens supply the sticker colours. No theme or site settings.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>404 – {{ __('theme-scrapbook::site.not_found_title') }}</title>
        @vite(['resources/css/app.css'])
        <link rel="stylesheet" href="{{ route('theme.asset', ['theme' => 'scrapbook', 'path' => 'scrapbook.css']) }}">
    </head>
    <body class="bg-canvas font-sans text-body text-ink">
        <main class="sb-error">
            <p class="sb-error-code" aria-hidden="true"><span>4</span><span>0</span><span>4</span></p>
            <h1>{{ __('theme-scrapbook::site.not_found_title') }}</h1>
            <p class="sb-error-text">{{ __('theme-scrapbook::site.not_found_text') }}</p>
            <a href="{{ url('/') }}" class="og-btn-primary">{{ __('theme-scrapbook::site.not_found_home') }}</a>
        </main>
    </body>
</html>
