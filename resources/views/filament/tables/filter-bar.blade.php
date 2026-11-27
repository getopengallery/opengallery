{{--
    Filter chips above an admin table (App\Filament\Tables\FilterBar). Each
    chip opens a popover (a bottom sheet on phones) whose fields are bound
    straight to the table's live filter state, so the table updates as you
    pick. Filament's own filter panel is hidden for these tables.
--}}
@php
    $activeCount = collect($chips)->where('active', true)->count();
    $hasMore = collect($chips)->contains(fn (array $chip): bool => ! $chip['primary']);
@endphp

<div
    class="og-filter-bar"
    wire:key="og-filter-bar"
    x-data="{ open: null, more: false }"
    x-on:keydown.escape.window="open = null; more = false"
>
    <div class="og-filter-bar-row" role="toolbar" aria-label="{{ __('Filters') }}">
        @foreach ($chips as $chip)
            @php
                $path = 'tableFilters.'.$chip['name'];
                $visible = $chip['primary'] || $chip['active'];
            @endphp

            <div
                class="og-filter-chip-wrap"
                wire:key="og-filter-chip-{{ $chip['name'] }}"
                @unless ($visible) x-show="open === @js($chip['name'])" x-cloak @endunless
            >
                @if ($chip['kind'] === 'toggle')
                    <button
                        type="button"
                        class="og-filter-chip @if ($chip['active']) is-active @endif"
                        aria-pressed="{{ $chip['active'] ? 'true' : 'false' }}"
                        x-on:click="open = null; $wire.$set(@js($path.'.isActive'), {{ $chip['active'] ? 'false' : 'true' }})"
                    >
                        @if ($chip['active'])
                            <x-filament::icon icon="heroicon-m-check" class="og-filter-chip-icon" />
                        @endif
                        {{ $chip['label'] }}
                    </button>
                @else
                    <button
                        type="button"
                        class="og-filter-chip @if ($chip['active']) is-active @endif"
                        aria-haspopup="dialog"
                        x-bind:aria-expanded="open === @js($chip['name'])"
                        x-on:click="more = false; open = open === @js($chip['name']) ? null : @js($chip['name'])"
                    >
                        <span>{{ $chip['label'] }}@if ($chip['active']):@endif</span>
                        @if ($chip['active'])
                            <span class="og-filter-chip-value">{{ $chip['summary'] }}</span>
                        @else
                            <x-filament::icon icon="heroicon-m-chevron-down" class="og-filter-chip-icon" />
                        @endif
                    </button>
                @endif

                @if ($chip['active'] && $chip['kind'] !== 'toggle')
                    <button
                        type="button"
                        class="og-filter-chip-remove"
                        aria-label="{{ __('Remove filter :name', ['name' => $chip['label']]) }}"
                        x-on:click="open = null"
                        wire:click="removeTableFilter(@js($chip['name']))"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="og-filter-chip-icon" />
                    </button>
                @endif

                @if ($chip['kind'] !== 'toggle')
                    <div class="og-filter-backdrop" x-show="open === @js($chip['name'])" x-cloak x-on:click="open = null"></div>

                    <div
                        class="og-filter-popover"
                        role="dialog"
                        aria-label="{{ $chip['label'] }}"
                        x-show="open === @js($chip['name'])"
                        x-cloak
                        x-on:click.outside="if (open === @js($chip['name']) && ! $event.target.closest('.og-filter-more-menu')) open = null"
                        x-data="{ q: '' }"
                    >
                        <div class="og-filter-popover-header">
                            <span class="og-filter-popover-title">{{ $chip['label'] }}</span>
                            @if ($chip['active'])
                                <button type="button" class="og-filter-link" wire:click="removeTableFilter(@js($chip['name']))">{{ __('Clear') }}</button>
                            @endif
                        </div>

                        <div class="og-filter-popover-body">
                            @switch($chip['kind'])
                                @case('select')
                                    @if (count($chip['options']) > \App\Filament\Tables\FilterBar::SEARCH_THRESHOLD)
                                        <input
                                            type="search"
                                            class="og-filter-input og-filter-search"
                                            placeholder="{{ __('Search') }}"
                                            aria-label="{{ __('Search :name', ['name' => $chip['label']]) }}"
                                            x-model="q"
                                        >
                                    @endif

                                    <div class="og-filter-options">
                                        @unless ($chip['multiple'])
                                            <label class="og-filter-option" x-show="q === ''">
                                                <input type="radio" name="og-filter-{{ $chip['name'] }}" value="" x-bind:checked="String($wire.get(@js($path.'.value')) ?? '') === ''" x-on:change="$wire.$set(@js($path.'.value'), null)">
                                                <span class="og-filter-option-label">{{ __('All') }}</span>
                                            </label>
                                        @endunless

                                        @forelse ($chip['options'] as $value => $option)
                                            <label
                                                class="og-filter-option"
                                                wire:key="og-filter-{{ $chip['name'] }}-{{ $value }}"
                                                x-show="q === '' || @js(mb_strtolower($option['label'])).includes(q.toLowerCase())"
                                            >
                                                @if ($chip['multiple'])
                                                    <input type="checkbox" value="{{ $value }}" wire:model.live="{{ $path }}.values">
                                                @else
                                                    <input type="radio" name="og-filter-{{ $chip['name'] }}" value="{{ $value }}" x-bind:checked="String($wire.get(@js($path.'.value')) ?? '') === @js((string) $value)" x-on:change="$wire.$set(@js($path.'.value'), @js((string) $value))">
                                                @endif
                                                <span class="og-filter-option-label">{{ $option['label'] }}</span>
                                                @if ($option['count'] !== null)
                                                    <span class="og-filter-option-count">{{ \Illuminate\Support\Number::format($option['count']) }}</span>
                                                @endif
                                            </label>
                                        @empty
                                            <p class="og-filter-empty">{{ __('Nothing to choose yet.') }}</p>
                                        @endforelse
                                    </div>

                                    @if ($chip['limited'])
                                        <p class="og-filter-empty">{{ __('Only the :count most used are listed.', ['count' => \App\Filament\Tables\FilterBar::OPTION_LIMIT]) }}</p>
                                    @endif
                                    @break

                                @case('date')
                                    <div class="og-filter-presets">
                                        @foreach ($chip['presets'] as $preset)
                                            @php
                                                $isPreset = ($chip['state']['from'] ?? null) === $preset['from'] && ($chip['state']['until'] ?? null) === $preset['until'];
                                            @endphp
                                            <button
                                                type="button"
                                                class="og-filter-pill @if ($isPreset) is-active @endif"
                                                aria-pressed="{{ $isPreset ? 'true' : 'false' }}"
                                                x-on:click="$wire.$set(@js($path.'.from'), @js($preset['from']), false); $wire.$set(@js($path.'.until'), @js($preset['until']))"
                                            >{{ $preset['label'] }}</button>
                                        @endforeach
                                    </div>

                                    <div class="og-filter-dates">
                                        <label class="og-filter-field">
                                            <span>{{ __('From') }}</span>
                                            <input type="date" class="og-filter-input" wire:model.live="{{ $path }}.from">
                                        </label>
                                        <label class="og-filter-field">
                                            <span>{{ __('To') }}</span>
                                            <input type="date" class="og-filter-input" wire:model.live="{{ $path }}.until">
                                        </label>
                                    </div>
                                    @break

                                @case('text')
                                    <label class="og-filter-field">
                                        <span>{{ __(':label contains', ['label' => $chip['label']]) }}</span>
                                        <input
                                            type="text"
                                            class="og-filter-input"
                                            wire:model.live.debounce.400ms="{{ $path }}.value"
                                            x-effect="if (open === @js($chip['name'])) $nextTick(() => $el.focus())"
                                        >
                                    </label>
                                    @break
                            @endswitch
                        </div>

                        <div class="og-filter-popover-footer">
                            <button type="button" class="og-filter-done" x-on:click="open = null">
                                @if ($resultCount !== null)
                                    {{ trans_choice('Show :count result|Show :count results', $resultCount, ['count' => \Illuminate\Support\Number::format($resultCount)]) }}
                                @else
                                    {{ __('Done') }}
                                @endif
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach

        @if ($hasMore)
            <div class="og-filter-chip-wrap">
                <button
                    type="button"
                    class="og-filter-chip is-ghost"
                    aria-haspopup="menu"
                    x-bind:aria-expanded="more"
                    x-on:click="open = null; more = ! more"
                >
                    <x-filament::icon icon="heroicon-m-plus" class="og-filter-chip-icon" />
                    {{ __('More filters') }}
                </button>

                <div class="og-filter-backdrop" x-show="more" x-cloak x-on:click="more = false"></div>

                <div class="og-filter-popover og-filter-more-menu" role="menu" x-show="more" x-cloak x-on:click.outside="more = false">
                    <div class="og-filter-popover-header">
                        <span class="og-filter-popover-title">{{ __('More filters') }}</span>
                    </div>
                    <div class="og-filter-options">
                        @foreach ($chips as $chip)
                            @continue($chip['primary'])
                            <button
                                type="button"
                                role="menuitem"
                                class="og-filter-option"
                                @if ($chip['kind'] === 'toggle')
                                    x-on:click.stop="more = false; $wire.$set(@js('tableFilters.'.$chip['name'].'.isActive'), {{ $chip['active'] ? 'false' : 'true' }})"
                                @else
                                    x-on:click.stop="more = false; open = @js($chip['name'])"
                                @endif
                            >
                                <span class="og-filter-option-label">{{ $chip['label'] }}</span>
                                @if ($chip['active'])
                                    <x-filament::icon icon="heroicon-m-check" class="og-filter-chip-icon" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if ($activeCount > 0)
            <button type="button" class="og-filter-link og-filter-reset" wire:click="removeTableFilters">
                {{ __('Reset all') }}
            </button>
        @endif
    </div>
</div>
