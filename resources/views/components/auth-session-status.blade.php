@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-og bg-surface px-4 py-3 text-sm text-ink']) }} role="status">
        {{ $status }}
    </div>
@endif
