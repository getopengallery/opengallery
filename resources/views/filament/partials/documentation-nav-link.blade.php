{{-- A link to one doc. The open doc also lists its sections, marking the one being read. --}}
<li>
    <a
        href="{{ \App\Filament\Pages\Documentation::getUrl(['doc' => $key]) }}"
        class="og-docs-link"
        @if ($key === $current) aria-current="page" @endif
    >{{ $label }}</a>

    @if ($key === $current && $outline !== [])
        <ul class="og-docs-outline" aria-label="{{ __('On this page') }}">
            @foreach ($outline as $section)
                <li>
                    <a
                        href="#{{ $section['id'] }}"
                        x-bind:aria-current="section === @js($section['id']) ? 'location' : null"
                        x-on:click="open = false"
                    >{{ $section['title'] }}</a>
                </li>
            @endforeach
        </ul>
    @endif
</li>
