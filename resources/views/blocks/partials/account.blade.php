{{-- Account menu (logged in) or log-in / sign-up links. Used by the header layouts. --}}
@auth
    <x-dropdown align="right" width="48">
        <x-slot name="trigger">
            <button class="inline-flex items-center gap-2 rounded-og py-1.5 pe-1 ps-1.5 text-sm font-medium text-ink hover:bg-surface">
                <x-avatar :user="Auth::user()" size="sm" />
                {{ Auth::user()->name }}
                <svg class="h-4 w-4 text-muted" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
        </x-slot>

        <x-slot name="content">
            <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
            @if ($adminUrl = \App\Support\Navigation::adminUrl())
                <x-dropdown-link :href="$adminUrl">{{ __('Admin') }}</x-dropdown-link>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                    {{ __('Log out') }}
                </x-dropdown-link>
            </form>
        </x-slot>
    </x-dropdown>
@else
    <a href="{{ route('login') }}" class="rounded-og px-3 py-2 text-sm font-medium text-muted hover:text-ink">{{ __('Log in') }}</a>
    @if (config('gallery.registration_enabled'))
        <a href="{{ route('register') }}" class="og-btn-primary ms-1 py-1.5">{{ __('Create account') }}</a>
    @endif
@endauth
