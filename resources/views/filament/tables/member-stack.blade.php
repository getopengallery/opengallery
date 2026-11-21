{{--
    UserStackColumn: users as overlapping pictures, each with its own name as
    a tooltip. Only the first few are loaded (UserStackColumn::withUsers());
    the rest are counted by the database.
--}}
@php
    $record = $getRecord();
    $shown = $record->users;
    $remaining = max(0, (int) $record->users_count - $shown->count());
@endphp

<div class="og-member-stack px-3 py-2">
    @forelse ($shown as $member)
        <img src="{{ filament()->getUserAvatarUrl($member) }}" alt="{{ $member->name }}"
             x-tooltip="{ content: @js($member->name), theme: $store.theme }">
    @empty
        <span class="og-member-empty">{{ __($column->getEmptyText()) }}</span>
    @endforelse

    @if ($remaining > 0)
        <span class="og-member-more" x-tooltip="{ content: @js(__(':count more', ['count' => \Illuminate\Support\Number::format($remaining)])), theme: $store.theme }">+{{ \Illuminate\Support\Number::abbreviate($remaining) }}</span>
    @endif
</div>
