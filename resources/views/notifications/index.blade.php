{{--
    Notifications page (NotificationController): the same notifications as the
    bell in the admin. Ones that were unread when the page opened are marked.
--}}
<x-app-layout :meta="\App\Support\PageMeta::hidden()">
    <x-slot:title>{{ __('Notifications') }}</x-slot:title>

    <x-slot name="header">
        <h1 class="text-heading font-semibold">{{ __('Notifications') }}</h1>
    </x-slot>

    <div class="og-container max-w-3xl">
        @if ($items->isEmpty())
            <x-block name="empty-state" :message="__('Nothing here yet. You are told here when an album is shared with you and when something changes on your account.')" />
        @else
            <ul class="divide-y divide-line rounded-og border border-line">
                @foreach ($items as $item)
                    <li @class(['bg-surface' => $item['unread']])>
                        @if ($item['message']->url)
                            <a href="{{ $item['message']->url }}" class="flex gap-3 px-4 py-4 hover:bg-surface sm:px-5">
                        @else
                            <div class="flex gap-3 px-4 py-4 sm:px-5">
                        @endif
                            <span @class([
                                'mt-2 h-2 w-2 shrink-0 rounded-full',
                                'bg-primary' => $item['unread'],
                                'bg-transparent' => ! $item['unread'],
                            ])></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-ink">
                                    @if ($item['unread'])
                                        <span class="sr-only">{{ __('New') }}: </span>
                                    @endif
                                    {{ $item['message']->title }}
                                </span>
                                @if ($item['message']->body)
                                    <span class="mt-0.5 block text-muted">{{ $item['message']->body }}</span>
                                @endif
                                <time datetime="{{ $item['at']->toIso8601String() }}" class="mt-1 block text-caption text-muted">
                                    {{ \App\Support\DateFormat::dateTime($item['at']) }}
                                </time>
                            </span>
                        @if ($item['message']->url)
                            </a>
                        @else
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-10">{{ $notifications->links() }}</div>

            <p class="mt-6 text-caption text-muted">
                {{ trans_choice('Notifications are kept for :count day.|Notifications are kept for :count days.', \App\Support\Inbox::KEEP_DAYS) }}
            </p>
        @endif
    </div>
</x-app-layout>
