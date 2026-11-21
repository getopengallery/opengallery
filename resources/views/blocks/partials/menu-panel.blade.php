{{-- Full menu panel (links + account), opened by a menu button. Expects Alpine "open". --}}
<nav id="site-menu" x-show="open" x-cloak class="border-t border-line" aria-label="{{ __('Main') }}">
    <div class="og-container flex flex-col py-2">
        @foreach (\App\Support\Navigation::links() as $link)
            <a href="{{ $link['href'] }}" @class(['py-2.5 text-body', 'font-semibold text-ink' => $link['active'], 'text-muted hover:text-ink' => ! $link['active']]) @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
        @endforeach

        <div class="mt-2 border-t border-line pt-2">
            @auth
                <a href="{{ route('profile.edit') }}" class="block py-2.5 text-muted hover:text-ink">{{ __('Profile') }}</a>
                @if ($adminUrl = \App\Support\Navigation::adminUrl())
                    <a href="{{ $adminUrl }}" class="block py-2.5 text-muted hover:text-ink">{{ __('Admin') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="py-2.5 text-muted hover:text-ink">{{ __('Log out') }}</button>
                </form>
            @elseif (! \App\Support\Navigation::onLanding())
                <a href="{{ route('login') }}" class="block py-2.5 text-muted hover:text-ink">{{ __('Log in') }}</a>
                @if (config('gallery.registration_enabled'))
                    <a href="{{ route('register') }}" class="block py-2.5 text-muted hover:text-ink">{{ __('Create account') }}</a>
                @endif
            @endauth
        </div>
    </div>
</nav>
