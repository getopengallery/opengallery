{{--
    ImageUpload: one standalone image. The box keeps its size in every state,
    so nothing moves while an upload starts or ends. Logic: Alpine component
    "ogImageUpload" (filament.partials.image-upload-script).
--}}
@php
    $id = $field->getId();
    $statePath = $field->getStatePath();
    $preview = $field->getPreviewStyle();
    $isDisabled = $field->isDisabled();
    $title = (string) $field->getLabel();
    $config = $field->getUploadConfig();
    $firstFile = collect($config['files'])->filter()->first();
    $chip = $config['chip'];
    $browsePrompt = __('Drop an image or :browse');
    $isWide = $preview === \App\Enums\ImagePreview::Wide;
    $isSquare = $preview === \App\Enums\ImagePreview::Square;
@endphp

<div
    id="{{ $id }}"
    role="group"
    aria-labelledby="{{ $id }}-label"
    wire:ignore
    wire:key="{{ $field->getLivewireKey() }}.image-upload.{{ $isDisabled ? 'disabled' : 'enabled' }}"
    x-data="ogImageUpload({ state: $wire.{{ $field->applyStateBindingModifiers("\$entangle('{$statePath}')") }}, ...@js($config) })"
    x-on:og-image-upload-saved.window="saved($event.detail)"
    x-on:paste="pasted($event)"
    class="og-iu is-{{ $preview->value }}"
>
    <input
        x-ref="input"
        type="file"
        class="og-sr-only"
        tabindex="-1"
        accept="{{ implode(',', $config['accepted']) }}"
        aria-labelledby="{{ $id }}-label"
        x-on:change="pick($event.target.files)"
        @disabled($isDisabled)
    />

    <div
        @class(['og-iu-frame', 'is-empty' => ! $firstFile, 'is-disabled' => $isDisabled])
        x-bind:class="{ 'is-empty': ! hasImage, 'is-dragging': isDragging, 'is-failed': Boolean(error) }"
        x-on:dragover.prevent="dragOver()"
        x-on:dragleave="dragLeave($event)"
        x-on:drop.prevent="dropped($event)"
    >
        @if ($isDisabled)
            <div class="og-iu-stage og-iu-drop {{ $isWide ? 'is-wide' : '' }} {{ $isSquare ? 'is-square' : '' }}" x-show="! hasImage" @if ($firstFile) style="display: none" @endif>
                <span class="og-iu-prompt">{{ __('No image') }}</span>
            </div>
        @else
            <button
                type="button"
                class="og-iu-stage og-iu-drop {{ $isWide ? 'is-wide' : '' }} {{ $isSquare ? 'is-square' : '' }}"
                aria-label="{{ __('Upload :title', ['title' => $title]) }}"
                x-show="! hasImage"
                x-on:click="$refs.input.click()"
                @if ($firstFile) style="display: none" @endif
            >
                <x-filament::icon icon="heroicon-o-arrow-up-tray" />
                <span class="og-iu-prompt" x-show="isDragging" style="display: none">{{ __('Drop to upload') }}</span>
                <span class="og-iu-prompt" x-show="! isDragging">
                    <span class="og-iu-prompt-pointer">{{ \Illuminate\Support\Str::before($browsePrompt, ':browse') }}<span class="og-iu-link">{{ __('browse') }}</span>{{ \Illuminate\Support\Str::after($browsePrompt, ':browse') }}</span>
                    <span class="og-iu-prompt-touch og-iu-link">{{ __('Choose an image') }}</span>
                </span>
            </button>
        @endif

        <div class="og-iu-stage {{ $preview->stageClasses() }}" x-show="hasImage" @unless ($firstFile) style="display: none" @endunless>
            <img
                class="og-iu-img"
                src="{{ $firstFile['url'] ?? '' }}"
                alt="{{ __('Preview of :title', ['title' => $title]) }}"
                x-bind:src="previewUrl"
                x-bind:class="{ 'is-dim': upload }"
                x-show="! previewFailed"
                x-on:load="measure($event.target)"
                x-on:error="previewFailed = hasImage"
            />
            <span class="og-iu-no-preview" x-show="previewFailed" style="display: none">{{ __('No preview') }}</span>
            <span class="og-iu-flag og-iu-stage-flag" x-show="isUnsaved" style="display: none">{{ __('Not saved yet') }}</span>
            @if ($chip)
                <span class="og-iu-chip" x-bind:class="{ 'is-dim': upload }" x-show="! previewFailed">
                    <img src="{{ $firstFile['url'] ?? '' }}" alt="" x-bind:src="previewUrl" />
                    <span x-text="chipText"></span>
                </span>
            @endif
        </div>

        <div class="og-iu-meta" x-show="hasImage" @unless ($firstFile) style="display: none" @endunless>
            <span
                class="og-iu-progress"
                role="progressbar"
                aria-label="{{ __('Upload :title', ['title' => $title]) }}"
                aria-valuemin="0"
                aria-valuemax="100"
                x-show="upload"
                x-bind:aria-valuenow="upload?.progress ?? 0"
                x-bind:style="{ width: (upload?.progress ?? 0) + '%' }"
                style="display: none"
            ></span>
            <div class="og-iu-file">
                <span class="og-iu-name" x-text="displayName"></span>
                <span class="og-iu-sub">
                    <span x-text="detail"></span>
                    <span class="og-iu-flag og-iu-meta-flag" x-show="isUnsaved" style="display: none">{{ __('Not saved yet') }}</span>
                </span>
            </div>
            @unless ($isDisabled)
                <div class="og-iu-actions">
                    <button type="button" class="og-iu-btn" aria-label="{{ __('Cancel upload of :title', ['title' => $title]) }}" x-show="upload" x-bind:disabled="upload?.isFinishing" x-on:click="cancel()" style="display: none">{{ __('Cancel') }}</button>
                    <button type="button" class="og-iu-btn" aria-label="{{ __('Replace :title', ['title' => $title]) }}" x-show="! upload" x-on:click="$refs.input.click()">{{ __('Replace') }}</button>
                    @if ($config['canRemove'])
                        <button type="button" class="og-iu-btn is-quiet" aria-label="{{ __('Remove :title', ['title' => $title]) }}" x-show="! upload" x-on:click="remove()">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endunless
        </div>

        <div class="og-iu-meta" x-show="! hasImage && ! isRemoved" @if ($firstFile) style="display: none" @endif>
            <span>{{ $field->getFormatsLabel() }}</span>
            @if ($limit = $field->getMaxSizeLabel())
                <span>{{ __('Up to :size', ['size' => $limit]) }}</span>
            @endif
        </div>

        <div class="og-iu-meta has-action" x-show="isRemoved" style="display: none">
            <span>{{ __('Removed when you save') }}</span>
            <button type="button" class="og-iu-btn" aria-label="{{ __('Undo removing :title', ['title' => $title]) }}" x-on:click="undo()">{{ __('Undo') }}</button>
        </div>
    </div>

    <p class="og-iu-error" role="alert" x-show="error" style="display: none">
        <x-filament::icon icon="heroicon-m-exclamation-circle" />
        <span x-text="error"></span>
    </p>
</div>
