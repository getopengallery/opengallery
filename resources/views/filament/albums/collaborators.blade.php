{{-- Body of the Collaborators card on the album edit page. Everything it calls is on the page (ManagesCollaborators). --}}
@php
    $page = $getLivewire();
    $entries = $page->collaboratorEntries();
    $manages = $page->canManageCollaborators();
@endphp

@if ($entries)
    <div class="og-collaborators">
        @if ($entries->total() > \App\Support\AlbumCollaborators::PER_PAGE || filled($page->collaboratorSearch))
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="collaboratorSearch"
                    x-on:keydown.enter.prevent
                    :placeholder="__('Search')"
                    :aria-label="__('Search collaborators')"
                />
            </x-filament::input.wrapper>
        @endif

        @if ($entries->isEmpty())
            <p class="og-collaborators-empty">
                {{ filled($page->collaboratorSearch) ? __('Nothing matches') : __('Nobody works on this album yet') }}
            </p>
        @else
            <ul role="list" class="og-collaborators-list">
                @foreach ($entries as $entry)
                    <li wire:key="collaborator-{{ $entry->key }}" class="og-collaborators-row">
                        <span class="og-collaborators-name">
                            <span>{{ $entry->name }}</span>
                            @if ($entry->type === \App\Support\PeopleAndGroups::GROUP)
                                <x-filament::badge color="gray" size="sm">{{ __('Group') }}</x-filament::badge>
                            @endif
                        </span>

                        @if ($manages && $entry->ownRole)
                            <span class="og-collaborators-controls">
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        :aria-label="__('Role of :name', ['name' => $entry->name])"
                                        wire:change="changeCollaboratorRole('{{ $entry->key }}', $event.target.value)"
                                    >
                                        @foreach (\App\Enums\CollaboratorRole::cases() as $role)
                                            <option value="{{ $role->value }}" @selected($role === $entry->ownRole)>{{ $role->getLabel() }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                {{ ($page->removeCollaboratorAction)(['key' => $entry->key]) }}
                            </span>
                        @else
                            <span class="og-collaborators-controls">
                                <x-filament::badge color="gray">{{ $entry->role->getLabel() }}</x-filament::badge>
                                @unless ($entry->ownRole)
                                    <span class="og-collaborators-note">{{ __('from a parent album') }}</span>
                                @endunless
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($entries->hasPages())
            <nav class="og-collaborators-pages" aria-label="{{ __('Collaborators') }}">
                <x-filament::icon-button
                    icon="heroicon-m-chevron-left"
                    color="gray"
                    size="sm"
                    :disabled="$entries->onFirstPage()"
                    :label="__('Previous')"
                    wire:click="$set('collaboratorPage', {{ $entries->currentPage() - 1 }})"
                />
                <span>{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</span>
                <x-filament::icon-button
                    icon="heroicon-m-chevron-right"
                    color="gray"
                    size="sm"
                    :disabled="! $entries->hasMorePages()"
                    :label="__('Next')"
                    wire:click="$set('collaboratorPage', {{ $entries->currentPage() + 1 }})"
                />
            </nav>
        @endif

        @if ($manages)
            <div>{{ $page->addCollaboratorsAction }}</div>
            <p class="og-collaborators-note">{{ __('Editors can publish the album and, by turning on downloads, reach the original files.') }}</p>
        @endif
    </div>
@endif
