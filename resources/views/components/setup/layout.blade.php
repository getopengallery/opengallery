{{--
    Frame of the setup wizard (/setup): works before a database, a theme or
    any setting exists, so it uses the core tokens only and no theme blocks.
    Steps the visitor has reached can be opened again from the list.
--}}
@props(['step', 'reached', 'title', 'finished' => false])

@php
    $steps = \App\Enums\SetupStep::cases();
    $current = $step->position();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ $title }} – {{ __('OpenGallery setup') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}">

        <script>
            (() => {
                const mode = localStorage.getItem('filament:theme:mode') ?? 'system';
                const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        @if (\App\Support\Setup\Requirements::assetsAreBuilt())
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-canvas font-sans text-body text-ink antialiased">
        <header class="border-b border-line">
            <div class="mx-auto flex h-16 max-w-5xl items-center gap-3 px-4 sm:px-6">
                <x-logo-mark class="h-7 w-7 shrink-0" />
                <span class="text-[1.0625rem] font-semibold tracking-[-0.01em]">OpenGallery</span>
                <span class="text-muted">{{ __('Setup') }}</span>
            </div>
        </header>

        <div class="mx-auto grid max-w-5xl gap-10 px-4 py-10 sm:px-6 md:grid-cols-[13rem_minmax(0,1fr)] md:py-14">
            <nav aria-label="{{ __('Setup steps') }}">
                @unless ($finished)
                    <p class="text-caption text-muted md:hidden">
                        {{ __('Step :current of :total', ['current' => $current + 1, 'total' => count($steps)]) }}
                    </p>
                @endunless
                <ol class="hidden space-y-1 md:block">
                    @foreach ($steps as $item)
                        @php
                            $position = $item->position();
                            $isCurrent = $item === $step && ! $finished;
                            $isDone = $finished || ($position < $reached->position() && ! $isCurrent);
                            $canOpen = ! $finished && ! $isCurrent && $position <= $reached->position();
                        @endphp
                        <li>
                            @if ($canOpen)
                                <a href="{{ route($item->route()) }}" class="flex items-center gap-3 rounded-og px-2 py-1.5 text-sm text-ink hover:bg-surface">
                                    @include('setup.partials.step-badge')
                                    {{ $item->label() }}
                                </a>
                            @else
                                <span @if ($isCurrent) aria-current="step" @endif @class([
                                    'flex items-center gap-3 rounded-og px-2 py-1.5 text-sm',
                                    'font-semibold text-ink' => $isCurrent,
                                    'text-muted' => ! $isCurrent,
                                ])>
                                    @include('setup.partials.step-badge')
                                    {{ $item->label() }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>

            <main class="max-w-xl">
                <h1 class="text-heading font-semibold">{{ $title }}</h1>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
