@php
    $tree = $this->getNavigation();
    $guides = $this->getGuides();
    $currentGuide = $this->getCurrentGuide();
    $outline = $this->getOutline();
    $neighbours = $this->getNeighbours();
@endphp

<x-filament-panels::page>
    <div
        class="og-docs"
        {{--
            Docs open without a page load: links to another doc call open(), which swaps
            the article while the contents keep their place. The doc is in the URL
            (back/forward work); modified clicks and new tabs stay normal links.
            Nothing in x-data may change between docs, as Alpine keeps this state.
        --}}
        x-data="{
            open: false,
            query: '',
            guide: null,
            guides: @js(array_column($guides, 'folder')),
            pages: @js($this->getPages()),
            home: @js(\App\Support\Documentation::HOME),
            section: null,
            sectionTitle: null,
            title: null,
            hash: null,
            fromArticle: false,
            anchor: null,
            init() {
                this.guide = this.guideOf(this.$wire.doc);
                this.title = document.querySelector('.fi-header-heading')?.textContent.trim() ?? null;
                const wide = window.matchMedia('(min-width: 80rem)');
                wide.addEventListener('change', (event) => { if (event.matches) this.open = false });
                window.addEventListener('scroll', () => this.track(), { passive: true });
                this.$wire.$watch('doc', () => this.$nextTick(() => this.arrive()));
                this.track();
            },
            headings() {
                return [...this.$root.querySelectorAll('.og-docs-body > h2 > .heading-permalink')].map((anchor) => anchor.parentElement);
            },
            track() {
                let current = null;
                for (const heading of this.headings()) {
                    if (heading.getBoundingClientRect().top > 160) break;
                    current = heading;
                }
                const anchor = current?.querySelector('.heading-permalink');
                this.section = anchor?.id ?? null;
                this.sectionTitle = current ? current.textContent.slice(0, -anchor.textContent.length).trim() : null;
            },
            guideOf(doc) {
                const first = doc.split('/')[0];
                return doc.includes('/') && this.guides.includes(first) ? first : (this.guides[0] ?? null);
            },
            follow(event) {
                const link = event.target.closest('a[href]');
                if (! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.getAttribute('href').startsWith('#')) return;
                const url = new URL(link.href, window.location.href);
                if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
                const doc = url.searchParams.get('doc') ?? this.home;
                event.preventDefault();
                this.open = false;
                this.query = '';
                this.hash = url.hash;
                this.fromArticle = ! link.closest('#og-docs-nav');
                this.anchor = link.classList.contains('og-docs-link') ? link.getBoundingClientRect().top : null;
                doc === this.$wire.doc ? this.arrive() : this.$wire.open(doc);
            },
            arrive() {
                const target = this.hash ? document.getElementById(decodeURIComponent(this.hash.slice(1))) : null;
                target ? target.scrollIntoView() : window.scrollTo({ top: 0 });
                if (this.hash) history.replaceState(history.state, '', this.hash);
                this.hash = null;
                this.guide = this.guideOf(this.$wire.doc);
                const heading = document.querySelector('.fi-header-heading');
                if (heading) {
                    document.title = this.title ? document.title.replace(this.title, heading.textContent.trim()) : document.title;
                }
                this.title = heading?.textContent.trim();
                this.reveal();
                if (this.fromArticle && heading) {
                    heading.setAttribute('tabindex', '-1');
                    heading.focus({ preventScroll: true });
                }
                this.anchor = null;
                this.fromArticle = false;
                this.track();
            },
            reveal() {
                const current = this.$root.querySelector('#og-docs-nav .og-docs-link[aria-current=page]');
                if (! current) return;
                current.closest('details')?.setAttribute('open', '');
                const list = this.$root.querySelector('.og-docs-nav-scroll');
                const box = list.getBoundingClientRect();
                const item = current.getBoundingClientRect();
                if (this.anchor !== null) {
                    {{-- The link that was clicked stays where it was, whatever opened or closed above it. --}}
                    list.scrollTop += item.top - this.anchor;
                } else if (item.top < box.top || item.bottom > box.bottom) {
                    list.scrollTop += item.top - box.top - box.height / 3;
                }
            },
            get results() {
                const words = this.query.toLowerCase().split(/\s+/).filter(Boolean);
                return words.length === 0 ? [] : this.pages.filter((page) => words.every((word) => (page.title + ' ' + page.context).toLowerCase().includes(word)));
            },
            moveGuide(step) {
                const index = (this.guides.indexOf(this.guide) + step + this.guides.length) % this.guides.length;
                this.guide = this.guides[index];
                this.$nextTick(() => this.$refs['tab-' + index]?.focus());
            },
        }"
        x-on:keydown.escape.window="open = false"
        x-on:click="follow($event)"
    >
        {{-- Below wide screens: where you are, and the way into the contents. --}}
        <div class="og-docs-bar">
            <button
                type="button"
                class="og-docs-bar-button"
                aria-controls="og-docs-nav"
                x-bind:aria-expanded="open.toString()"
                x-on:click="open = true; $nextTick(() => reveal())"
            >
                <x-filament::icon icon="heroicon-o-bars-3-bottom-left" class="og-docs-bar-icon" />
                <span class="og-docs-bar-text">
                    <span class="og-docs-bar-title">{{ $this->getTitle() }}</span>
                    <span class="og-docs-bar-hint" x-text="sectionTitle ?? @js(__('Show all pages'))">{{ __('Show all pages') }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-up-down" class="og-docs-bar-icon" />
            </button>
        </div>

        <div class="og-docs-backdrop" x-show="open" x-cloak x-transition.opacity x-on:click="open = false"></div>

        <nav
            id="og-docs-nav"
            class="og-docs-nav"
            x-bind:class="{ 'is-open': open }"
            x-trap.noscroll="open"
            aria-label="{{ __('Documentation') }}"
        >
            <div class="og-docs-nav-head">
                <p class="og-docs-nav-heading">{{ __('Contents') }}</p>
                <button type="button" class="og-docs-nav-close" x-on:click="open = false">
                    <x-filament::icon icon="heroicon-m-x-mark" class="og-docs-bar-icon" />
                    <span class="sr-only">{{ __('Close') }}</span>
                </button>
            </div>

            <div class="og-docs-nav-scroll">
                <label for="og-docs-filter" class="sr-only">{{ __('Filter pages') }}</label>
                <input
                    id="og-docs-filter"
                    type="search"
                    class="og-docs-filter"
                    placeholder="{{ __('Filter pages') }}"
                    autocomplete="off"
                    x-model="query"
                    x-on:keydown.escape.stop="query === '' ? (open = false) : (query = '')"
                >

                <div x-show="query.trim() !== ''" x-cloak>
                    <ul class="og-docs-results">
                        <template x-for="page in results" x-bind:key="page.url">
                            <li>
                                <a x-bind:href="page.url">
                                    <span class="og-docs-results-title" x-text="page.title"></span>
                                    <span class="og-docs-results-context" x-show="page.context" x-text="page.context"></span>
                                </a>
                            </li>
                        </template>
                    </ul>
                    <p class="og-docs-results-empty" x-show="results.length === 0">
                        {{ __('No page titles match. The search at the top of the admin also looks inside pages.') }}
                    </p>
                </div>

                <div x-show="query.trim() === ''">
                    @if (count($guides) > 1)
                        <div class="og-docs-guides" role="tablist" aria-label="{{ __('Guides') }}">
                            @foreach ($guides as $index => $guide)
                                <button
                                    type="button"
                                    role="tab"
                                    id="og-docs-tab-{{ $index }}"
                                    aria-controls="og-docs-guide-{{ $index }}"
                                    x-ref="tab-{{ $index }}"
                                    aria-selected="{{ $guide['folder'] === $currentGuide ? 'true' : 'false' }}"
                                    tabindex="{{ $guide['folder'] === $currentGuide ? '0' : '-1' }}"
                                    x-bind:aria-selected="(guide === @js($guide['folder'])).toString()"
                                    x-bind:tabindex="guide === @js($guide['folder']) ? 0 : -1"
                                    x-on:click="guide = @js($guide['folder'])"
                                    x-on:keydown.right.prevent="moveGuide(1)"
                                    x-on:keydown.left.prevent="moveGuide(-1)"
                                >{{ $guide['title'] }}</button>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($guides as $index => $guide)
                        <div
                            id="og-docs-guide-{{ $index }}"
                            @if (count($guides) > 1) role="tabpanel" aria-labelledby="og-docs-tab-{{ $index }}" @endif
                            x-show="guide === @js($guide['folder'])"
                            @if ($guide['folder'] !== $currentGuide) x-cloak @endif
                        >
                            <ul class="og-docs-tree">
                                @if ($guide['key'])
                                    @include('filament.partials.documentation-nav-link', ['key' => $guide['key'], 'label' => __('Overview'), 'current' => $doc, 'outline' => $outline])
                                @endif
                                @foreach ($guide['children'] as $item)
                                    @include('filament.partials.documentation-nav-item', ['item' => $item, 'current' => $doc, 'outline' => $outline])
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    @if ($tree['key'] || array_filter($tree['children'], fn (array $item): bool => $item['type'] === 'doc'))
                        <ul class="og-docs-tree og-docs-tree-root">
                            @if ($tree['key'])
                                @include('filament.partials.documentation-nav-link', ['key' => $tree['key'], 'label' => $tree['title'], 'current' => $doc, 'outline' => $outline])
                            @endif
                            @foreach ($tree['children'] as $item)
                                @if ($item['type'] === 'doc')
                                    @include('filament.partials.documentation-nav-item', ['item' => $item, 'current' => $doc, 'outline' => $outline])
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </nav>

        <div class="og-docs-main" wire:loading.class="is-loading" wire:target="open">
            <x-filament::section class="og-docs-section">
                <article class="og-docs-body">
                    {!! $this->getContent() !!}
                </article>
            </x-filament::section>

            @if ($neighbours['previous'] || $neighbours['next'])
                <nav class="og-docs-pager" aria-label="{{ __('Previous and next page') }}">
                    @if ($neighbours['previous'])
                        <a href="{{ static::getUrl(['doc' => $neighbours['previous']['key']]) }}" rel="prev" class="og-docs-pager-link">
                            <span class="og-docs-pager-hint">
                                <x-filament::icon icon="heroicon-m-arrow-left" class="og-docs-pager-icon" />
                                {{ __('Previous') }}
                            </span>
                            <span class="og-docs-pager-title">{{ $neighbours['previous']['title'] }}</span>
                        </a>
                    @endif
                    @if ($neighbours['next'])
                        <a href="{{ static::getUrl(['doc' => $neighbours['next']['key']]) }}" rel="next" class="og-docs-pager-link og-docs-pager-next">
                            <span class="og-docs-pager-hint">
                                {{ __('Next') }}
                                <x-filament::icon icon="heroicon-m-arrow-right" class="og-docs-pager-icon" />
                            </span>
                            <span class="og-docs-pager-title">{{ $neighbours['next']['title'] }}</span>
                        </a>
                    @endif
                </nav>
            @endif
        </div>
    </div>
</x-filament-panels::page>
