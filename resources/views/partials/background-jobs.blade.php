{{--
    Starts the background jobs from the visitor's browser while Settings →
    Background jobs is "AJAX" (App\Support\BackgroundJobs). Only printed when
    a pass is due; the beacon does not hold up the page.
--}}
@if (\App\Support\BackgroundJobs::ajaxDue())
    <script>navigator.sendBeacon?.(@js(route('background-jobs.ajax')))</script>
@endif
