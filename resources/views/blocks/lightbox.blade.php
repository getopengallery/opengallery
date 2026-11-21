{{-- Block: lightbox. Rendered inside an x-data="ogLightbox(...)" scope by the photo-grid blocks. --}}
<div x-show="index !== null" x-cloak
     x-transition.opacity.duration.150ms
     @keydown.window="onKey($event)"
     role="dialog" aria-modal="true" :aria-label="current?.title || @js(__('Photo'))"
     class="fixed inset-0 z-50 flex flex-col bg-black text-white">
    <div class="flex h-14 shrink-0 items-center justify-between px-4 text-sm">
        <p class="truncate text-white/70">
            <span x-text="current?.title"></span>
            <span class="ms-2 text-white/40" x-text="counter"></span>
        </p>
        <div class="flex items-center gap-1">
            <a x-show="current?.download" :href="current?.download" download
               class="inline-flex h-9 w-9 items-center justify-center rounded-og hover:bg-white/10 focus-visible:outline-white" aria-label="{{ __('Download') }}" title="{{ __('Download') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v10M6 9l4 4 4-4M4 16h12"/></svg>
            </a>
            <button type="button" x-ref="close" @click="close()"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-og hover:bg-white/10 focus-visible:outline-white" aria-label="{{ __('Close') }}">
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
                        class="absolute inset-y-0 left-0 flex w-10 items-center justify-center text-white/50 hover:text-white focus-visible:outline-white sm:w-16">
                    <svg class="h-6 w-6 drop-shadow" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4l-6 6 6 6"/></svg>
                </button>
                <button type="button" @click="step(1)" aria-label="{{ __('Next photo') }}"
                        class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-white/50 hover:text-white focus-visible:outline-white sm:w-16">
                    <svg class="h-6 w-6 drop-shadow" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 4l6 6-6 6"/></svg>
                </button>
            </div>
        </template>
    </div>

    <div x-show="current?.description || current?.taken" class="shrink-0 px-4 pb-6 text-center text-sm sm:px-16">
        <p x-show="current?.description" x-text="current?.description" class="mx-auto max-w-2xl whitespace-pre-line text-white/80"></p>
        <p x-show="current?.taken" class="mt-1 text-white/50">{{ __('Taken') }} <span x-text="current?.taken"></span></p>
    </div>
</div>
