{{-- Themes list: the first colours of a theme's palette (Themes::table()). --}}
<div class="og-swatches px-3 py-2" aria-hidden="true">
    @foreach ($getRecord()['swatches'] as $hex)
        <span style="--swatch: {{ $hex }}"></span>
    @endforeach
</div>
