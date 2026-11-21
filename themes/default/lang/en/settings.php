<?php

/*
 * The Default theme's own texts (theme.json settings form), used as
 * __('theme-default::settings.…'). Core texts live in /lang.
 */
return [
    'sections' => [
        'colours' => [
            'label' => 'Colours',
        ],
        'header' => [
            'label' => 'Header',
        ],
        'footer' => [
            'label' => 'Footer',
        ],
        'albums' => [
            'label' => 'Albums',
        ],
        'landing' => [
            'label' => 'Landing page',
            'description' => 'Shown to visitors who are not logged in while public albums are turned off (Settings → Access). It can also open the album list on the home page.',
        ],
        'auth' => [
            'label' => 'Login and registration',
        ],
        'custom_css' => [
            'label' => 'Custom CSS',
            'description' => 'Added after the theme\'s styles on every page of the site (not the admin).',
        ],
    ],
    'fields' => [
        'palette' => [
            'label' => 'Palette',
            'help' => 'Each palette includes matching light and dark colours.',
        ],
        'custom_colors' => [
            'label' => 'Use my own colours',
            'help' => 'Overrides the palette for the colours you set below. Leave a colour empty to keep the palette\'s.',
        ],
        'radius' => [
            'label' => 'Corners',
            'options' => [
                'from_the_palette' => 'From the palette',
                'square' => 'Square',
                'slightly_rounded' => 'Slightly rounded',
                'rounded' => 'Rounded',
            ],
        ],
        'font' => [
            'label' => 'Typeface',
            'help' => 'System and Serif use fonts already on the visitor\'s device.',
            'options' => [
                'from_the_palette' => 'From the palette',
                'hanken_grotesk' => 'Hanken Grotesk',
                'system' => 'System',
                'serif' => 'Serif',
            ],
        ],
        'header_layout' => [
            'label' => 'Layout',
            'help' => 'Classic: logo left, links right. Centered: logo above the links. Minimal: logo and a menu button.',
            'options' => [
                'classic' => 'Classic',
                'centered' => 'Centered',
                'minimal' => 'Minimal',
            ],
        ],
        'header_pages' => [
            'label' => 'Pages in the header',
        ],
        'header_mode_toggle' => [
            'label' => 'Show the light/dark switch',
        ],
        'header_sticky' => [
            'label' => 'Keep the header visible while scrolling',
        ],
        'footer_layout' => [
            'label' => 'Layout',
            'options' => [
                'one_line' => 'One line',
                'columns' => 'Columns',
                'no_footer' => 'No footer',
            ],
        ],
        'footer_text' => [
            'label' => 'Text',
            'help' => 'For example a short description, a copyright line or contact details.',
        ],
        'footer_pages' => [
            'label' => 'Pages in the footer',
            'help' => 'For example your imprint and privacy policy.',
        ],
        'album_grid_layout' => [
            'label' => 'Album tiles',
            'help' => 'Grid shows up to four albums per row. Large shows two, with bigger covers.',
            'options' => [
                'grid' => 'Grid',
                'large' => 'Large',
            ],
        ],
        'photo_grid_layout' => [
            'label' => 'Photo layout',
            'help' => 'Justified rows keep each photo\'s shape. Square crops show a uniform grid.',
            'options' => [
                'justified_rows' => 'Justified rows',
                'square_crops' => 'Square crops',
            ],
        ],
        'landing_on_home' => [
            'label' => 'Show above the album list',
            'help' => 'Opens the home page with this landing page; the albums follow below it. Visitors who are not logged in still get the landing page alone while public albums are turned off.',
            'options' => [
                'never' => 'Never',
                'guests' => 'To visitors who are not logged in',
                'everyone' => 'To everyone',
            ],
        ],
        'landing_layout' => [
            'label' => 'Layout',
            'options' => [
                'text_only' => 'Text only',
                'text_and_image' => 'Text and image',
                'full_screen_image' => 'Full-screen image',
            ],
        ],
        'landing_heading' => [
            'label' => 'Heading',
            'help' => 'Leave empty to use the site name.',
        ],
        'landing_text' => [
            'label' => 'Text',
        ],
        'landing_image' => [
            'label' => 'Image',
            'help' => 'Used by the "Text and image" and "Full-screen image" layouts. Landscape photos work best.',
        ],
        'landing_show_register' => [
            'label' => 'Show "Create account" (when registration is open)',
        ],
        'auth_layout' => [
            'label' => 'Layout',
            'options' => [
                'centered' => 'Centered',
                'form_and_image' => 'Form and image',
                'form_on_an_image' => 'Form on an image',
            ],
        ],
        'auth_image' => [
            'label' => 'Image',
            'help' => 'Used by the image layouts.',
        ],
        'auth_caption' => [
            'label' => 'Caption on the image',
            'help' => 'Optional, e.g. "Photos of the club since 1987".',
        ],
        'custom_css' => [
            'label' => 'CSS',
            'help' => 'Use the colour tokens, e.g. color: rgb(var(--og-primary));',
        ],
    ],
    'palettes' => [
        'neutral' => 'Black and white',
        'moss' => 'Moss',
        'exp' => 'Experimental',
    ],
];
