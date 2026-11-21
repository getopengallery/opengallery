{{-- Sign-in methods offered by plugins that can be linked to this account. --}}
@php
    $options = app(\App\Extensions\LoginOptions::class)->all()->filter(fn ($option) => $option->linkUrl);
    $linked = $user->socialAccounts->keyBy('provider');
@endphp

@if ($options->isNotEmpty() || $linked->isNotEmpty())
    <section>
        <header>
            <h2 class="text-title font-semibold text-ink">{{ __('Linked accounts') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Sign in with any of these in addition to your password.') }}</p>
        </header>

        <x-input-error :messages="$errors->get('sign_in')" class="mt-4" />

        @if (session('status') === 'account-linked')
            <p class="mt-4 text-sm text-muted" role="status">{{ __('Account linked.') }}</p>
        @elseif (session('status') === 'account-unlinked')
            <p class="mt-4 text-sm text-muted" role="status">{{ __('Account unlinked.') }}</p>
        @endif

        <ul class="mt-6 divide-y divide-line">
            @foreach ($options->keys()->merge($linked->keys())->unique() as $key)
                @php
                    $option = $options->get($key);
                @endphp
                <li class="flex items-center justify-between gap-4 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        @if ($option?->iconUrl)
                            <img src="{{ $option->iconUrl }}" alt="" class="h-5 w-5 shrink-0 object-contain">
                        @endif
                        <div class="min-w-0">
                            <p class="font-medium text-ink">{{ $option?->name ?? $key }}</p>
                            @if ($linked->has($key))
                                <p class="truncate text-sm text-muted">{{ $linked[$key]->email ?? __('Linked') }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($linked->has($key))
                        <form method="POST" action="{{ route('linked-accounts.destroy', $key) }}">
                            @csrf
                            @method('DELETE')
                            <x-secondary-button type="submit">{{ __('Unlink') }}</x-secondary-button>
                        </form>
                    @elseif ($option)
                        <a href="{{ $option->linkUrl }}" class="og-link rounded-og text-sm font-medium text-ink">{{ __('Link') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
