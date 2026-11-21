/**
 * Light / dark appearance, shared by every switch on the page.
 *
 * Uses the Filament admin's storage key, so site and admin stay in sync:
 * "light", "dark" or "system" (follow the operating system; the default).
 * The first paint is handled by the inline script in partials/theme-head,
 * so there is no flash of the wrong mode before this loads.
 */
const KEY = 'filament:theme:mode';
const media = window.matchMedia('(prefers-color-scheme: dark)');

const read = () => {
    try {
        return localStorage.getItem(KEY) ?? 'system';
    } catch {
        return 'system';
    }
};

export default {
    mode: read(),
    systemDark: media.matches,

    init() {
        media.addEventListener('change', (event) => {
            this.systemDark = event.matches;
            this.apply();
        });

        // Another tab (or the admin) changed the setting.
        window.addEventListener('storage', (event) => {
            if (event.key === KEY) {
                this.mode = read();
                this.apply();
            }
        });
    },

    get isDark() {
        return this.mode === 'dark' || (this.mode === 'system' && this.systemDark);
    },

    /** Flip what is currently shown; one press always changes the page. */
    toggle() {
        this.set(this.isDark ? 'light' : 'dark');
    },

    set(mode) {
        this.mode = mode;

        try {
            localStorage.setItem(KEY, mode);
        } catch {
            // Private mode or blocked storage: still switch for this page view.
        }

        this.apply();
    },

    apply() {
        document.documentElement.classList.toggle('dark', this.isDark);
    },
};
