{{--
    Aligns the Filament admin with the site's design system: the active
    theme's tokens are injected here too (same .dark switch), and primary
    buttons follow the site's ink-on-paper rule, which flips in dark mode.
--}}
<style>
    {!! app(\App\Extensions\ThemeManager::class)->cssVariables() !!}

    /* Corners follow the theme ("Corners" setting / --og-radius); Filament's scale is derived from it. */
    :root {
        --radius-sm: calc(var(--og-radius, 6px) * 0.5);
        --radius-md: calc(var(--og-radius, 6px) * 0.75);
        --radius-lg: var(--og-radius, 6px);
        --radius-xl: calc(var(--og-radius, 6px) * 1.5);
    }

    .dark .fi-btn.fi-color-primary:not(.fi-outlined) {
        --dark-bg: rgb(var(--og-primary)) !important;
        --dark-hover-bg: rgb(var(--og-primary) / 0.85) !important;
        --dark-text: rgb(var(--og-on-primary)) !important;
        --dark-hover-text: rgb(var(--og-on-primary)) !important;
    }

    /*
     * Custom admin UI. Cards, tabs, badges, callouts and empty states are
     * Filament's own components (x-filament::section, ::tabs, ::badge, ...);
     * these rules only cover what Filament has no component for. Colours come
     * from the theme tokens (--og-*) or Filament's palettes (--gray-*, set by
     * AdminTheme), never hex, except white/black overlays on photos.
     * Filament ships precompiled CSS, so these are plain rules.
     */

    .og-muted { color: rgb(var(--og-muted, 107 107 107)); font-size: 0.875rem; }
    .og-note { margin-top: 1rem; font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-num { font-variant-numeric: tabular-nums; }
    .og-link { text-decoration: underline; text-decoration-color: rgb(var(--og-line, 227 227 227)); text-underline-offset: 3px; }
    .og-link:hover { text-decoration-color: currentColor; }
    .og-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .og-sidebar-version { padding: 0.75rem 1.5rem; font-size: 0.75rem; }
    .og-update-bar { height: 6px; margin-top: 0.5rem; overflow: hidden; border-radius: 3px; background: rgb(var(--og-line, 227 227 227)); }
    .og-update-bar span { display: block; height: 100%; background: rgb(var(--og-primary)); transition: width 0.3s; }

    /* Brand mark in the top bar (filament.partials.brand) */
    .og-brand { display: inline-flex; align-items: center; gap: 0.625rem; height: 100%; }
    .og-brand-logo { height: 100%; width: auto; max-width: 12rem; object-fit: contain; }
    .og-brand-mark { width: 28px; height: 28px; flex-shrink: 0; }
    .og-brand-name { font-size: 1.0625rem; font-weight: 600; letter-spacing: -0.01em; white-space: nowrap; }

    /*
     * Top bar: search in the middle, then the light/dark switch and the
     * account menu (filament.partials.topbar-account), styled like the site
     * header. From lg up the search sits in the true centre of the bar; on
     * narrower screens it is centred in the space left between the sides.
     */
    .fi-topbar { position: relative; }
    .fi-topbar .fi-topbar-end { flex: 1 1 0%; min-width: 0; justify-content: flex-end; gap: 0.5rem; }
    .fi-topbar .fi-global-search-ctn { flex: 0 1 28rem; min-width: 0; margin-inline: auto; }
    .fi-topbar .fi-global-search { width: 100%; }
    @media (min-width: 640px) {
        /* Results open under the field, as wide as the field. */
        .fi-topbar .fi-global-search-results-ctn { inset-inline: 0; width: auto; max-width: none; }
    }
    @media (min-width: 1024px) {
        .fi-topbar .fi-global-search-ctn {
            position: absolute;
            top: 50%;
            left: 50%;
            width: min(28rem, 100% - 36rem);
            transform: translate(-50%, -50%);
        }
        .og-topbar-tools { margin-inline-start: auto; }
    }

    .og-topbar-tools { display: flex; align-items: center; gap: 0.25rem; flex-shrink: 0; }
    .og-topbar-icon-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem;
        border-radius: var(--og-radius, 6px); color: rgb(var(--og-muted, 107 107 107));
    }
    .og-topbar-icon-btn:hover, .og-topbar-icon-btn:focus-visible { background: rgb(var(--og-surface, 243 243 243)); color: rgb(var(--og-ink, 26 26 26)); }
    .og-topbar-icon-btn svg { width: 18px; height: 18px; }
    .og-bell { position: relative; }
    .og-bell-badge {
        position: absolute; top: 0.125rem; inset-inline-end: 0.125rem; min-width: 1rem; height: 1rem; padding: 0 0.25rem;
        border-radius: 9999px; background: rgb(var(--og-primary, 26 26 26)); color: rgb(var(--og-on-primary, 255 255 255));
        font-size: 0.625rem; font-weight: 600; line-height: 1rem; text-align: center;
    }
    .og-mode-dark, .dark .og-mode-light { display: none; }
    .dark .og-mode-dark { display: block; }

    .og-account-trigger {
        display: inline-flex; align-items: center; gap: 0.5rem; max-width: 16rem; padding: 0.375rem 0.25rem 0.375rem 0.375rem;
        border-radius: var(--og-radius, 6px); font-size: 0.875rem; line-height: 1.25rem; font-weight: 500; color: rgb(var(--og-ink, 26 26 26));
    }
    .og-account-trigger:hover, .og-account-trigger:focus-visible, .og-account .fi-dropdown-trigger[aria-expanded='true'] .og-account-trigger { background: rgb(var(--og-surface, 243 243 243)); }
    .og-account-avatar {
        display: inline-flex; flex-shrink: 0; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; overflow: hidden;
        border-radius: 9999px; background: rgb(var(--og-line, 227 227 227)); color: rgb(var(--og-ink, 26 26 26));
        font-size: 0.6875rem; font-weight: 600; line-height: 1; user-select: none;
    }
    .og-account-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .og-account-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .og-account-chevron { width: 1rem; height: 1rem; flex-shrink: 0; color: rgb(var(--og-muted, 107 107 107)); }
    @media (max-width: 639px) {
        .og-account-name, .og-account-chevron { display: none; }
        .og-account-trigger { padding: 0.25rem; }
    }

    /* The account menu's panel, like the site's dropdown (x-dropdown). */
    .fi-dropdown-panel.og-account-menu {
        /* Filament caps the width with a layered !important max-width (14rem), so set the width itself. */
        width: 12rem; padding: 0.25rem; border-radius: var(--og-radius, 6px);
        background: rgb(var(--og-canvas, 255 255 255));
        box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)), 0 10px 15px -3px rgb(0 0 0 / 0.05), 0 4px 6px -4px rgb(0 0 0 / 0.05);
    }
    .og-account-menu .fi-dropdown-list { padding: 0; gap: 0; }
    .og-account-menu .fi-dropdown-list-item { padding: 0.5rem 0.75rem; border-radius: 4px; }
    .og-account-menu .fi-dropdown-list-item .fi-dropdown-list-item-label { color: rgb(var(--og-ink, 26 26 26)); font-weight: 400; }
    .og-account-menu .fi-dropdown-list-item:not(.fi-disabled):not([disabled]):hover,
    .og-account-menu .fi-dropdown-list-item:not(.fi-disabled):not([disabled]):focus-visible { background: rgb(var(--og-surface, 243 243 243)); }

    /* Images and editors set from PHP (PhotosTable, PhotoForm, PhotoInfolist, PageForm, PageMediaBlock) */
    .og-thumb { object-fit: cover; border-radius: var(--radius-md); }
    .og-preview-image { width: 100%; border-radius: var(--radius-lg); }
    .og-preview-image-contained { max-height: 70vh; object-fit: contain; }
    .og-page-editor { min-height: 20rem; }
    .og-block-preview { display: flex; flex-wrap: wrap; gap: 4px; }
    .og-block-preview img { height: 64px; width: auto; border-radius: var(--radius-sm); }
    .og-block-preview-empty { color: rgb(var(--og-muted, 107 107 107)); }

    /* Test mailbox: one e-mail. The frame stays white like a mail client. */
    .og-mail { display: flex; flex-direction: column; gap: 1rem; }
    .og-mail-meta { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.875rem; }
    .og-mail-meta dt { font-weight: 600; }
    .og-mail-meta dd { overflow-wrap: anywhere; }
    .og-mail-frame { width: 100%; height: 65vh; border: 1px solid rgb(var(--og-line, 227 227 227)); border-radius: var(--radius-lg); background: #fff; }
    .og-mail-text { overflow: auto; padding: 1rem; white-space: pre-wrap; font-size: 0.875rem; color: #1a1a1a; }

    /* Ledger: one line of figures separated by rules, not a row of cards. The section's padding is dropped so the rules reach its edges. */
    .og-ledger-section { overflow: hidden; }
    .og-ledger-section .fi-section-content { padding: 0; }
    .og-ledger { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); }
    /* Each item draws its rule on the top/left edge; the panel clips the outer ones, so dividers stay internal however the row wraps. */
    .og-ledger-item {
        display: flex; flex-direction: column; gap: 0.125rem;
        padding: 1.25rem 1.5rem;
        box-shadow: -1px -1px 0 rgb(var(--og-line, 227 227 227));
    }
    a.og-ledger-item:hover .og-ledger-label { color: inherit; }
    .og-ledger-value { font-size: 1.875rem; line-height: 2.25rem; font-weight: 600; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
    .og-ledger-label { font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }

    /* Activity calendar */
    .og-calendar-note { margin-bottom: 1rem; }
    .og-calendar-scroll { overflow-x: auto; padding-bottom: 0.25rem; }
    /* Squares stretch to fill the panel (min. 10px, then the panel scrolls). */
    .og-calendar-grid {
        display: grid;
        grid-template-columns: 2rem repeat(var(--weeks), minmax(10px, 1fr));
        grid-template-rows: 1.25rem;
        grid-auto-rows: auto;
        gap: 3px;
        min-width: calc(2rem + var(--weeks) * 13px);
        font-size: 0.75rem;
        color: rgb(var(--og-muted, 107 107 107));
    }
    .og-calendar-grid .og-cell { width: auto; height: auto; aspect-ratio: 1; }
    .og-calendar-month { grid-row: 1; white-space: nowrap; line-height: 1rem; }
    .og-calendar-weekday { grid-column: 1; align-self: center; font-size: 0.6875rem; line-height: 1; }

    /* Shades run from the theme's rule colour to its primary colour. */
    .og-cell { display: inline-block; width: 11px; height: 11px; border-radius: 2px; background: rgb(var(--og-line, 227 227 227)); }
    .og-cell[data-level='1'] { background: rgb(var(--og-primary, 26 26 26) / 0.25); }
    .og-cell[data-level='2'] { background: rgb(var(--og-primary, 26 26 26) / 0.45); }
    .og-cell[data-level='3'] { background: rgb(var(--og-primary, 26 26 26) / 0.7); }
    .og-cell[data-level='4'] { background: rgb(var(--og-primary, 26 26 26)); }

    .og-calendar-legend { display: flex; align-items: center; justify-content: flex-end; gap: 3px; margin-top: 0.75rem; font-size: 0.75rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-calendar-legend .og-cell { margin-inline: 1px; }
    .og-calendar-legend .og-cell:first-of-type { margin-inline-start: 0.5rem; }
    .og-calendar-legend .og-cell:last-of-type { margin-inline-end: 0.5rem; }

    /* Lists */
    .og-list { display: flex; flex-direction: column; }
    .og-list > li { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: 0.625rem 0; font-size: 0.875rem; border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-list > li:first-child { border-top: 0; }
    .og-event { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0; }
    .og-event-flag { display: inline-flex; margin-inline-end: 0.25rem; vertical-align: middle; }
    .og-event-details { font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); overflow-wrap: anywhere; }
    .og-list time { flex-shrink: 0; font-variant-numeric: tabular-nums; }

    .og-bar-row .og-bar-label { display: flex; flex-direction: column; gap: 0.375rem; flex: 1; min-width: 0; }
    .og-bar { display: block; height: 3px; width: var(--share); border-radius: 2px; background: rgb(var(--og-primary, 26 26 26) / 0.5); }
    .og-bar-row .og-num { color: rgb(var(--og-muted, 107 107 107)); flex-shrink: 0; }

    /* Dashboard widgets (filament.widgets.*). Cards in one row end level: the
       section fills the widget, which the grid stretches to the row's height. */
    .fi-wi-widget:has(> .fi-section) { display: flex; flex-direction: column; }
    .fi-wi-widget > .fi-section { flex: 1 1 auto; }
    /* Album form: the same cards as the dashboard, level within a row. */
    .og-album-card, .og-album-card > .fi-section { height: 100%; }
    .og-album-card .og-mfield-frame { max-height: 16rem; }
    .og-collaborators { display: flex; flex-direction: column; gap: 0.75rem; }
    .og-collaborators-list { display: flex; flex-direction: column; }
    .og-collaborators-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.375rem 0.75rem; padding: 0.5rem 0; }
    .og-collaborators-row + .og-collaborators-row { border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-collaborators-name { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem; min-width: 0; font-size: 0.875rem; overflow-wrap: anywhere; }
    .og-collaborators-controls { display: inline-flex; align-items: center; gap: 0.25rem; }
    .og-collaborators-pages { display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-collaborators-empty, .og-collaborators-note { font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-quick-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .og-row-main { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem; min-width: 0; overflow-wrap: anywhere; }
    .og-badges { display: inline-flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.25rem; flex-shrink: 0; }
    .og-callout { margin-bottom: 0.75rem; padding: 0.625rem 0.75rem; font-size: 0.875rem; border-radius: var(--radius-md); background: rgb(var(--og-primary, 26 26 26) / 0.06); }
    .og-subheading { margin-top: 1.25rem; margin-bottom: 0.125rem; font-size: 0.8125rem; font-weight: 600; color: rgb(var(--og-muted, 107 107 107)); }
    .og-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(4.5rem, 1fr)); gap: 0.5rem; }
    .og-thumbs a { display: block; }
    .og-thumbs .og-thumb { display: block; width: 100%; aspect-ratio: 1; }
    .og-thumb-missing { display: flex; align-items: center; justify-content: center; aspect-ratio: 1; padding: 0.25rem; overflow: hidden; font-size: 0.6875rem; text-align: center; border-radius: var(--radius-md); color: rgb(var(--og-muted, 107 107 107)); background: rgb(var(--og-line, 227 227 227)); }

    /* Trends: per measure a headline figure, then slim weekly bars on a baseline.
       The running week is hatched, so it does not read as a drop. */
    .og-trends { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1.5rem 3rem; }
    .og-trend { display: flex; flex-direction: column; gap: 0.75rem; margin: 0; min-width: 0; }
    .og-trend-head { display: grid; grid-template-columns: auto 1fr; align-items: baseline; column-gap: 0.625rem; }
    .og-trend-value { font-size: 2rem; line-height: 2.25rem; font-weight: 600; letter-spacing: -0.02em; }
    .og-trend-label { font-size: 0.875rem; color: rgb(var(--og-ink, 26 26 26)); }
    .og-trend-change { grid-column: 1 / -1; min-height: 1.25rem; font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-trend-chart { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); align-items: end; gap: 0.375rem; height: 6rem; border-bottom: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-trend-col { display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 2px; height: 100%; padding-bottom: 2px; }
    .og-trend-col:hover .og-trend-bar { opacity: 0.75; }
    .og-trend-bar { display: block; width: 100%; max-width: 1.75rem; height: var(--share); min-height: 2px; border-radius: 4px 4px 1px 1px; }
    .og-trend-bar.is-members, .og-swatch.is-members { background: rgb(var(--og-primary, 26 26 26)); }
    .og-trend-bar.is-guests, .og-swatch.is-guests { background: rgb(var(--og-primary, 26 26 26) / 0.35); }
    .og-trend-col.is-current .og-trend-bar { background: repeating-linear-gradient(135deg, rgb(var(--og-primary, 26 26 26) / 0.55) 0 2px, transparent 2px 5px); box-shadow: inset 0 0 0 1px rgb(var(--og-primary, 26 26 26) / 0.45); }
    .og-trend-axis { display: flex; justify-content: space-between; font-size: 0.75rem; color: rgb(var(--og-muted, 107 107 107)); margin-top: -0.375rem; }
    .og-trend-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem 1rem; font-size: 0.8125rem; }
    .og-trend-legend span { display: inline-flex; align-items: center; gap: 0.375rem; }
    .og-swatch { display: inline-block; width: 0.625rem; height: 0.625rem; border-radius: 2px; }

    /* Rows that ask for something (members, site health). */
    .og-todo { display: flex; flex-direction: column; }
    .og-todo > li { display: grid; grid-template-columns: 1.25rem 1fr auto; align-items: start; gap: 0.25rem 0.75rem; padding: 0.625rem 0; font-size: 0.875rem; border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-todo > li:first-child { border-top: 0; padding-top: 0; }
    .og-todo-icon { width: 1.25rem; height: 1.25rem; margin-top: 0.0625rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-todo-icon.is-danger { color: var(--danger-600); }
    .og-todo-icon.is-warning { color: var(--warning-600); }
    .dark .og-todo-icon.is-danger { color: var(--danger-400); }
    .dark .og-todo-icon.is-warning { color: var(--warning-400); }
    .og-todo-text { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0; }
    .og-todo-text strong { font-weight: 600; }
    .og-todo-text .og-link { align-self: flex-start; margin-top: 0.25rem; }
    .og-quiet { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-subheading-first { margin-top: 0; }
    .og-section-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem; }

    /* Visually hidden, still read by screen readers (Filament's CSS has no sr-only). */
    .og-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    /* Themes list: colour preview of each theme (filament.tables.theme-swatches) */
    .og-swatches { display: flex; gap: 4px; }
    .og-swatches span { width: 1.25rem; height: 1.25rem; border-radius: 9999px; background: var(--swatch); box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); }

    /* ---- Media grid: select by clicking the photo ------------------------- */

    /* Filament's own checkboxes are replaced by the photo overlay. */
    .fi-ta-record:has(.og-pick) > .fi-ta-record-checkbox,
    .fi-ta-ctn:has(.og-pick) .fi-ta-content-header .fi-ta-page-checkbox { display: none; }
    .fi-ta-ctn:has(.og-pick) .fi-ta-content-header:not(:has(select, button)) { display: none; }
    .fi-ta-record:has(.og-pick) { padding: 0.5rem; }
    .fi-ta-record:has(.og-pick .is-selected), .fi-ta-record:has(.og-pick.is-selected) { box-shadow: 0 0 0 2px rgb(var(--og-primary, 26 26 26)); }

    .og-pick { position: relative; }
    .og-pick-photo {
        position: relative;
        display: block;
        width: 100%;
        aspect-ratio: 4 / 3;
        overflow: hidden;
        border-radius: var(--radius-lg);
        background: rgb(var(--og-surface, 243 243 243));
        cursor: pointer;
    }
    .og-pick-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 120ms ease-out, border-radius 120ms ease-out;
    }
    .og-pick-empty { display: grid; place-items: center; height: 100%; font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-pick-photo:focus-visible { outline: 2px solid rgb(var(--og-primary, 26 26 26)); outline-offset: 2px; }

    /* Check mark: hidden until hover/focus, always shown while selecting. */
    .og-pick-mark {
        position: absolute;
        top: 0.5rem;
        left: 0.5rem;
        display: grid;
        place-items: center;
        width: 1.5rem;
        height: 1.5rem;
        border-radius: 9999px;
        border: 2px solid #fff;
        background: rgb(0 0 0 / 0.25);
        color: transparent;
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.35);
        opacity: 0;
        transition: opacity 120ms ease-out;
    }
    .og-pick-mark svg { width: 0.875rem; height: 0.875rem; }
    .og-pick-photo:hover .og-pick-mark,
    .og-pick-photo:focus-visible .og-pick-mark,
    .og-pick.is-selecting .og-pick-mark { opacity: 1; }

    /* Selected: the photo steps back inside a ring, with a filled check. */
    .og-pick.is-selected .og-pick-photo { background: rgb(var(--og-primary, 26 26 26) / 0.18); }
    .og-pick.is-selected .og-pick-photo img { transform: scale(0.88); border-radius: var(--radius-md); }
    .og-pick.is-selected .og-pick-mark { background: rgb(var(--og-primary, 26 26 26)); border-color: rgb(var(--og-primary, 26 26 26)); color: rgb(var(--og-on-primary, 255 255 255)); }
    .dark .og-pick.is-selected .og-pick-mark { box-shadow: 0 0 0 2px rgb(0 0 0 / 0.4); }

    .og-pick-cover {
        position: absolute;
        left: 0.5rem;
        bottom: 0.5rem;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
        background: rgb(0 0 0 / 0.6);
        color: #fff;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* Open details: a small button in the top-right corner. */
    .og-pick-open {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        display: grid;
        place-items: center;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 9999px;
        background: rgb(255 255 255 / 0.92);
        color: #1a1a1a;
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.3);
        opacity: 0;
        transition: opacity 120ms ease-out;
    }
    .og-pick-open svg { width: 0.95rem; height: 0.95rem; }
    .og-pick:hover .og-pick-open, .og-pick-open:focus-visible { opacity: 1; }
    .og-pick-open:focus-visible { outline: 2px solid rgb(var(--og-primary, 26 26 26)); outline-offset: 2px; }
    @media (hover: none) { .og-pick-open, .og-pick-mark { opacity: 1; } }

    @media (prefers-reduced-motion: reduce) {
        .og-pick-photo img, .og-pick-mark, .og-pick-open { transition: none; }
    }

    /* Albums list: tree */
    .og-tree { display: flex; flex-direction: column; gap: 0.125rem; padding-inline-start: calc(var(--og-depth, 0) * 1.5rem); }
    .og-tree-title { display: inline-flex; align-items: center; gap: 0.4rem; }
    .og-tree-meta { font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-tree-branch + span { font-weight: 400; }
    .og-tree-branch { color: rgb(var(--og-muted, 107 107 107)); font-size: 0.8rem; }

    /* Media picker field (MediaPickerField) */
    .og-mfield { display: flex; flex-direction: column; gap: 0.75rem; }
    .og-mfield-frame { display: grid; place-items: center; aspect-ratio: 3 / 2; overflow: hidden; border-radius: var(--radius-lg); background: rgb(var(--og-surface, 243 243 243)); outline: 1px dashed rgb(var(--og-line, 227 227 227)); outline-offset: -1px; }
    .og-mfield-frame img { width: 100%; height: 100%; object-fit: cover; }
    .og-mfield-frame:has(img) { outline: none; }
    .og-mfield-empty { font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); padding: 1rem; text-align: center; }
    .og-mfield.is-multiple .og-mfield-empty { padding: 0; text-align: start; }
    /* Justified like the compact tiles below; media-grid-script completes the rows. */
    .og-mfield-grid { --row: 110px; display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .og-mfield-grid::after { content: ''; display: var(--og-row-spacer, block); flex: 999 1 0; }
    .og-mfield-thumb { position: relative; flex: var(--r, 1.5) 1 var(--og-basis, calc(var(--r, 1.5) * var(--row))); min-width: 0; aspect-ratio: var(--r, 1.5); border-radius: var(--radius-lg); overflow: hidden; background: rgb(var(--og-surface, 243 243 243)); }
    .og-mfield-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .og-mfield-remove { position: absolute; top: 0.25rem; right: 0.25rem; display: grid; place-items: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; background: rgb(0 0 0 / 0.6); color: #fff; }
    .og-mfield-remove svg { width: 0.75rem; height: 0.75rem; }
    .og-mfield-remove:focus-visible { outline: 2px solid #fff; outline-offset: 1px; }
    .og-mfield-count { font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-mfield-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }

    /* ---- Standalone images (ImageUpload): one box that keeps its size in every state ---- */
    .og-iu { display: flex; flex-direction: column; gap: 0.375rem; width: 100%; min-width: 0; container-type: inline-size; }
    .og-iu.is-wide { max-width: 40rem; }
    .og-iu.is-round { width: min(100vw - 6rem, 19rem); }
    .og-iu-frame { position: relative; display: flex; flex-direction: column; overflow: hidden; border: 1px solid rgb(var(--og-line, 227 227 227)); border-radius: var(--radius-lg); transition: border-color 0.12s ease, box-shadow 0.12s ease; }
    .og-iu-frame.is-empty { border-style: dashed; border-color: var(--gray-300); }
    .dark .og-iu-frame.is-empty { border-color: var(--gray-600); }
    .og-iu-frame.is-empty:not(.is-disabled):hover { border-color: rgb(var(--og-muted, 107 107 107)); }
    .og-iu-frame.is-dragging, .og-iu-frame.is-dragging:hover, .dark .og-iu-frame.is-dragging { border-style: solid; border-color: rgb(var(--og-primary, 26 26 26)); box-shadow: 0 0 0 1px rgb(var(--og-primary, 26 26 26)); }
    .og-iu-frame.is-failed, .dark .og-iu-frame.is-failed { border-color: rgb(var(--og-danger, 220 38 38)); }

    .og-iu-stage { position: relative; display: flex; align-items: center; justify-content: center; gap: 1.5rem; width: 100%; height: 8rem; padding: 1.375rem; overflow: hidden; background-color: rgb(var(--og-surface, 243 243 243)); }
    .og-iu-stage.is-wide { height: auto; aspect-ratio: 16 / 9; padding: 0; }
    .og-iu-img { display: block; transition: opacity 0.16s ease; }
    .og-iu-img.is-dim, .og-iu-chip.is-dim { opacity: 0.4; }
    .og-iu-stage.is-contain .og-iu-img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .og-iu-stage.is-wide .og-iu-img { width: 100%; height: 100%; object-fit: cover; }
    .og-iu-stage.is-icon .og-iu-img { flex-shrink: 0; width: 3.5rem; height: 3.5rem; object-fit: contain; }
    .og-iu-stage.is-round .og-iu-img { width: 5.75rem; height: 5.75rem; border-radius: 9999px; object-fit: cover; }
    /* A logo is judged against the background it appears on, whatever mode the admin is in. */
    .og-iu-stage.is-on-light { --og-iu-paper: #fff; --og-iu-tile: var(--gray-100); }
    .og-iu-stage.is-on-dark { --og-iu-paper: var(--gray-900); --og-iu-tile: var(--gray-800); }
    .og-iu-stage.is-on-theme { --og-iu-paper: rgb(var(--og-canvas, 255 255 255)); --og-iu-tile: rgb(var(--og-surface, 243 243 243)); }
    .og-iu-check { background-color: var(--og-iu-paper); background-image: conic-gradient(var(--og-iu-tile) 25%, transparent 0 50%, var(--og-iu-tile) 0 75%, transparent 0); background-size: 16px 16px; }
    .og-iu-no-preview { font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-iu-stage.is-on-dark .og-iu-no-preview { color: var(--gray-300); }
    .og-iu-chip { display: inline-flex; flex: 0 1 auto; align-items: center; gap: 0.4375rem; height: 2rem; min-width: 0; padding: 0 0.6875rem; border-radius: var(--radius-lg); background: rgb(var(--og-canvas, 255 255 255)); color: rgb(var(--og-ink, 26 26 26)); font-size: 0.8125rem; font-weight: 500; box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); transition: opacity 0.16s ease; }
    .og-iu-chip img { flex-shrink: 0; width: 1.125rem; height: 1.125rem; object-fit: contain; }
    .og-iu-chip span { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

    .og-iu-drop { flex-direction: column; gap: 0.4375rem; color: rgb(var(--og-muted, 107 107 107)); text-align: center; transition: background-color 0.12s ease; }
    button.og-iu-drop { cursor: pointer; }
    button.og-iu-drop:hover { background-color: color-mix(in srgb, rgb(var(--og-surface, 243 243 243)) 72%, rgb(var(--og-line, 227 227 227))); }
    .og-iu-drop svg { width: 1.375rem; height: 1.375rem; }
    .og-iu-prompt { color: rgb(var(--og-ink, 26 26 26)); font-size: 0.875rem; line-height: 1.25rem; }
    .og-iu-link { font-weight: 600; text-decoration: underline; text-decoration-color: var(--gray-400); text-underline-offset: 3px; }
    .og-iu-prompt-touch { display: none; }
    @media (hover: none) {
        .og-iu-prompt-pointer { display: none; }
        .og-iu-prompt-touch { display: inline; }
    }
    .og-iu-frame.is-dragging .og-iu-drop { background-color: transparent; color: rgb(var(--og-ink, 26 26 26)); }
    .og-iu-drop:focus-visible, .og-iu-btn:focus-visible { outline: 2px solid rgb(var(--og-primary, 26 26 26)); outline-offset: -2px; }

    .og-iu-meta { position: relative; display: flex; align-items: center; gap: 0.375rem; min-height: 3.5rem; padding: 0.3125rem 0.375rem 0.3125rem 0.75rem; border-top: 1px solid rgb(var(--og-line, 227 227 227)); font-size: 0.875rem; line-height: 1.25rem; }
    .og-iu-frame.is-empty .og-iu-meta { justify-content: space-between; padding-inline-end: 0.75rem; border-top-color: transparent; background: rgb(var(--og-surface, 243 243 243)); color: rgb(var(--og-muted, 107 107 107)); font-size: 0.8125rem; }
    .og-iu-frame.is-empty .og-iu-meta.has-action { padding-inline-end: 0.375rem; }
    .og-iu-frame.is-dragging .og-iu-meta { background: transparent; }
    .og-iu-progress { position: absolute; top: -1px; inset-inline-start: 0; display: block; height: 2px; background: rgb(var(--og-primary, 26 26 26)); transition: width 0.12s linear; }
    .og-iu-file { display: flex; flex: 1; flex-direction: column; min-width: 0; }
    .og-iu-name { overflow: hidden; font-weight: 500; color: rgb(var(--og-ink, 26 26 26)); text-overflow: ellipsis; white-space: nowrap; }
    .og-iu-sub { display: flex; align-items: center; gap: 0.5rem; overflow: hidden; color: rgb(var(--og-muted, 107 107 107)); font-size: 0.8125rem; line-height: 1.0625rem; white-space: nowrap; }
    .og-iu-flag { display: inline-flex; flex-shrink: 0; align-items: center; gap: 0.3125rem; color: rgb(var(--og-ink, 26 26 26)); font-weight: 500; }
    .og-iu-flag::before { content: ''; width: 6px; height: 6px; border-radius: 9999px; background: currentColor; }
    /* In a narrow field the unsaved notice moves onto the image, where it fits in every language. */
    .og-iu-stage-flag { display: none; position: absolute; top: 0.5rem; inset-inline-start: 0.5rem; max-width: calc(100% - 1rem); padding: 0.125rem 0.5rem; border-radius: 9999px; background: rgb(var(--og-canvas, 255 255 255)); font-size: 0.75rem; line-height: 1.125rem; white-space: nowrap; box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); }
    @container (max-width: 24rem) {
        .og-iu-stage-flag { display: inline-flex; }
        .og-iu-meta-flag { display: none; }
    }
    .og-iu-actions { display: flex; flex-shrink: 0; }
    .og-iu-btn { min-height: 2.75rem; padding: 0 0.625rem; border-radius: var(--radius-md); color: rgb(var(--og-ink, 26 26 26)); font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: background-color 0.12s ease, color 0.12s ease; }
    .og-iu-btn:hover { background: rgb(var(--og-surface, 243 243 243)); }
    .og-iu-btn:disabled { opacity: 0.5; cursor: default; }
    .og-iu-btn.is-quiet { color: rgb(var(--og-muted, 107 107 107)); }
    .og-iu-btn.is-quiet:hover { color: rgb(var(--og-ink, 26 26 26)); }
    .og-iu-error { display: flex; align-items: flex-start; gap: 0.375rem; color: rgb(var(--og-danger, 220 38 38)); font-size: 0.8125rem; line-height: 1.125rem; }
    .og-iu-error svg { flex-shrink: 0; width: 1rem; height: 1rem; margin-top: 1px; }
    @media (prefers-reduced-motion: reduce) {
        .og-iu-frame, .og-iu-img, .og-iu-chip, .og-iu-drop, .og-iu-progress, .og-iu-btn { transition: none; }
    }

    /* ---- Group members as overlapping pictures ----------------------------- */
    .og-member-stack { display: flex; align-items: center; }
    .og-member-empty { font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-member-stack > img, .og-member-more { width: 1.875rem; height: 1.875rem; border-radius: 9999px; box-shadow: 0 0 0 2px var(--color-white, #fff); }
    .dark .og-member-stack > img, .dark .og-member-more { box-shadow: 0 0 0 2px var(--gray-900, #18181b); }
    .og-member-stack > * + * { margin-inline-start: -0.25rem; }
    .og-member-stack > img { object-fit: cover; }
    .og-member-more { display: inline-grid; place-items: center; min-width: 1.875rem; width: auto; padding-inline: 0.375rem; font-size: 0.6875rem; font-weight: 600; background: rgb(var(--og-surface, 243 243 243)); color: rgb(var(--og-ink, 26 26 26)); }

    /* ---- Role form: permission groups in balanced columns ---------------- */
    @media (min-width: 768px) {
        .og-perm-columns > .fi-grid { display: block; column-count: 2; column-gap: 1.5rem; }
        .og-perm-columns > .fi-grid > .fi-grid-col { break-inside: avoid; margin-bottom: 1.5rem; }
        .og-perm-columns > .fi-grid > .fi-grid-col:last-child { margin-bottom: 0; }
    }

    /* ---- Compact photo tiles (picker, album photos, chosen photos) ------- */
    .og-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }

    /*
     * Justified rows, like the site's album grid (.og-justified): each record
     * grows in proportion to its photo's shape (--r, from the og-r-* class
     * MediaGrid puts on it), so every photo in a row has the same height.
     * media-grid-script splits the photos into complete rows (--og-basis) and
     * turns the ::after spacer off; the spacer only stays when there are too
     * few photos to fill one row at a sensible size.
     */
    .fi-ta-content-grid:has(.og-pick.is-compact) { --row: 120px; display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.75rem; }
    .fi-ta-content-grid:has(.og-pick.is-compact)::after { content: ''; display: var(--og-row-spacer, block); flex: 999 1 0; }
    @media (min-width: 640px) { .fi-ta-content-grid:has(.og-pick.is-compact) { --row: 140px; } }
    @media (min-width: 1024px) { .fi-ta-content-grid:has(.og-pick.is-compact) { --row: 180px; } }
    .fi-ta-record:has(.og-pick.is-compact) { flex: var(--r, 1.5) 1 var(--og-basis, calc(var(--r, 1.5) * var(--row))); min-width: 0; height: auto; }
    /* Filament's column shrinks its content to fit; the photo must fill the whole record. */
    .og-pick.is-compact { width: 100%; min-width: 0; }
    .og-pick.is-compact .og-pick-photo { aspect-ratio: var(--r, 1.5); }
    @for ($step = \App\Filament\Tables\MediaGrid::RATIO_MIN; $step <= \App\Filament\Tables\MediaGrid::RATIO_MAX; $step++)
    .og-r-{{ $step }} { --r: {{ $step / \App\Filament\Tables\MediaGrid::RATIO_STEPS }}; }
    @endfor
    /* Dragging into order: the handle sits on the photo instead of beside it. */
    .fi-ta-record:has(.og-pick.is-compact) > .fi-ta-reorder-handle { position: absolute; top: 0.375rem; left: 0.375rem; z-index: 1; margin: 0; border-radius: 9999px; background: rgb(255 255 255 / 0.92); color: #1a1a1a; box-shadow: 0 1px 3px rgb(0 0 0 / 0.3); }
    .fi-ta-record:has(.og-pick.is-compact):has(> .fi-ta-reorder-handle) .og-pick-mark { display: none; }
    .fi-ta-record:has(.og-pick.is-compact) { padding: 0; border-radius: var(--radius-lg); overflow: hidden; box-shadow: none; background: transparent; }
    .fi-ta-record:has(.og-pick.is-compact.is-selected) { box-shadow: 0 0 0 2px rgb(var(--og-primary, 26 26 26)); }
    .fi-ta-record:has(.og-pick.is-compact) .fi-ta-record-content-ctn,
    .fi-ta-record:has(.og-pick.is-compact) .fi-ta-record-content { padding: 0; }
    .og-pick.is-compact .og-pick-photo { border-radius: var(--radius-lg); }
    .og-pick-caption {
        position: absolute; inset-inline: 0; bottom: 0;
        padding: 1.25rem 0.5rem 0.375rem;
        background: linear-gradient(to top, rgb(0 0 0 / 0.65), transparent);
        color: #fff; font-size: 0.75rem; font-weight: 500; text-align: start;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        pointer-events: none;
    }
    /* In a compact tile the cover badge sits in the caption, clear of the "open" button. */
    .og-pick-caption .og-pick-cover { position: static; display: inline-block; margin-inline-end: 0.375rem; padding: 0 0.375rem; font-size: 0.6875rem; line-height: 1.125rem; background: rgb(255 255 255 / 0.9); color: #1a1a1a; }

    /* Media picker drop zone (chunked uploader) */
    [x-cloak] { display: none !important; }
    /* The modal grows with its content otherwise: long file names would make it wider than a phone screen. */
    .fi-modal-window:has(.og-picker) { min-width: 0; }
    .og-uploader { margin-bottom: 1rem; padding: 1.25rem 1.5rem; border-radius: var(--radius-lg); background: rgb(var(--og-surface, 243 243 243)); border: 1.5px dashed rgb(var(--og-line, 210 210 210)); font-size: 0.9375rem; color: rgb(var(--og-muted, 107 107 107)); transition: border-color 0.15s, background-color 0.15s; }
    .dark .og-uploader { background: color-mix(in oklab, var(--gray-800) 40%, transparent); border-color: var(--gray-700); }
    .og-uploader.is-dragging { border-color: rgb(var(--og-primary, 26 26 26)); }
    /* Waiting and uploading share one cell, so the box and the grid below it do not move when an upload starts or ends. */
    .og-uploader-stage { display: grid; }
    .og-uploader-stage > * { grid-area: 1 / 1; min-width: 0; }
    .og-uploader .is-off { visibility: hidden; }
    .og-uploader-idle { min-height: 4rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem; text-align: center; }
    .og-uploader-prompt { display: flex; flex-direction: column; gap: 0.25rem; }
    .og-uploader-live { min-height: 1.5em; line-height: 1.5; }
    .og-uploader-hint { font-size: 0.8125rem; }
    .og-uploader-browse { color: rgb(var(--og-ink, 26 26 26)); text-decoration: underline; text-decoration-color: rgb(var(--og-line, 210 210 210)); text-underline-offset: 2px; font-weight: 500; }
    .dark .og-uploader-browse { color: var(--gray-100); }
    .og-uploader-busy { display: flex; flex-direction: column; gap: 0.5rem; }
    .og-uploader-status { display: flex; justify-content: space-between; gap: 1rem; min-height: 3em; line-height: 1.5; color: rgb(var(--og-ink, 26 26 26)); }
    @media (min-width: 640px) { .og-uploader-status { min-height: 1.5em; } }
    .dark .og-uploader-status { color: var(--gray-100); }
    .og-uploader-bar { height: 0.375rem; border-radius: 9999px; overflow: hidden; background: rgb(var(--og-line, 227 227 227)); }
    .og-uploader-bar span { display: block; height: 100%; background: rgb(var(--og-primary, 26 26 26)); transition: width 0.3s; }
    .og-uploader-current { min-height: 1.5em; line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .og-uploader-notice { color: rgb(var(--og-ink, 26 26 26)); }
    .dark .og-uploader-notice { color: var(--gray-100); }
    .og-uploader-problems { margin-top: 0.75rem; display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.8125rem; }
    .og-uploader-problems ul { max-height: 8rem; overflow-y: auto; list-style: disc; padding-inline-start: 1.25rem; }

    /*
     * ---- Documentation page (filament.pages.documentation) ----------------
     * Wide screens (80rem+, where the docs area is ~900px next to the admin
     * sidebar): contents column + article. Below: a bar that stays under the
     * top bar and opens the same contents as a drawer (a bottom sheet on phones).
     */
    .og-docs { --og-docs-page: var(--gray-50); --og-docs-paper: #fff; display: grid; gap: 1rem; align-items: start; }
    .dark .og-docs { --og-docs-page: var(--gray-950); --og-docs-paper: var(--gray-900); }
    .og-docs-main { min-width: 0; max-width: 46rem; }
    .og-docs .sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .og-docs-main.is-loading { opacity: 0.55; transition: opacity 0.15s ease 0.15s; }

    /* Location bar */
    .og-docs-bar { position: sticky; top: 4rem; z-index: 20; margin: -0.5rem -1rem 0; padding: 0.5rem 1rem; background: var(--og-docs-page); }
    @media (min-width: 48rem) { .og-docs-bar { margin-inline: -1.5rem; padding-inline: 1.5rem; } }
    @media (min-width: 64rem) { .og-docs-bar { margin-inline: -2rem; padding-inline: 2rem; } }
    .og-docs-bar-button { display: flex; align-items: center; gap: 0.75rem; width: 100%; max-width: 46rem; padding: 0.5rem 0.875rem; border-radius: var(--radius-lg); background: var(--og-docs-paper); box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); text-align: start; }
    .og-docs-bar-button:focus-visible { outline: 2px solid rgb(var(--og-ink, 26 26 26)); outline-offset: 2px; }
    .og-docs-bar-text { display: grid; flex: 1; min-width: 0; line-height: 1.3; }
    .og-docs-bar-title { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 0.875rem; font-weight: 600; color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-bar-hint { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-bar-icon { flex: none; width: 1.25rem; height: 1.25rem; color: rgb(var(--og-muted, 107 107 107)); }

    /* Contents: a drawer below wide screens */
    .og-docs-backdrop { position: fixed; inset: 0; z-index: 40; background: rgb(0 0 0 / 0.4); }
    .og-docs-nav { position: fixed; z-index: 50; display: flex; flex-direction: column; background: var(--og-docs-paper); font-size: 0.9375rem; visibility: hidden; transition: transform 0.25s ease, visibility 0s linear 0.25s; }
    .og-docs-nav.is-open { visibility: visible; transform: none; transition: transform 0.25s ease; }
    @media (max-width: 39.99rem) {
        .og-docs-nav { inset-inline: 0; bottom: 0; max-height: 85dvh; border-radius: calc(var(--radius-lg) * 2) calc(var(--radius-lg) * 2) 0 0; transform: translateY(100%); }
    }
    @media (min-width: 40rem) and (max-width: 79.99rem) {
        .og-docs-nav { inset-block: 0; inset-inline-start: 0; width: 22rem; transform: translateX(-100%); box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); }
        [dir='rtl'] .og-docs-nav { transform: translateX(100%); }
        [dir='rtl'] .og-docs-nav.is-open { transform: none; }
    }
    @media (prefers-reduced-motion: reduce) { .og-docs-nav, .og-docs-nav.is-open { transition: none; } }
    .og-docs-nav-head { display: flex; align-items: center; justify-content: space-between; padding: 0.875rem 1rem 0.25rem 1.25rem; }
    .og-docs-nav-heading { font-weight: 600; color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-nav-close { display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: var(--radius-lg); }
    .og-docs-nav-close:hover { background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-nav-close:focus-visible { outline: 2px solid rgb(var(--og-ink, 26 26 26)); }
    .og-docs-nav-scroll { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 0.5rem 1rem 1.5rem; }

    /* Contents: a column on wide screens */
    @media (min-width: 80rem) {
        .og-docs { grid-template-columns: 15.5rem minmax(0, 1fr); column-gap: 2.5rem; }
        .og-docs-bar, .og-docs-backdrop, .og-docs-nav-head { display: none !important; }
        .og-docs-nav { position: sticky; top: 5rem; z-index: auto; max-height: calc(100dvh - 6rem); background: none; font-size: 0.875rem; visibility: visible; transform: none; transition: none; }
        .og-docs-nav-scroll { padding: 0 0.25rem 1rem 0; }
    }

    /* The ring is drawn inside the field: the scrolling list around it would cut off an outer one. */
    .og-docs-filter { display: block; width: 100%; padding: 0.5rem 0.75rem; border: 0; border-radius: var(--radius-lg); background: var(--og-docs-paper); box-shadow: inset 0 0 0 1px rgb(var(--og-line, 227 227 227)); color: rgb(var(--og-ink, 26 26 26)); font-size: 1rem; }
    .og-docs-filter::placeholder { color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-filter:focus { outline: none; box-shadow: inset 0 0 0 2px rgb(var(--og-ink, 26 26 26)); }
    @media (min-width: 80rem) { .og-docs-filter { font-size: 0.875rem; } }

    /* Guide switch */
    .og-docs-guides { display: grid; grid-auto-columns: 1fr; grid-auto-flow: column; gap: 0.25rem; margin: 0.875rem 0 0.75rem; padding: 0.25rem; border-radius: var(--radius-lg); background: rgb(var(--og-surface, 243 243 243)); }
    .dark .og-docs-guides { background: var(--gray-800); }
    .og-docs-guides button { padding: 0.375rem 0.5rem; border-radius: var(--radius-md); font-size: 0.8125rem; font-weight: 500; line-height: 1.3; color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-guides button:hover { color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-guides button[aria-selected='true'] { background: var(--og-docs-paper); color: rgb(var(--og-ink, 26 26 26)); box-shadow: 0 1px 2px rgb(0 0 0 / 0.08), 0 0 0 1px rgb(var(--og-line, 227 227 227)); }
    .og-docs-guides button:focus-visible { outline: 2px solid rgb(var(--og-ink, 26 26 26)); outline-offset: 1px; }

    /* Tree */
    .og-docs-tree { display: flex; flex-direction: column; gap: 0.125rem; }
    .og-docs-tree .og-docs-tree { margin: 0.125rem 0 0.375rem 0.75rem; padding-inline-start: 0.5rem; border-inline-start: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-docs-tree-root { margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-docs-link, .og-docs-folder summary { display: flex; align-items: center; min-height: 2.5rem; padding: 0.375rem 0.625rem; border-radius: var(--radius-md); line-height: 1.35; }
    @media (min-width: 80rem) { .og-docs-link, .og-docs-folder summary { min-height: 2rem; } }
    .og-docs-link { color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-link:hover { color: rgb(var(--og-ink, 26 26 26)); background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-link[aria-current='page'] { color: rgb(var(--og-ink, 26 26 26)); background: rgb(var(--og-surface, 243 243 243)); font-weight: 600; }
    .dark .og-docs-link:hover, .dark .og-docs-link[aria-current='page'], .dark .og-docs-folder summary:hover { background: var(--gray-800); }
    .og-docs-folder summary { justify-content: space-between; gap: 0.5rem; list-style: none; cursor: pointer; font-weight: 600; color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-folder summary::-webkit-details-marker { display: none; }
    .og-docs-folder summary:hover { background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-folder-icon { flex: none; width: 1rem; height: 1rem; color: rgb(var(--og-muted, 107 107 107)); transition: transform 0.15s ease; }
    .og-docs-folder details[open] > summary .og-docs-folder-icon { transform: rotate(90deg); }
    [dir='rtl'] .og-docs-folder details:not([open]) > summary .og-docs-folder-icon { transform: rotate(180deg); }
    .og-docs-nav a:focus-visible, .og-docs-folder summary:focus-visible { outline: 2px solid rgb(var(--og-ink, 26 26 26)); outline-offset: -2px; }

    /* The open doc's sections: a thread that follows your reading */
    .og-docs-outline { margin: 0.25rem 0 0.5rem 0.875rem; border-inline-start: 2px solid rgb(var(--og-line, 227 227 227)); }
    .og-docs-outline a { display: block; margin-inline-start: -2px; padding: 0.3rem 0.75rem; border-inline-start: 2px solid transparent; font-size: 0.8125rem; line-height: 1.4; color: rgb(var(--og-muted, 107 107 107)); transition: border-color 0.15s ease, color 0.15s ease; }
    .og-docs-outline a:hover { color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-outline a[aria-current='location'] { border-inline-start-color: rgb(var(--og-ink, 26 26 26)); color: rgb(var(--og-ink, 26 26 26)); font-weight: 500; }
    @media (prefers-reduced-motion: reduce) { .og-docs-outline a, .og-docs-folder-icon { transition: none; } }

    /* Filter results */
    .og-docs-results { display: flex; flex-direction: column; gap: 0.125rem; margin-top: 0.75rem; }
    .og-docs-results a { display: grid; gap: 0.125rem; padding: 0.5rem 0.625rem; border-radius: var(--radius-md); }
    .og-docs-results a:hover { background: rgb(var(--og-surface, 243 243 243)); }
    .dark .og-docs-results a:hover { background: var(--gray-800); }
    .og-docs-results-title { font-weight: 500; color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-results-context { font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-results-empty { margin-top: 0.75rem; padding: 0 0.625rem; font-size: 0.875rem; line-height: 1.5; color: rgb(var(--og-muted, 107 107 107)); }

    /* Previous / next */
    .og-docs-pager { display: grid; gap: 0.5rem; margin-top: 1.5rem; }
    @media (min-width: 40rem) { .og-docs-pager { grid-template-columns: 1fr 1fr; gap: 1rem; } }
    .og-docs-pager-link { display: grid; gap: 0.25rem; padding: 0.75rem 1rem; border-radius: var(--radius-lg); }
    .og-docs-pager-link:hover { background: var(--og-docs-paper); box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); }
    .og-docs-pager-link:focus-visible { outline: 2px solid rgb(var(--og-ink, 26 26 26)); }
    .og-docs-pager-next { grid-column: -2; text-align: end; }
    .og-docs-pager-next .og-docs-pager-hint { justify-content: flex-end; }
    .og-docs-pager-hint { display: inline-flex; align-items: center; gap: 0.375rem; font-size: 0.8125rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-pager-icon { width: 1rem; height: 1rem; }
    [dir='rtl'] .og-docs-pager-icon { transform: scaleX(-1); }
    .og-docs-pager-title { font-weight: 600; line-height: 1.35; color: rgb(var(--og-ink, 26 26 26)); }

    /* Article */
    @media (max-width: 39.99rem) { .og-docs-section .fi-section-content { padding: 1.125rem 1rem; } }
    /* The page header already shows the doc title. */
    .og-docs-body > h1:first-child { display: none; }
    .og-docs-body { font-size: 1rem; line-height: 1.7; overflow-wrap: break-word; color: rgb(var(--og-ink, 26 26 26)); }
    .og-docs-body > :first-child, .og-docs-body > h1:first-child + * { margin-top: 0; }
    .og-docs-body h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 1rem; }
    .og-docs-body h2 { font-size: 1.3125rem; font-weight: 600; line-height: 1.35; margin: 2.25rem 0 0.75rem; padding-top: 1.5rem; border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-docs-body h3 { font-size: 1.0625rem; font-weight: 600; line-height: 1.4; margin: 1.75rem 0 0.5rem; }
    .og-docs-body h4 { font-weight: 600; margin: 1.25rem 0 0.5rem; }
    .og-docs-body p, .og-docs-body ul, .og-docs-body ol, .og-docs-body pre, .og-docs-body table, .og-docs-body blockquote { margin: 0 0 1rem; }
    .og-docs-body ul { list-style: disc; padding-inline-start: 1.5rem; }
    .og-docs-body ol { list-style: decimal; padding-inline-start: 1.5rem; }
    .og-docs-body li + li { margin-top: 0.25rem; }
    .og-docs-body li > ul, .og-docs-body li > ol { margin: 0.25rem 0 0; }
    .og-docs-body a { text-decoration: underline; text-decoration-color: rgb(var(--og-line, 227 227 227)); text-underline-offset: 3px; }
    .og-docs-body a:hover { text-decoration-color: currentColor; }
    .og-docs-body .heading-permalink { margin-inline-start: 0.375rem; text-decoration: none; color: rgb(var(--og-muted, 107 107 107)); opacity: 0; scroll-margin-top: 8.5rem; }
    @media (min-width: 80rem) { .og-docs-body .heading-permalink { scroll-margin-top: 5.5rem; } }
    .og-docs-body :is(h1, h2, h3, h4):hover .heading-permalink, .og-docs-body .heading-permalink:focus { opacity: 1; }
    .og-docs-body code { padding: 0.1rem 0.3rem; border-radius: 0.25rem; font-size: 0.85em; background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-body pre { padding: 1rem 1.125rem; border-radius: 0.5rem; overflow-x: auto; font-size: 0.8125rem; line-height: 1.6; background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-body pre code { padding: 0; background: none; font-size: inherit; }
    .og-docs-body blockquote { padding-inline-start: 1rem; border-inline-start: 3px solid rgb(var(--og-line, 227 227 227)); color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-body table { display: block; overflow-x: auto; border-collapse: collapse; font-size: 0.875rem; }
    .og-docs-body th, .og-docs-body td { padding: 0.5rem 0.75rem; border: 1px solid rgb(var(--og-line, 227 227 227)); text-align: start; vertical-align: top; }
    .og-docs-body th { font-weight: 600; background: rgb(var(--og-surface, 243 243 243)); }
    .og-docs-body img { max-width: 100%; height: auto; border-radius: 0.5rem; box-shadow: 0 0 0 1px rgb(var(--og-line, 227 227 227)); }
    .og-docs-body .og-docs-missing-image { display: block; padding: 1.5rem 1rem; border: 1px dashed rgb(var(--og-line, 227 227 227)); border-radius: 0.5rem; text-align: center; font-size: 0.875rem; color: rgb(var(--og-muted, 107 107 107)); }
    .og-docs-body hr { margin: 2rem 0; border: 0; border-top: 1px solid rgb(var(--og-line, 227 227 227)); }
    .og-docs-body input[type='checkbox'] { margin-inline-end: 0.375rem; }

    /* Read-only file contents in a form (the robots.txt preview). */
    .og-code-preview { margin: 0; padding: 0.75rem 1rem; border-radius: var(--radius-lg); background: var(--gray-50); color: var(--gray-700); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8125rem; line-height: 1.6; white-space: pre-wrap; overflow-wrap: anywhere; }
    .dark .og-code-preview { background: var(--gray-950); color: var(--gray-300); }

    /* Tag field: an input with a dropdown of existing tags, and the chosen tags below it. */
    .og-tags-combobox { position: relative; }
    .og-tags-dropdown { position: absolute; z-index: 30; inset-inline: 0; top: calc(100% + 0.25rem); max-height: 15rem; overflow-y: auto; overscroll-behavior: contain; padding: 0.25rem; border-radius: var(--radius-lg); background: white; box-shadow: 0 8px 24px rgb(0 0 0 / 0.12), 0 0 0 1px var(--gray-200); }
    .dark .og-tags-dropdown { background: var(--gray-900); box-shadow: 0 8px 24px rgb(0 0 0 / 0.4), 0 0 0 1px var(--gray-700); }
    .og-tags-option { display: flex; align-items: center; gap: 0.375rem; padding: 0.375rem 0.625rem; border-radius: var(--radius-md); font-size: 0.875rem; line-height: 1.25rem; color: var(--gray-800); cursor: pointer; overflow-wrap: anywhere; }
    .dark .og-tags-option { color: var(--gray-100); }
    .og-tags-option.is-active { background: var(--gray-100); }
    .dark .og-tags-option.is-active { background: var(--gray-800); }
    .og-tags-option.is-new { color: var(--gray-600); }
    .dark .og-tags-option.is-new { color: var(--gray-300); }
    .og-tags-option-name { white-space: pre-wrap; }
    .og-tags-option mark { background: none; color: inherit; font-weight: 600; }
    .og-tags-option-icon { display: inline-flex; flex-shrink: 0; }
    .og-tags-option-icon svg { width: 1rem; height: 1rem; }
    .og-tags-empty { padding: 0.375rem 0.625rem; font-size: 0.875rem; line-height: 1.25rem; color: var(--gray-500); }
    .og-tags-list { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-top: 0.5rem; }
    .og-tag { display: inline-flex; align-items: center; gap: 0.25rem; max-width: 100%; padding: 0.125rem 0.25rem 0.125rem 0.5rem; border-radius: var(--radius-md); background: var(--gray-100); box-shadow: inset 0 0 0 1px var(--gray-200); color: var(--gray-800); font-size: 0.8125rem; line-height: 1.25rem; overflow-wrap: anywhere; }
    .dark .og-tag { background: var(--gray-800); box-shadow: inset 0 0 0 1px var(--gray-700); color: var(--gray-100); }
    .og-tag-remove { display: inline-flex; flex-shrink: 0; width: 1.25rem; height: 1.25rem; align-items: center; justify-content: center; border-radius: var(--radius-sm); color: var(--gray-500); }
    .og-tag-remove:hover, .og-tag-remove:focus-visible { background: var(--gray-200); color: var(--gray-900); }
    .dark .og-tag-remove { color: var(--gray-400); }
    .dark .og-tag-remove:hover, .dark .og-tag-remove:focus-visible { background: var(--gray-700); color: var(--gray-50); }
    .og-tag-remove svg { width: 0.875rem; height: 0.875rem; }
    .og-tags-hint { margin-top: 0.5rem; font-size: 0.8125rem; line-height: 1.25rem; color: var(--gray-600); }
    .dark .og-tags-hint { color: var(--gray-400); }
</style>
