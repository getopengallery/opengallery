{{-- One entry of a guide in the documentation navigation: a doc, or a folder that folds open (open while it holds the current doc). --}}
@if ($item['type'] === 'folder')
    <li class="og-docs-folder">
        {{-- wire:ignore.self: sections someone opened stay open when another doc is loaded. --}}
        <details wire:ignore.self @if (str_starts_with($current, $item['folder'].'/')) open @endif>
            <summary>
                <span>{{ $item['title'] }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="og-docs-folder-icon" />
            </summary>
            <ul class="og-docs-tree">
                @if ($item['key'])
                    @include('filament.partials.documentation-nav-link', ['key' => $item['key'], 'label' => __('Overview'), 'current' => $current, 'outline' => $outline])
                @endif
                @foreach ($item['children'] as $child)
                    @include('filament.partials.documentation-nav-item', ['item' => $child, 'current' => $current, 'outline' => $outline])
                @endforeach
            </ul>
        </details>
    </li>
@else
    @include('filament.partials.documentation-nav-link', ['key' => $item['key'], 'label' => $item['title'], 'current' => $current, 'outline' => $outline])
@endif
