<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@isset($title){{ $title }} – @endisset{{ config('app.name', 'OpenGallery') }}</title>
        @include('partials.page-meta', ['meta' => $meta ?? \App\Support\PageMeta::site(), 'pageTitle' => isset($title) ? trim((string) $title) : null])

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.theme-head')
    </head>
    <body class="flex min-h-screen flex-col bg-canvas font-sans text-body text-ink">
        @if (\App\Support\Theme::enabled('header_sticky'))
            <div class="sticky top-0 z-40 bg-canvas"><x-block name="site-header" /></div>
        @else
            <x-block name="site-header" />
        @endif

        <main class="flex-1 pb-24">
            @isset($header)
                <x-block name="page-header">{{ $header }}</x-block>
            @endisset

            {{ $slot }}
        </main>

        <x-block name="site-footer" />
        @include('partials.background-jobs')
    </body>
</html>
