{{-- Prepares the update in portions, then hands over to the standalone updater (App\Support\Updates\UpdateInstaller). --}}
<div
    wire:ignore
    x-data="{
        percent: 0,
        phase: 'downloading',
        error: null,
        labels: @js([
            'downloading' => __('Downloading the release'),
            'unpacking' => __('Unpacking the release'),
            'ready' => __('Starting the update'),
            'failed' => __('The update could not be prepared. Nothing was changed.'),
        ]),
        async run() {
            try {
                for (;;) {
                    const result = await $wire.advanceInstall();
                    if (result.error) { this.error = result.error; return; }
                    this.phase = result.phase;
                    this.percent = result.percent;
                    if (result.phase === 'ready') { break; }
                }
                const handover = await $wire.handOverInstall();
                if (handover.error) { this.error = handover.error; return; }
                const form = document.createElement('form');
                form.method = 'post';
                form.action = handover.url;
                const token = document.createElement('input');
                token.type = 'hidden';
                token.name = 'og_update_token';
                token.value = handover.token;
                form.append(token);
                document.body.append(form);
                form.submit();
            } catch (exception) {
                this.error = this.labels.failed;
            }
        },
    }"
    x-init="run()"
    class="og-update-progress"
>
    <template x-if="! error">
        <div>
            <p class="og-muted" role="status" x-text="labels[phase] + ' (' + percent + ' %)'"></p>
            <div class="og-update-bar"><span :style="{ width: percent + '%' }"></span></div>
            <p class="og-note">{{ __('The gallery stays online during this step. Keep this page open.') }}</p>
        </div>
    </template>
    <template x-if="error">
        <div>
            <p role="alert" x-text="error"></p>
            <button type="button" class="og-link" x-on:click="window.location.reload()">{{ __('Back') }}</button>
        </div>
    </template>
</div>
