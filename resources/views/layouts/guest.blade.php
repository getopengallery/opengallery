<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@isset($title){{ $title }} – @endisset{{ config('app.name', 'OpenGallery') }}</title>
        @include('partials.page-meta', ['meta' => $meta ?? \App\Support\PageMeta::hidden(), 'pageTitle' => isset($title) ? trim((string) $title) : null])

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.theme-head')
    </head>
    <body class="min-h-screen bg-canvas font-sans text-body text-ink">
        <x-block name="auth-shell">{{ $slot }}</x-block>
        @include('partials.background-jobs')
    </body>
</html>
