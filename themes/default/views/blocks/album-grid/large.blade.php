{{-- Block: album-grid, variant "large". Two albums per row with bigger covers. Chosen with the "Album tiles" setting. Props: albums. --}}
<ul role="list" class="grid grid-cols-1 gap-x-8 gap-y-12 sm:grid-cols-2">
    @foreach ($albums as $album)
        <li><x-block name="album-tile" :album="$album" /></li>
    @endforeach
</ul>
