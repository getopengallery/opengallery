import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// Theme tokens are CSS variables set by the active theme's theme.json,
// so themes can restyle the site without a build step.
const token = (name) => `rgb(var(--og-${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    // Same switch as the Filament admin (localStorage "filament:theme:mode"),
    // so light/dark is shared between site and admin.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './themes/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['var(--og-font-sans)', ...defaultTheme.fontFamily.sans],
            },
            // Scale ≈ 1.25 from a 16px base.
            fontSize: {
                caption: ['0.8125rem', { lineHeight: '1.25rem' }],
                body: ['1rem', { lineHeight: '1.6rem' }],
                title: ['1.25rem', { lineHeight: '1.75rem', letterSpacing: '-0.01em' }],
                heading: ['2rem', { lineHeight: '2.375rem', letterSpacing: '-0.02em' }],
            },
            colors: {
                canvas: token('canvas'),
                surface: token('surface'),
                ink: token('ink'),
                muted: token('muted'),
                line: token('line'),
                primary: token('primary'),
                'on-primary': token('on-primary'),
                danger: token('danger'),
                'on-danger': token('on-danger'),
            },
            borderRadius: {
                og: 'var(--og-radius)',
            },
        },
    },

    plugins: [forms],
};
