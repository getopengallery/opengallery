<x-filament-widgets::widget>
    <x-filament::section :heading="__('Recent activity')">
        <x-slot name="afterHeader">
            <x-filament::link :href="\App\Filament\Resources\ActivityEvents\ActivityEventResource::getUrl()">{{ __('Full log') }}</x-filament::link>
        </x-slot>

        @if ($events->isEmpty())
            <p class="og-muted">{{ __('Nothing yet. Logins, album views and changes will show up here.') }}</p>
        @else
            <ul class="og-list">
                @foreach ($events as $event)
                    @php
                        $subject = $event->subjectLabel();
                        $url = $event->subjectUrl();
                        $details = $event->details();
                    @endphp
                    <li>
                        <span class="og-event">
                            <span>
                                @if ($event->user || $event->type->isVisit() === false)
                                    <strong>{{ $event->actorName() }}</strong>
                                @else
                                    {{ $event->actorName() }}
                                @endif
                                @if ($event->type->isSecurity())
                                    <x-filament::badge color="warning" size="sm" class="og-event-flag">{{ __('Security') }}</x-filament::badge>
                                @endif
                                {{ $event->action() }}
                                @if ($subject && $event->showsSubject())
                                    @if ($url)
                                        <a href="{{ $url }}" class="og-link">{{ $subject }}</a>
                                    @else
                                        {{ $subject }}
                                    @endif
                                @endif
                            </span>
                            @foreach ($details as $line)
                                <span class="og-event-details">{{ $line }}</span>
                            @endforeach
                        </span>
                        <time class="og-muted" datetime="{{ $event->created_at->toIso8601String() }}">{{ \App\Support\DateFormat::dateTime($event->created_at) }}</time>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
