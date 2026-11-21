<x-setup.layout :step="$step" :reached="$reached" :title="__('Welcome to OpenGallery')">
    <p class="mt-3 text-muted">
        {{ __('This wizard sets up your gallery in a few steps: the database, your administrator account, the main settings and, if you like, some demo content to try things out.') }}
    </p>

    <form method="POST" action="{{ route('setup.start') }}" class="mt-8 space-y-8">
        @csrf

        <div>
            <x-input-label for="locale" :value="__('Language')" />
            <select id="locale" name="locale" class="og-input mt-1.5 w-full">
                @foreach ($locales as $code => $name)
                    <option value="{{ $code }}" @selected(old('locale', app()->getLocale()) === $code)>{{ $name }}</option>
                @endforeach
            </select>
            <p class="mt-1.5 text-caption text-muted">{{ __('Used by this wizard and preselected as the language of your gallery.') }}</p>
            <x-input-error :messages="$errors->get('locale')" class="mt-2" />
        </div>

        <section>
            <h2 class="text-title font-semibold">{{ __('Server check') }}</h2>
            <ul class="mt-3 divide-y divide-line rounded-og border border-line">
                @foreach ($checks as $check)
                    <li class="flex items-start gap-3 px-4 py-2.5 text-sm">
                        @if ($check['passed'])
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-ink" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 8.5l3 3 7-7"/></svg>
                            <span class="sr-only">{{ __('OK') }}:</span>
                        @elseif ($check['required'])
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-danger" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                            <span class="sr-only">{{ __('Missing') }}:</span>
                        @else
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-muted" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h8"/></svg>
                            <span class="sr-only">{{ __('Optional') }}:</span>
                        @endif
                        <span>
                            <span @class(['text-danger' => ! $check['passed'] && $check['required']])>{{ $check['label'] }}</span>
                            @if (! $check['passed'] && $check['hint'])
                                <span class="block text-caption text-muted">{{ $check['hint'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <x-input-error :messages="$errors->get('requirements')" class="mt-2" />
        </section>

        <div class="flex flex-wrap items-center gap-3">
            @if ($passed)
                <x-primary-button>{{ __('Start setup') }}</x-primary-button>
            @else
                <a href="{{ route('setup.welcome') }}" class="og-btn-primary">{{ __('Check again') }}</a>
                <p class="text-sm text-muted">{{ __('Fix the items marked above, for example in your hosting panel, then check again.') }}</p>
            @endif
        </div>
    </form>
</x-setup.layout>
