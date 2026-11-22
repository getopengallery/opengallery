{{-- Block: search-photos. Photos the site search found by their tags. Props: photos (paginator), query (the search). --}}
<section aria-labelledby="search-photos-heading">
    <h2 id="search-photos-heading" class="mb-2 text-xl font-semibold">{{ __('Photos') }}</h2>
    <p class="mb-6 text-muted">
        {{ trans_choice('{1} :count photo has a tag matching ":query".|[2,*] :count photos have a tag matching ":query".', $photos->total(), ['query' => $query]) }}
    </p>

    <x-block name="photo-grid" :photos="$photos" />

    <div class="mt-14">{{ $photos->links() }}</div>
</section>
