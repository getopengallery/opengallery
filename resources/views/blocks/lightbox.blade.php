{{--
    Block: lightbox. Rendered inside an x-data="ogLightbox(...)" scope by the photo-grid blocks.
    Settings → Photos decides about the slideshow and the "i" panel (configure()); the heart and
    download button appear for items that carry `favorite` / `download`.
--}}
<div x-show="index !== null" x-cloak
     x-init="configure(@js(['slideshow' => \App\Support\SiteSettings::slideshowEnabled(), 'seconds' => \App\Support\SiteSettings::slideshowSeconds(), 'info' => \App\Support\SiteSettings::photoInfoEnabled()]))"
     x-transition.opacity.duration.150ms
     @keydown.window="onKey($event)"
     role="dialog" aria-modal="true" :aria-label="current?.title || @js(__('Photo'))"
     class="og-safe-area fixed inset-0 z-50 flex flex-col bg-black text-white">
    <div class="flex h-14 shrink-0 items-center justify-between gap-2 px-2 text-sm sm:px-4">
        <p class="min-w-0 truncate ps-2 text-white/70 sm:ps-0">
            <span x-text="current?.title"></span>
            <span class="ms-2 text-white/40" x-text="counter"></span>
        </p>
        <div class="flex shrink-0 items-center sm:gap-1">
            <button type="button" x-show="slideshow && (items.length > 1 || current?.nextPage || current?.previousPage)" @click="togglePlay()"
                    class="og-icon-btn hover:bg-white/10 focus-visible:outline-white"
                    :aria-label="playing ? @js(__('Pause slideshow')) : @js(__('Play slideshow'))" :title="playing ? @js(__('Pause slideshow')) : @js(__('Play slideshow'))">
                <svg x-show="! playing" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 4.5v11l9-5.5z"/></svg>
                <svg x-show="playing" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 4.5h2.5v11H6zM11.5 4.5H14v11h-2.5z"/></svg>
            </button>
            <button type="button" x-show="hasInfo" @click="infoOpen = ! infoOpen" :aria-expanded="infoOpen.toString()" aria-controls="og-lightbox-info"
                    class="og-icon-btn hover:bg-white/10 focus-visible:outline-white" :class="infoOpen && 'bg-white/10'"
                    aria-label="{{ __('Photo information') }}" title="{{ __('Photo information') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="M10 9v5M10 6.2v.1"/></svg>
            </button>
            <button type="button" x-show="current?.favorite" @click="toggleFavorite()" :aria-pressed="(current?.favorite?.active ?? false).toString()"
                    class="og-icon-btn hover:bg-white/10 focus-visible:outline-white"
                    aria-label="{{ __('Favorite') }}" :title="current?.favorite?.active ? @js(__('Remove from favorites')) : @js(__('Add to favorites'))">
                <svg class="h-5 w-5" :fill="current?.favorite?.active ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 17s-6.5-3.9-6.5-8.6A3.6 3.6 0 0 1 10 6.1a3.6 3.6 0 0 1 6.5 2.3C16.5 13.1 10 17 10 17Z"/></svg>
            </button>
            <a x-show="current?.download" :href="current?.download" download
               class="og-icon-btn hover:bg-white/10 focus-visible:outline-white" aria-label="{{ __('Download') }}" title="{{ __('Download') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v10M6 9l4 4 4-4M4 16h12"/></svg>
            </a>
            <button type="button" x-ref="close" @click="close()"
                    class="og-icon-btn hover:bg-white/10 focus-visible:outline-white" aria-label="{{ __('Close') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15"/></svg>
            </button>
        </div>
    </div>

    <div class="relative flex min-h-0 flex-1 touch-pan-y items-center justify-center px-4 pb-6 sm:px-16"
         @pointerdown="swipeStart($event)" @pointerup="swipeEnd($event)" @pointercancel="swipeCancel()">
        <img :src="current?.src" :alt="current?.title || ''" class="max-h-full max-w-full select-none object-contain" draggable="false" @click="tap()">

        <template x-if="items.length > 1 || current?.nextPage || current?.previousPage">
            <div>
                <button type="button" @click="step(-1)" aria-label="{{ __('Previous photo') }}"
                        class="absolute inset-y-0 start-0 flex w-12 items-center justify-center text-white/70 hover:text-white focus-visible:outline-white sm:w-16">
                    <svg class="h-6 w-6 drop-shadow rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4l-6 6 6 6"/></svg>
                </button>
                <button type="button" @click="step(1)" aria-label="{{ __('Next photo') }}"
                        class="absolute inset-y-0 end-0 flex w-12 items-center justify-center text-white/70 hover:text-white focus-visible:outline-white sm:w-16">
                    <svg class="h-6 w-6 drop-shadow rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 4l6 6-6 6"/></svg>
                </button>
            </div>
        </template>

        <aside id="og-lightbox-info" x-show="infoOpen && hasInfo" x-transition.opacity.duration.150ms
               class="absolute inset-x-0 bottom-0 z-10 max-h-[60%] overflow-y-auto rounded-t-og bg-black/90 p-5 text-sm sm:inset-x-auto sm:inset-y-0 sm:end-4 sm:max-h-none sm:w-full sm:max-w-xs sm:rounded-none"
               aria-label="{{ __('Photo information') }}">
            {{-- On phones the panel is a bottom sheet over the photo, so it gets its own close button. --}}
            <div class="flex items-start justify-between gap-2">
                <h2 class="font-semibold" x-text="current?.title || @js(__('Photo information'))"></h2>
                <button type="button" @click="infoOpen = false" class="og-icon-btn -me-3 -mt-3 hover:bg-white/10 focus-visible:outline-white sm:hidden" aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15"/></svg>
                </button>
            </div>
            <p x-show="current?.description" x-text="current?.description" class="mt-3 whitespace-pre-line text-white/80"></p>
            <dl class="mt-4 space-y-2">
                <div x-show="current?.taken" class="flex justify-between gap-4">
                    <dt class="text-white/50">{{ __('Taken') }}</dt>
                    <dd class="text-end" x-text="current?.taken"></dd>
                </div>
                <template x-for="detail in (current?.info ?? [])" :key="detail.label">
                    <div class="flex justify-between gap-4">
                        <dt class="text-white/50" x-text="detail.label"></dt>
                        <dd class="text-end" x-text="detail.value"></dd>
                    </div>
                </template>
            </dl>
        </aside>
    </div>

    <div x-show="current?.description || current?.taken" class="shrink-0 px-4 pb-6 text-center text-sm sm:px-16">
        <p x-show="current?.description" x-text="current?.description" class="mx-auto max-w-2xl whitespace-pre-line text-white/80"></p>
        <p x-show="current?.taken" class="mt-1 text-white/50">{{ __('Taken') }} <span x-text="current?.taken"></span></p>
    </div>
</div>
