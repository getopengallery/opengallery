{{-- Block: album-grid. Props: albums (iterable of Album with photos_count). --}}
<ul role="list" class="grid grid-cols-2 gap-x-5 gap-y-9 sm:grid-cols-3 lg:grid-cols-4">
    @foreach ($albums as $album)
        <li><x-block name="album-tile" :album="$album" /></li>
    @endforeach
</ul>
