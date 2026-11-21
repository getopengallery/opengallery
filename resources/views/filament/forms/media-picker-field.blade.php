{{--
    MediaPickerField: chosen photos + the shared media picker.
    Listens to "media-picked" for its own state path and keeps the
    thumbnails client-side, so it also works inside action modals.
--}}
@php
    $statePath = $getStatePath();
    $multiple = $isMultiple();
    $items = $getItems();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:key="media-field-{{ $statePath }}-{{ md5(json_encode($getState())) }}"
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            items: @js($items),
            multiple: @js($multiple),
            set(items) {
                this.items = items
                this.state = this.multiple ? items.map((item) => item.id) : (items[0]?.id ?? null)
            },
            add(picked) {
                if (! this.multiple) return this.set(picked.slice(0, 1))
                const known = new Set(this.items.map((item) => item.id))
                this.set([...this.items, ...picked.filter((item) => ! known.has(item.id))])
            },
            remove(id) { this.set(this.items.filter((item) => item.id !== id)) },
        }"
        x-on:media-picked.window="if ($event.detail.key === @js($statePath)) add($event.detail.items ?? [])"
        @class(['og-mfield', 'is-multiple' => $multiple])
    >
        @if ($multiple)
            <ul class="og-mfield-grid" role="list" x-show="items.length">
                <template x-for="item in items" :key="item.id">
                    <li class="og-mfield-thumb" :style="{ '--r': item.ratio }">
                        <img x-show="item.thumb" :src="item.thumb" alt="" loading="lazy">
                        <span class="og-pick-caption" x-text="item.title || @js(__('Untitled'))"></span>
                        <button type="button" class="og-mfield-remove" x-on:click="remove(item.id)" :aria-label="@js(__('Remove :title', ['title' => ':title'])).replace(':title', item.title || @js(__('Untitled')))" title="{{ __('Remove') }}">
                            <x-filament::icon icon="heroicon-m-x-mark" />
                        </button>
                    </li>
                </template>
            </ul>
            {{-- Both plural forms with ":count" left in, filled in by Alpine. --}}
            <p class="og-mfield-count" x-show="items.length" x-text="(items.length === 1 ? @js(trans_choice(':count photo|:count photos', 1, ['count' => ':count'])) : @js(trans_choice(':count photo|:count photos', 2, ['count' => ':count']))).replace(':count', items.length)"></p>
            <p class="og-mfield-empty" x-show="! items.length">{{ $getEmptyText() }}</p>
        @else
            <div class="og-mfield-frame">
                <template x-if="items[0]?.thumb">
                    <img :src="items[0].thumb" :alt="items[0].title ?? ''">
                </template>
                <p class="og-mfield-empty" x-show="! items.length">{{ $getEmptyText() }}</p>
            </div>
        @endif

        @unless ($isDisabled())
            <div class="og-mfield-actions">
                {{ $getAction('pick') }}

                @unless ($multiple)
                    <x-filament::button color="gray" size="sm" x-show="items.length" x-on:click="set([])">
                        {{ __('Remove') }}
                    </x-filament::button>
                @endunless
            </div>
        @endunless
    </div>
</x-dynamic-component>
