<x-filament-widgets::widget>
    <x-filament::section :heading="__('Site health')">
        @if ($problems === [])
            <p class="og-quiet">
                <x-filament::icon icon="heroicon-o-check-circle" class="og-todo-icon" />
                {{ trans_choice('{1} The check passed.|[2,*] All :count checks passed.', $checked) }}
            </p>
        @else
            <ul class="og-todo">
                @foreach ($problems as $problem)
                    @php($info = \App\Filament\Widgets\SiteHealth::describe($problem))
                    <li>
                        <x-filament::icon
                            :icon="match ($problem['severity']) { 'danger' => 'heroicon-o-exclamation-circle', 'warning' => 'heroicon-o-exclamation-triangle', default => 'heroicon-o-information-circle' }"
                            class="og-todo-icon is-{{ $problem['severity'] }}"
                        />
                        <span class="og-todo-text">
                            <strong><span class="og-sr-only">{{ $info['severity'] }}: </span>{{ $info['title'] }}</strong>
                            <span class="og-muted">{{ $info['text'] }}</span>
                            <a href="{{ $info['url'] }}" class="og-link">{{ $info['link'] }}</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
