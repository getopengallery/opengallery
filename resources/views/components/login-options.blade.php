{{--
    Sign-in methods contributed by plugins (App\Extensions\LoginOptions),
    e.g. "Log in with Keycloak". Errors from those plugins use the
    "sign_in" error key.
--}}
@php($options = app(\App\Extensions\LoginOptions::class)->all())

<x-input-error :messages="$errors->get('sign_in')" class="mt-6" />

@if ($options->isNotEmpty())
    <div class="mt-8 flex items-center gap-4 text-caption text-muted" aria-hidden="true">
        <span class="h-px flex-1 bg-line"></span>{{ __('or') }}<span class="h-px flex-1 bg-line"></span>
    </div>

    <ul class="mt-6 space-y-2">
        @foreach ($options as $option)
            <li>
                <a href="{{ $option->url }}" class="og-btn-secondary w-full gap-3 px-4 py-2.5">
                    @if ($option->iconUrl)
                        <img src="{{ $option->iconUrl }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                    @endif
                    <span class="min-w-0 text-center">
                        <span class="block">{{ $option->label }}</span>
                        @if ($option->description)
                            <span class="block truncate text-caption font-normal text-muted">{{ $option->description }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
