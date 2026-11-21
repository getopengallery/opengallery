<div class="og-picker">
    {{--
        Drop zone: photos (and whole folders) are uploaded in chunks, a few at a
        time, and come back selected below. The uploader keeps its own state, so
        Livewire leaves it alone (wire:ignore). Script: filament.partials.photo-uploader-script.
    --}}
    @if ($uploader = $this->uploaderConfig())
        <div
            wire:ignore
            x-data="ogPhotoUploader(@js($uploader))"
            class="og-uploader"
            x-bind:class="{ 'is-dragging': dragging, 'is-busy': running }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="drop($event)"
        >
            <input
                type="file"
                x-ref="input"
                class="og-sr-only"
                tabindex="-1"
                accept="{{ collect($uploader['extensions'])->map(fn ($ext) => '.'.$ext)->implode(',') }}"
                @if ($uploader['multiple']) multiple @endif
                x-on:change="add($event.target.files); $event.target.value = ''"
            />

            {{-- Both states share one grid cell and only swap visibility, so the box keeps its height. --}}
            <div class="og-uploader-stage">
            <div class="og-uploader-idle">
                <div class="og-uploader-prompt" x-bind:class="{ 'is-off': running }">
                @php
                    $browse = '<button type="button" class="og-uploader-browse" x-on:click="$refs.input.click()">'.e(__('browse')).'</button>';
                @endphp
                <p>
                    {!! $uploader['multiple']
                        ? __('Drop photos or folders here or :browse to upload them', ['browse' => $browse])
                        : __('Drop a photo here or :browse to upload it', ['browse' => $browse]) !!}
                </p>
                <p class="og-uploader-hint">
                    {{ __(':types, up to :mb MB each', [
                        'types' => collect($uploader['extensions'])->reject(fn ($ext) => in_array($ext, ['jpeg', 'jfif', 'heif'], true))->map(fn ($ext) => strtoupper($ext))->implode(', '),
                        'mb' => (int) round($uploader['maxSize'] / 1024 / 1024),
                    ]) }}
                </p>
                </div>

                <div class="og-uploader-live" aria-live="polite">
                    <p x-show="notice" x-cloak class="og-uploader-notice" x-text="notice"></p>
                </div>
            </div>

            <div class="og-uploader-busy is-off" x-bind:class="{ 'is-off': ! running }">
                <div class="og-uploader-status">
                    <span x-text="statusText()"></span>
                    <button type="button" class="og-uploader-browse" x-on:click="cancel()">{{ __('Cancel') }}</button>
                </div>
                <div
                    class="og-uploader-bar"
                    role="progressbar"
                    aria-label="{{ __('Upload progress') }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    x-bind:aria-valuenow="percent()"
                >
                    <span x-bind:style="`width: ${percent()}%`"></span>
                </div>
                <p class="og-uploader-hint og-uploader-current" x-text="active.map((slot) => slot.name).join(', ')"></p>
                <p class="og-uploader-hint">{{ __('You can keep working in this window. Closing it stops the upload.') }}</p>
            </div>
            </div>

            <div x-show="problems.length" x-cloak class="og-uploader-problems">
                <div class="og-uploader-status">
                    <strong x-text="(problemCount === 1 ? @js(__(':count file could not be added')) : @js(__(':count files could not be added'))).replace(':count', problemCount)"></strong>
                    <button type="button" class="og-uploader-browse" x-on:click="problems = []; problemCount = 0">{{ __('Dismiss') }}</button>
                </div>
                <ul>
                    <template x-for="(problem, index) in problems" x-bind:key="index">
                        <li><span x-text="problem.name"></span>: <span x-text="problem.message"></span></li>
                    </template>
                    <li x-show="problemCount > problems.length" x-text="@js(__('and :count more')).replace(':count', problemCount - problems.length)"></li>
                </ul>
            </div>
        </div>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
