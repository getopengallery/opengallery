{{-- Block: album-grid (Scrapbook theme). A crowded pinboard of polaroid tiles, tilted unless "Tilt photos and albums" is off. Props: albums. --}}
<ul role="list" @class(['sb-board', 'sb-wobble' => \App\Support\Theme::enabled('wobble')])>
    @foreach ($albums as $album)
        <li><x-block name="album-tile" :album="$album" /></li>
    @endforeach
</ul>
