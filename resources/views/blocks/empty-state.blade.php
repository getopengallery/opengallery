{{-- Block: empty-state. Props: message, (optional) slot for an action. --}}
<div class="rounded-og bg-surface px-6 py-14 text-center">
    <p class="mx-auto max-w-md text-muted">{{ $message }}</p>
    @if (! $slot->isEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
