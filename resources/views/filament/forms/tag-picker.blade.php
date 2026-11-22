{{--
    TagPicker: an input with a dropdown of the existing tags, and the chosen
    tags below it. Typing narrows the dropdown and also finds close spellings
    ("pet1" shows "Pet 1"), so people reuse a tag instead of creating a variant.
    Enter takes the highlighted row, otherwise what was typed. Text that was
    not confirmed is discarded on leaving the field, so no stray tags appear.
--}}
@php
    $statePath = $getStatePath();
    $disabled = $isDisabled();
    $inputId = $getId();
    $listId = $inputId.'-options';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Livewire must not redraw this: it would evaluate the list before the state is connected. --}}
    <div
        class="og-tags"
        wire:ignore
        wire:key="tag-picker-{{ $statePath }}-{{ $disabled ? 'read-only' : 'editable' }}"
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            known: @js($getSuggestions()),
            allowsNew: @js($allowsNewTags()),
            typed: '',
            open: false,
            active: -1,
            notice: '',
            clean(name) { return name.replace(/[\p{C}\s]+/gu, ' ').trim() },
            same(name) { return this.clean(name).toLocaleLowerCase() },
            loose(name) { return name.toLocaleLowerCase().replace(/[^\p{L}\p{M}\p{N}]+/gu, '') },
            get chosen() { return Array.isArray(this.state) ? this.state : [] },
            has(name) { return this.chosen.some((tag) => this.same(tag) === this.same(name)) },
            rank(name, key) {
                const candidate = this.loose(name)
                if (candidate === key) return 0
                if (candidate.startsWith(key)) return 1
                if (candidate.includes(key)) return 2
                return candidate.length > 1 && key.includes(candidate) ? 3 : 4
            },
            get matches() {
                const key = this.loose(this.typed)
                const available = this.known.filter((name) => ! this.has(name))
                if (key === '') return this.clean(this.typed) === '' ? available : []
                return available
                    .map((name) => [this.rank(name, key), name])
                    .filter(([rank]) => rank < 4)
                    .sort((a, b) => a[0] - b[0])
                    .map(([, name]) => name)
            },
            get canCreate() {
                const tag = this.clean(this.typed)
                return this.allowsNew && this.loose(tag) !== '' && ! this.has(tag)
                    && ! this.known.some((name) => this.same(name) === this.same(tag))
            },
            get rows() {
                const rows = this.matches.map((name) => ({ key: 'tag:' + name, name, isNew: false }))
                return this.canCreate ? [...rows, { key: 'new', name: this.clean(this.typed), isNew: true }] : rows
            },
            get emptyText() {
                const tag = this.clean(this.typed)
                {{-- Blade drops the line break after a directive, so these lines need their semicolons. --}}
                if (tag === '') return this.allowsNew ? @js(__('No tags yet. Type to create one.')) : @js(__('No predefined tags yet.'));
                if (this.has(tag)) return @js(__('Already added.'));
                if (! this.allowsNew) return @js(__('No predefined tag matches.'));
                return @js(__('Tags need at least one letter or number.'));
            },
            parts(name) {
                const needle = this.typed.trim().toLocaleLowerCase()
                const lower = name.toLocaleLowerCase()
                const at = needle === '' ? -1 : lower.indexOf(needle)
                if (at < 0 || lower.length !== name.length) return [name, '', '']
                return [name.slice(0, at), name.slice(at, at + needle.length), name.slice(at + needle.length)]
            },
            createLabel(name) { return @js(__('Create tag ":tag"', ['tag' => ':tag'])).replace(':tag', () => name) },
            optionId(index) { return @js($listId) + '-' + index },
            add(name) {
                const tag = this.clean(name)
                if (tag === '' || this.has(tag)) return
                const existing = this.known.find((known) => this.same(known) === this.same(tag))
                if (! existing && ! this.allowsNew) {
                    this.notice = @js(__('":tag" is not one of the predefined tags.', ['tag' => ':tag'])).replace(':tag', () => tag)
                    return
                }
                this.state = [...this.chosen, existing ?? tag]
            },
            close() {
                this.open = false
                this.active = -1
            },
            commit() {
                this.typed.split(',').forEach((name) => this.add(name))
                this.typed = ''
                this.close()
            },
            choose(row) {
                this.add(row.name)
                this.typed = ''
                this.notice = ''
                this.close()
            },
            remove(name) { this.state = this.chosen.filter((tag) => tag !== name) },
            typing() {
                this.notice = ''
                this.open = true
                this.active = ! this.allowsNew && this.typed.trim() !== '' && this.matches.length ? 0 : -1
                if (this.typed.includes(',')) this.commit()
            },
            move(step) {
                if (! this.open) {
                    this.open = true
                    return
                }
                const count = this.rows.length
                if (count === 0) return
                this.active = this.active < 0 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count
                this.$nextTick(() => this.$refs.options?.querySelector('[aria-selected=true]')?.scrollIntoView({ block: 'nearest' }))
            },
            keydown(event) {
                if (event.isComposing) return
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault()
                    this.move(event.key === 'ArrowDown' ? 1 : -1)
                } else if (event.key === 'Enter') {
                    event.preventDefault()
                    event.stopPropagation()
                    const row = this.open ? this.rows[this.active] : null
                    row ? this.choose(row) : this.commit()
                } else if (event.key === 'Escape' && this.open) {
                    event.preventDefault()
                    event.stopPropagation()
                    this.close()
                }
            },
            leave(event) {
                this.close()
                if (! this.$root.contains(event.relatedTarget)) this.typed = ''
            },
        }"
        @unless ($disabled)
            x-on:focusout="leave($event)"
        @endunless
    >
        @unless ($disabled)
            <div class="og-tags-combobox">
                <x-filament::input.wrapper>
                    <input
                        id="{{ $inputId }}"
                        type="text"
                        class="fi-input"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="{{ $listId }}"
                        :aria-expanded="open ? 'true' : 'false'"
                        :aria-activedescendant="active >= 0 ? optionId(active) : null"
                        autocomplete="off"
                        maxlength="{{ \App\Models\Tag::MAX_LENGTH }}"
                        placeholder="{{ $allowsNewTags() ? __('Search or add a tag') : __('Choose a tag') }}"
                        x-model="typed"
                        x-on:focus="open = true"
                        x-on:click="open = true"
                        x-on:input="typing()"
                        x-on:keydown="keydown($event)"
                    >
                </x-filament::input.wrapper>

                <div class="og-tags-dropdown" x-show="open" x-cloak x-on:mousedown.prevent>
                    <ul id="{{ $listId }}" x-ref="options" role="listbox" aria-label="{{ __('Tags') }}" x-show="rows.length">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <li
                                role="option"
                                class="og-tags-option"
                                :id="optionId(index)"
                                :class="{ 'is-active': index === active, 'is-new': row.isNew }"
                                :aria-selected="index === active ? 'true' : 'false'"
                                x-on:mousemove="active = index"
                                x-on:click="choose(row)"
                            >
                                <span x-show="row.isNew" class="og-tags-option-icon"><x-filament::icon icon="heroicon-m-plus" /></span>
                                <span x-show="row.isNew" x-text="createLabel(row.name)"></span>
                                <span x-show="! row.isNew" class="og-tags-option-name"><span x-text="parts(row.name)[0]"></span><mark x-text="parts(row.name)[1]"></mark><span x-text="parts(row.name)[2]"></span></span>
                            </li>
                        </template>
                    </ul>
                    <p class="og-tags-empty" x-show="! rows.length" x-text="emptyText"></p>
                </div>
            </div>

            <p class="og-tags-hint" role="status" x-show="notice" x-text="notice" x-cloak></p>
        @endunless

        <ul class="og-tags-list" role="list" x-show="chosen.length" x-cloak>
            <template x-for="tag in chosen" :key="tag">
                <li class="og-tag">
                    <span x-text="tag"></span>
                    @unless ($disabled)
                        <button type="button" class="og-tag-remove" x-on:mousedown.prevent x-on:click="remove(tag)" :aria-label="@js(__('Remove :title', ['title' => ':title'])).replace(':title', () => tag)">
                            <x-filament::icon icon="heroicon-m-x-mark" />
                        </button>
                    @endunless
                </li>
            </template>
        </ul>

        @if ($disabled)
            <p class="og-tags-hint" x-show="! chosen.length" x-cloak>{{ __('No tags') }}</p>
        @endif
    </div>
</x-dynamic-component>
