/**
 * Photo viewer used by the photo-grid blocks.
 *
 * Markup contract: the grid's links carry data-index and point at the large
 * image, so everything still works (as plain links) without JavaScript.
 *
 * Items come from App\Support\PhotoGridItems. The address follows the open
 * photo (#photo-{id}), so a photo can be linked to and survives a reload.
 * On a paginated album every item knows its `page` address, and the first /
 * last item carry the previous / next page: `previousItems` / `nextItems`
 * (that page's items as JSON) are loaded and added to the viewer without
 * leaving the page; without them (or if loading fails) the viewer goes to
 * `previousPage` / `nextPage` (ending in #photo-last / #photo-first) instead.
 * Closing the viewer on a photo of another page opens that page.
 *
 * The lightbox block calls configure() with what Settings → Photos allows:
 * the heart (items carry `favorite`: {url, active}), the "i" panel (items
 * carry `info`: camera details) and the slideshow (seconds per photo).
 *
 * @param {{id: number, src: string, title: string|null, description: string|null, taken: string|null, download?: string|null, favorite?: {url: string, active: boolean}|null, info?: {label: string, value: string}[]|null, footer?: {text: string|null, note: string|null, license: {name: string, title: string|null, url: string|null}|null}|null, position?: number, total?: number, page?: string, previousPage?: string, nextPage?: string, previousItems?: string, nextItems?: string}[]} items
 */
export default (items) => {
    /** Neighbouring pages being loaded or loaded: address => Promise of items. */
    const loaded = new Map();

    const load = (url) => {
        if (!loaded.has(url)) {
            loaded.set(
                url,
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error(response.statusText))))
                    .then((data) => (data.items?.length ? data.items : Promise.reject(new Error('No photos'))))
                    .catch((error) => {
                        loaded.delete(url);
                        throw error;
                    }),
            );
        }

        return loaded.get(url);
    };

    return {
        items,
        index: null,
        opener: null,
        /** How many items of previous pages were put in front of the grid's own. */
        offset: 0,
        /** This page's address, and the address and page the grid shows. */
        address: window.location.pathname + window.location.search,
        gridPage: items[0]?.page ?? null,
        loading: false,

        /** What Settings → Photos allows (see configure()). */
        slideshow: false,
        seconds: 5,
        photoInfo: false,
        infoOpen: false,
        playing: false,
        timer: null,

        configure(options) {
            this.slideshow = Boolean(options?.slideshow);
            this.seconds = Math.max(1, Number(options?.seconds) || 5);
            this.photoInfo = Boolean(options?.info);
        },

        init() {
            const hash = window.location.hash;
            let index = -1;

            if (hash === '#photo-first') index = 0;
            else if (hash === '#photo-last') index = this.items.length - 1;
            else if (hash.startsWith('#photo-')) index = this.items.findIndex((item) => `#photo-${item.id}` === hash);

            if (index >= 0 && this.items[index]) this.open(index);
        },

        get current() {
            return this.index === null ? null : this.items[this.index];
        },

        /** "3 / 140": the place in the whole album, not just this page. */
        get counter() {
            if (!this.current) return '';
            return `${this.current.position ?? this.index + 1} / ${this.current.total ?? this.items.length}`;
        },

        /** index: the photo's place in the grid of this page. */
        open(index, event) {
            this.opener = event?.currentTarget ?? null;
            document.body.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.close?.focus());
            this.show(index + this.offset, 1);
        },

        close() {
            // The photo is on another page of the album: open that page (the viewer stays up until it has loaded).
            if (this.onOtherPage()) {
                window.location.replace(this.current.page);
                return;
            }

            this.pause();
            this.infoOpen = false;
            this.index = null;
            document.body.classList.remove('overflow-hidden');
            this.forget();
            this.opener?.focus();
        },

        step(delta) {
            if (this.index === null || this.loading) return;

            const target = this.index + delta;
            if (target >= this.items.length || target < 0) {
                const more = delta > 0 ? this.current.nextItems : this.current.previousItems;
                const page = delta > 0 ? this.current.nextPage : this.current.previousPage;

                if (more) {
                    this.goOn(more, page, delta);
                    return;
                }

                if (page) {
                    window.location.href = page;
                    return;
                }
            }

            this.show((target + this.items.length) % this.items.length, delta);
        },

        show(index, delta) {
            this.index = index;
            this.schedule();
            // The next photo in the direction of travel first, then the one behind, so stepping back is instant too.
            this.preload(index + delta);
            this.preload(index - delta);
            this.prefetch();
            this.remember();
        },

        /** Add the neighbouring page's photos and show the first (or last) of them. */
        async goOn(url, page, delta) {
            this.loading = true;

            try {
                const more = await load(url);

                if (delta > 0) {
                    this.items.push(...more);
                    if (this.index !== null) this.show(this.items.length - more.length, delta);
                } else {
                    this.items.unshift(...more);
                    this.offset += more.length;
                    if (this.index !== null) this.show(more.length - 1, delta);
                }
            } catch {
                window.location.href = page;
            } finally {
                this.loading = false;
            }
        },

        /** Whether the open photo has anything for the "i" panel. */
        get hasInfo() {
            return this.photoInfo && Boolean(this.current?.description || this.current?.taken || this.current?.info?.length || this.current?.license);
        },

        /** Like or unlike the open photo; the heart changes at once and goes back if that fails. */
        async toggleFavorite() {
            const item = this.current;
            if (!item?.favorite) return;

            const before = item.favorite.active;
            item.favorite.active = !before;

            try {
                const response = await fetch(item.favorite.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!response.ok) throw new Error(response.statusText);
                item.favorite.active = Boolean((await response.json()).favorite);
            } catch {
                item.favorite.active = before;
            }
        },

        play() {
            if (!this.slideshow || this.index === null) return;
            this.playing = true;
            this.schedule();
        },

        pause() {
            this.playing = false;
            clearTimeout(this.timer);
            this.timer = null;
        },

        togglePlay() {
            this.playing ? this.pause() : this.play();
        },

        /** While playing: the next photo after the configured time (counted again after every change). */
        schedule() {
            clearTimeout(this.timer);
            this.timer = this.playing ? setTimeout(() => this.step(1), this.seconds * 1000) : null;
        },

        /** Load the neighbouring page early when the open photo is the first or last one. */
        prefetch() {
            if (this.index === this.items.length - 1 && this.current?.nextItems) load(this.current.nextItems).catch(() => {});
            if (this.index === 0 && this.current?.previousItems) load(this.current.previousItems).catch(() => {});
        },

        onOtherPage() {
            return Boolean(this.current?.page && this.gridPage && this.current.page !== this.gridPage);
        },

        preload(i) {
            const item = this.items[(i + this.items.length) % this.items.length];
            if (item) new Image().src = item.src;
        },

        /** Put the open photo (and its page) in the address (replaceState: Back still leaves the album). */
        remember() {
            if (!this.current?.id) return;
            const page = this.onOtherPage() ? this.current.page : this.address;
            history.replaceState(history.state, '', `${page}#photo-${this.current.id}`);
        },

        forget() {
            if (window.location.hash.startsWith('#photo-')) {
                history.replaceState(history.state, '', this.address);
            }
        },

        // Swipe left / right on touch screens (mouse drags are left alone).
        swipeFrom: null,
        swiped: false,

        swipeStart(event) {
            if (event.pointerType === 'mouse') return;
            this.swipeFrom = { x: event.clientX, y: event.clientY };
        },

        swipeEnd(event) {
            if (!this.swipeFrom) return;
            const dx = event.clientX - this.swipeFrom.x;
            const dy = event.clientY - this.swipeFrom.y;
            this.swipeFrom = null;

            if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
            this.step(dx < 0 ? 1 : -1);
            // Ignore the tap some browsers still send after a swipe.
            this.swiped = true;
            setTimeout(() => (this.swiped = false), 400);
        },

        swipeCancel() {
            this.swipeFrom = null;
        },

        /** A tap or click on the photo shows the next one. */
        tap() {
            if (!this.swiped) this.step(1);
        },

        onKey(event) {
            if (this.index === null) return;
            if (event.key === 'Escape') {
                if (this.infoOpen) this.infoOpen = false;
                else this.close();
            }
            if (event.key === ' ' && this.slideshow && !event.target.closest?.('button, a, input, textarea')) {
                event.preventDefault();
                this.togglePlay();
            }
            if (event.key === 'ArrowRight') this.step(1);
            if (event.key === 'ArrowLeft') this.step(-1);
        },
    };
};
