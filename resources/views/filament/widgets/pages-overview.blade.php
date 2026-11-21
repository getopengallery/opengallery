<x-filament-widgets::widget>
    <x-filament::section :heading="__('Pages')" :description="__('Published: :published. Drafts: :drafts. For members only: :members.', ['published' => $published, 'drafts' => $drafts, 'members' => $membersOnly])">
        @if ($recent->isEmpty())
            <p class="og-muted">{{ __('No pages yet.') }}</p>
        @else
            <h3 class="og-subheading og-subheading-first">{{ __('Recently edited') }}</h3>
            <ul class="og-list">
                @foreach ($recent as $page)
                    <li>
                        <span class="og-row-main">
                            <a href="{{ \App\Filament\Resources\Pages\PageResource::getUrl('edit', ['record' => $page]) }}" class="og-link">{{ $page->title }}</a>
                            @unless ($page->is_published)
                                <x-filament::badge color="gray" size="sm">{{ __('Draft') }}</x-filament::badge>
                            @endunless
                        </span>
                        <time class="og-muted" datetime="{{ $page->updated_at->toIso8601String() }}">{{ \App\Support\DateFormat::dateTime($page->updated_at) }}</time>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-slot name="footer">
            <div class="og-section-footer">
                <x-filament::link :href="$pagesUrl">{{ __('All pages') }}</x-filament::link>
                @if ($newPageUrl)
                    <x-filament::button tag="a" :href="$newPageUrl" icon="heroicon-o-document-plus" size="sm">{{ __('New page') }}</x-filament::button>
                @endif
            </div>
        </x-slot>
    </x-filament::section>
</x-filament-widgets::widget>
