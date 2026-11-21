{{-- Block: page-header (Scrapbook theme). The page title gets a highlighter stripe and doodles around it. --}}
<div class="og-container sb-page-header sb-doodled">
    @include('blocks.partials.scrapbook-doodles')

    <div>{{ $slot }}</div>
</div>
