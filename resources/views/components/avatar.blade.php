{{-- A user's profile picture, or their initials when they have none. --}}
@props(['user', 'size' => 'md'])

@php
    $url = \App\Support\Avatars::url($user);
    $sizes = ['sm' => 'h-7 w-7 text-[0.6875rem]', 'md' => 'h-10 w-10 text-sm', 'lg' => 'h-20 w-20 text-xl'];
@endphp

<span {{ $attributes->class(['og-avatar', $sizes[$size] ?? $sizes['md']]) }}>
    @if ($url)
        <img src="{{ $url }}" alt="" class="h-full w-full object-cover">
    @else
        <span aria-hidden="true">{{ \App\Support\Avatars::initials($user->name) }}</span>
    @endif
</span>
