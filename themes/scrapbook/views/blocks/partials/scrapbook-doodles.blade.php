{{--
    Scrapbook theme: decorative doodles scattered over a section (stars,
    squiggles, hearts...). Positioned by .sb-doodles in scrapbook.css; the
    parent needs the .sb-doodled class. $flip mirrors the arrangement so
    neighbouring sections do not look the same. Turned off with "Scatter doodles".
--}}
@if (\App\Support\Theme::enabled('doodles'))
    <div @class(['sb-doodles', 'sb-doodles--flip' => $flip ?? false]) aria-hidden="true">
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="currentColor" d="M12 2l2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6z"/></svg>
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M2 12c2.5-6 5-6 6.7 0s4.2 6 6.6 0 4.2-6 6.7 0"/></svg>
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="currentColor" d="M12 21s-7.5-4.6-9.6-9.3C.9 8.2 3.3 4.5 6.9 4.5c2.2 0 3.9 1.3 5.1 3 1.2-1.7 2.9-3 5.1-3 3.6 0 6 3.7 4.5 7.2C19.5 16.4 12 21 12 21z"/></svg>
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 2v6M12 16v6M2 12h6M16 12h6M5 5l3 3M16 16l3 3M19 5l-3 3M8 16l-3 3"/></svg>
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M2 15l4-6 4 6 4-6 4 6 4-6"/></svg>
        <svg viewBox="0 0 24 24" class="sb-doodle"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 12a1 1 0 1 1 1-1 3 3 0 1 1-4 1 5 5 0 1 1 7 4 7 7 0 1 1 4-8"/></svg>
    </div>
@endif
