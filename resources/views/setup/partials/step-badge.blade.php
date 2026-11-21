{{-- Number (or tick, once done) of a step in the setup wizard's step list. --}}
<span @class([
    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-caption',
    'bg-primary text-on-primary' => $isCurrent,
    'border border-ink' => $isDone,
    'border border-line' => ! $isCurrent && ! $isDone,
])>
    @if ($isDone)
        <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 8.5l3 3 7-7"/></svg>
        <span class="sr-only">{{ __('Done') }}:</span>
    @else
        {{ $position + 1 }}
    @endif
</span>
