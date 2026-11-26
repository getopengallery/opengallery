{{--
    Bell for logged-in visitors: links to the notifications page and shows how
    many are unread (App\Support\Inbox). Used by the header layouts next to the
    light/dark switch; renders nothing for guests.
--}}
@auth
    @php($unread = \App\Support\Inbox::unreadCount(auth()->user()))
    <a href="{{ route('notifications.index') }}"
       aria-label="{{ $unread ? __('Notifications, :count unread', ['count' => $unread]) : __('Notifications') }}"
       title="{{ __('Notifications') }}"
       @if (request()->routeIs('notifications.index')) aria-current="page" @endif
       {{ $attributes->merge(['class' => 'og-icon-btn relative text-muted hover:bg-surface hover:text-ink']) }}>
        <svg viewBox="0 0 20 20" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 8a5 5 0 0 1 10 0c0 4 1.5 5.5 2 6H3c.5-.5 2-2 2-6z"/>
            <path d="M8.25 16.5a1.9 1.9 0 0 0 3.5 0"/>
        </svg>
        @if ($unread)
            <span class="absolute end-0.5 top-0.5 min-w-[1rem] rounded-full bg-primary px-1 text-center text-[0.625rem] font-semibold leading-4 text-on-primary" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </a>
@endauth
