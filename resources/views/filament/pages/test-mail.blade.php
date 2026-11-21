{{-- One e-mail of the test mailbox. The HTML runs in a sandbox without scripts; links open in a new tab. --}}
<div class="og-mail">
    <dl class="og-mail-meta">
        <dt>{{ __('From') }}</dt>
        <dd>{{ $mail->from ?: '–' }}</dd>
        <dt>{{ __('To') }}</dt>
        <dd>{{ $mail->to }}</dd>
        <dt>{{ __('Sent') }}</dt>
        <dd>{{ \App\Support\DateFormat::dateTime($mail->created_at) }}</dd>
    </dl>

    <x-filament::callout
        icon="heroicon-o-information-circle"
        color="info"
        :description="__('Links in this e-mail work. To act as the recipient (for example to accept an invitation), open them in a private window.')"
    />

    @if (filled($mail->html))
        <iframe
            title="{{ $mail->subject }}"
            sandbox="allow-popups allow-popups-to-escape-sandbox"
            srcdoc="{{ '<base target="_blank">'.$mail->html }}"
            class="og-mail-frame"
        ></iframe>
    @else
        <pre class="og-mail-frame og-mail-text">{{ $mail->text }}</pre>
    @endif
</div>
