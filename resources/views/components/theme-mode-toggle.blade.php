{{--
    Light/dark switch. Every press flips what is shown; all switches on the
    page share one state (Alpine store "appearance", resources/js/appearance.js)
    and the admin panel uses the same setting.
--}}
<button type="button"
        x-data
        @click="$store.appearance.toggle()"
        :aria-pressed="$store.appearance.isDark.toString()"
        :aria-label="$store.appearance.isDark ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
        :title="$store.appearance.isDark ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))"
        aria-label="{{ __('Switch between light and dark mode') }}"
        {{ $attributes->merge(['class' => 'inline-flex h-9 w-9 items-center justify-center rounded-og text-muted hover:bg-surface hover:text-ink']) }}>
    {{-- Shown in light mode: sun. In dark mode: moon. Pure CSS, so it is right before Alpine loads. --}}
    <svg viewBox="0 0 20 20" class="h-[18px] w-[18px] dark:hidden" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
        <circle cx="10" cy="10" r="3.5"/>
        <path d="M10 1.75v2M10 16.25v2M1.75 10h2M16.25 10h2M4.17 4.17l1.41 1.41M14.42 14.42l1.41 1.41M4.17 15.83l1.41-1.41M14.42 5.58l1.41-1.41"/>
    </svg>
    <svg viewBox="0 0 20 20" class="hidden h-[18px] w-[18px] dark:block" aria-hidden="true">
        <path d="M16.5 12.2A7 7 0 0 1 7.8 3.5a7 7 0 1 0 8.7 8.7z" fill="currentColor"/>
    </svg>
</button>
