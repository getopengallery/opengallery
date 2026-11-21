<x-filament-widgets::widget>
    <x-filament::section :heading="__('Members')" :description="trans_choice('{0} Nobody joined in the last :days days.|{1} :count person joined in the last :days days.|[2,*] :count people joined in the last :days days.', $joined, ['days' => $days])">
        <x-slot name="afterHeader">
            <x-filament::link :href="$usersUrl">{{ __('All users') }}</x-filament::link>
        </x-slot>

        @if ($todos === [])
            <p class="og-quiet"><x-filament::icon icon="heroicon-o-check-circle" class="og-todo-icon" />{{ __('Nobody is waiting for you.') }}</p>
        @else
            <ul class="og-todo">
                @foreach ($todos as $todo)
                    <li>
                        <x-filament::icon :icon="$todo['icon']" class="og-todo-icon is-{{ $todo['tone'] }}" />
                        <span class="og-todo-text">{{ $todo['text'] }}</span>
                        @if ($todo['url'])
                            <x-filament::link :href="$todo['url']">{{ $todo['link'] }}</x-filament::link>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($users->isNotEmpty())
            <h3 class="og-subheading">{{ __('Newest members') }}</h3>
            <ul class="og-list">
                @foreach ($users as $member)
                    <li>
                        <span class="og-row-main">
                            @if (in_array($member->id, $editable, true))
                                <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('edit', ['record' => $member]) }}" class="og-link">{{ $member->name }}</a>
                            @else
                                {{ $member->name }}
                            @endif
                            @if ($member->awaiting_approval)
                                <x-filament::badge color="warning" size="sm">{{ __('Waiting for approval') }}</x-filament::badge>
                            @endif
                        </span>
                        <time class="og-muted" datetime="{{ $member->created_at->toIso8601String() }}">{{ \App\Support\DateFormat::date($member->created_at) }}</time>
                    </li>
                @endforeach
            </ul>
            @if ($joined > 0)
                <p class="og-note"><x-filament::link :href="$joinedUrl">{{ __('Everyone who joined recently') }}</x-filament::link></p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
