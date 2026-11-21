{{--
    The bell that opens the admin's notification panel (App\Livewire\DatabaseNotifications),
    styled like the light/dark switch next to it. Expects $unreadNotificationsCount.
--}}
<button type="button"
        class="og-topbar-icon-btn og-bell"
        aria-label="{{ $unreadNotificationsCount
            ? __('Notifications, :count unread', ['count' => $unreadNotificationsCount])
            : __('Notifications') }}"
        title="{{ __('Notifications') }}">
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M5 8a5 5 0 0 1 10 0c0 4 1.5 5.5 2 6H3c.5-.5 2-2 2-6z"/>
        <path d="M8.25 16.5a1.9 1.9 0 0 0 3.5 0"/>
    </svg>
    @if ($unreadNotificationsCount)
        <span class="og-bell-badge" aria-hidden="true">{{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}</span>
    @endif
</button>
