{{--
    Search engines and link previews (App\Support\PageMeta). Included in the
    <head> of both site layouts; $meta comes from the view, $pageTitle from
    its title slot. Without a photo the app icon is the preview image.
--}}
@php
    $siteName = config('app.name', 'OpenGallery');
    $metaTitle = $meta->title ?? $pageTitle ?? $siteName;
    $metaImage = $meta->image ?? ['url' => \App\Support\WebApp::iconUrl(512), 'width' => 512, 'height' => 512, 'type' => 'image/png', 'alt' => $siteName];
    $metaPage = request()->integer('page');
    $metaUrl = $meta->url ? $meta->url.($metaPage > 1 ? '?page='.$metaPage : '') : null;
@endphp
@if ($meta->description)
    <meta name="description" content="{{ $meta->description }}">
@endif
<meta name="robots" content="{{ $meta->allowsIndexing() ? 'max-image-preview:large'.(\App\Support\SiteSettings::blocksAiCrawlers() ? ', noai, noimageai' : '') : 'noindex, nofollow' }}">
@if ($metaUrl && $meta->allowsIndexing())
    <link rel="canonical" href="{{ $metaUrl }}">
@endif
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:type" content="{{ $meta->type }}">
<meta property="og:title" content="{{ $metaTitle }}">
@if ($meta->description)
    <meta property="og:description" content="{{ $meta->description }}">
@endif
<meta property="og:url" content="{{ $metaUrl ?? url()->current() }}">
<meta property="og:image" content="{{ $metaImage['url'] }}">
<meta property="og:image:type" content="{{ $metaImage['type'] }}">
@if ($metaImage['width'] && $metaImage['height'])
    <meta property="og:image:width" content="{{ $metaImage['width'] }}">
    <meta property="og:image:height" content="{{ $metaImage['height'] }}">
@endif
<meta property="og:image:alt" content="{{ $metaImage['alt'] }}">
<meta name="twitter:card" content="{{ $meta->image ? 'summary_large_image' : 'summary' }}">
@if ($google = \App\Support\SiteSettings::googleVerification())
    <meta name="google-site-verification" content="{{ $google }}">
@endif
@if ($bing = \App\Support\SiteSettings::bingVerification())
    <meta name="msvalidate.01" content="{{ $bing }}">
@endif
