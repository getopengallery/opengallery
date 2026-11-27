{{--
    Makes the results of the admin's global search clickable in every browser.

    Filament closes the results when the search field loses focus. Safari (and
    Firefox on macOS) do not focus a link on click, so pressing the mouse on a
    result blurred the field, the list was hidden before the button was
    released, and the click never reached the link. Keeping the focus in the
    field while a result is pressed lets the click go through; Filament then
    closes the list itself and opens the result.

    Inline (like theme-styles) so it works without rebuilding the frontend.
--}}
<script data-navigate-once>
    document.addEventListener('mousedown', (event) => {
        if (event.button !== 0 || ! (event.target instanceof Element)) {
            return
        }

        if (event.target.closest('.fi-global-search-results-ctn a[href], .fi-global-search-results-ctn button')) {
            event.preventDefault()
        }
    }, true)
</script>
