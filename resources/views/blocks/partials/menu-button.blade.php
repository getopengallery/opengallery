<button type="button" @click="open = ! open" :aria-expanded="open" aria-controls="site-menu"
        class="og-icon-btn text-ink hover:bg-surface" aria-label="{{ __('Menu') }}">
    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true">
        <path x-show="! open" d="M3 6h14M3 10h14M3 14h14" />
        <path x-show="open" x-cloak d="M5 5l10 10M15 5L5 15" />
    </svg>
</button>
