{{--
    Right side of the admin top bar, built like the site header
    (x-notification-bell, x-theme-mode-toggle and blocks.partials.account):
    the notifications bell, a light/dark switch and the account menu
    (profile picture, name, Profile, Log out).

    Replaces Filament's user menu and its three-way theme switcher. The switch
    goes through Filament's "theme-changed" event, which stores the choice
    under the same key the site uses (filament:theme:mode).
    Styles: .og-topbar-* / .og-account-* in filament.partials.theme-styles.
--}}
@php
    $user = filament()->auth()->user();
    $avatarUrl = $user ? \App\Support\Avatars::url($user) : null;
@endphp

@if ($user)
    <div class="og-topbar-tools">
        {{-- Notifications (App\Support\Inbox), loaded after the page. --}}
        @livewire(\App\Livewire\DatabaseNotifications::class, ['lazy' => true])

        <button type="button"
                x-data
                x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
                x-bind:aria-pressed="($store.theme === 'dark').toString()"
                x-bind:aria-label="$store.theme === 'dark' ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
                x-bind:title="$store.theme === 'dark' ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
                aria-label="{{ __('Switch between light and dark mode') }}"
                class="og-topbar-icon-btn">
            {{-- Light mode: sun. Dark mode: moon. Pure CSS, so it is right before Alpine loads. --}}
            <svg viewBox="0 0 20 20" class="og-mode-light" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                <circle cx="10" cy="10" r="3.5"/>
                <path d="M10 1.75v2M10 16.25v2M1.75 10h2M16.25 10h2M4.17 4.17l1.41 1.41M14.42 14.42l1.41 1.41M4.17 15.83l1.41-1.41M14.42 5.58l1.41-1.41"/>
            </svg>
            <svg viewBox="0 0 20 20" class="og-mode-dark" aria-hidden="true">
                <path d="M16.5 12.2A7 7 0 0 1 7.8 3.5a7 7 0 1 0 8.7 8.7z" fill="currentColor"/>
            </svg>
        </button>

        <x-filament::dropdown placement="bottom-end" teleport width="og-account-menu" class="og-account">
            <x-slot name="trigger">
                <button type="button" class="og-account-trigger" aria-label="{{ $user->name }}">
                    <span class="og-account-avatar">
                        @if ($avatarUrl)
                            <img src="{{ $avatarUrl }}" alt="">
                        @else
                            <span aria-hidden="true">{{ \App\Support\Avatars::initials($user->name) }}</span>
                        @endif
                    </span>
                    <span class="og-account-name">{{ $user->name }}</span>
                    <svg class="og-account-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item tag="a" :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-filament::dropdown.list.item>

                <x-filament::dropdown.list.item tag="form" method="post" :action="filament()->getLogoutUrl()">
                    {{ __('Log out') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
@endif
