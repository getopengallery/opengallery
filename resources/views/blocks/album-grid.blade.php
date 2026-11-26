{{-- Block: album-grid. Props: albums (iterable of Album with photos_count). Slot: extra <li> tiles shown before the albums (e.g. favorites-tile). --}}
<ul role="list" class="grid grid-cols-2 gap-x-5 gap-y-9 sm:grid-cols-3 lg:grid-cols-4">
    {{ $slot ?? '' }}
    @foreach ($albums as $album)
        <li><x-block name="album-tile" :album="$album" /></li>
    @endforeach
</ul>
