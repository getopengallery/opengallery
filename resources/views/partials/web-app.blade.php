{{--
    The site as an installable app (App\Support\WebApp): manifest, home screen
    icon, browser bar colour and the service worker. Included on the site
    (partials.theme-head) and in the admin (AdminPanelProvider). While the
    app is turned off in Admin → Settings, a service worker installed earlier
    is removed.
--}}
@if (\App\Support\WebApp::enabled())
    @php($webAppColors = \App\Support\WebApp::themeColors())
    <link rel="manifest" href="{{ route('web-app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\WebApp::iconUrl(180) }}">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="{{ $webAppColors['light'] }}">
    <script>
        (() => {
            // The browser bar follows the light / dark mode of the page.
            const meta = document.querySelector('meta[name="theme-color"]');
            const sync = () => meta.content = document.documentElement.classList.contains('dark')
                ? @js($webAppColors['dark']) : @js($webAppColors['light']);
            sync();
            new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

            if ('serviceWorker' in navigator) {
                addEventListener('load', () => navigator.serviceWorker.register(@js(\App\Support\WebApp::serviceWorkerUrl())).catch(() => {}));
            }
        })();
    </script>
@else
    <script>
        navigator.serviceWorker?.getRegistrations().then((registrations) => registrations
            .filter((registration) => {
                const worker = registration.active ?? registration.waiting ?? registration.installing;

                return worker && new URL(worker.scriptURL).pathname.endsWith('/sw.js');
            })
            .forEach((registration) => registration.unregister()));
    </script>
@endif
