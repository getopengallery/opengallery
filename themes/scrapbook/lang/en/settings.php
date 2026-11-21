<?php

/*
 * The Scrapbook theme's settings form (theme.json), used as
 * __('theme-scrapbook::settings.…').
 */
return [
    'sections' => [
        'colours' => [
            'label' => 'Colours and paper',
        ],
        'whimsy' => [
            'label' => 'Whimsy',
            'description' => 'How much is going on. Turn things off for a calmer page.',
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
            'description' => 'Shown to visitors who are not logged in while public albums are turned off (Settings → Access).',
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
            'help' => 'Sets the page, header and sticker colours, in light and dark mode.',
        ],
        'custom_colors' => [
            'label' => 'Use my own colours',
            'help' => 'Overrides the palette for the colours you set below. Sticker colours always come from the palette.',
        ],
        'radius' => [
            'label' => 'Corners',
            'options' => [
                'from_the_palette' => 'From the palette',
                'snipped' => 'Snipped',
                'rounded' => 'Rounded',
                'bubbly' => 'Bubbly',
            ],
        ],
        'display_font' => [
            'label' => 'Headline lettering',
            'help' => 'Uses fonts already on the visitor\'s device, so it looks a little different everywhere.',
            'options' => [
                'from_the_palette' => 'From the palette',
                'handwritten' => 'Handwritten',
                'rounded' => 'Rounded',
                'chunky_serif' => 'Chunky serif',
            ],
        ],
        'paper' => [
            'label' => 'Paper',
            'options' => [
                'dots' => 'Dotted',
                'grid' => 'Grid paper',
                'confetti' => 'Confetti',
                'plain' => 'Plain',
            ],
        ],
        'wobble' => [
            'label' => 'Tilt photos and albums',
            'help' => 'Prints sit a little crooked and straighten up when pointed at.',
        ],
        'doodles' => [
            'label' => 'Scatter doodles',
        ],
        'ticker' => [
            'label' => 'Show the scrolling ticker under the header',
        ],
        'ticker_text' => [
            'label' => 'Ticker text',
            'help' => 'Leave empty for the theme\'s own text.',
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
        'footer_text' => [
            'label' => 'Text',
            'help' => 'Leave empty for the theme\'s own line.',
        ],
        'footer_pages' => [
            'label' => 'Pages in the footer',
        ],
        'photo_grid_layout' => [
            'label' => 'Photo layout',
            'help' => 'Pile keeps each photo\'s shape in crowded columns. Polaroid wall shows square prints with their titles.',
            'options' => [
                'pile' => 'Pile',
                'polaroids' => 'Polaroid wall',
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
            'help' => 'Taped into the collage next to the text.',
        ],
        'landing_show_register' => [
            'label' => 'Show "Create account" (when registration is open)',
        ],
        'auth_caption' => [
            'label' => 'Note under the form',
            'help' => 'Leave empty for the theme\'s own note.',
        ],
        'custom_css' => [
            'label' => 'CSS',
            'help' => 'Use the colour tokens, e.g. color: rgb(var(--og-pop-1));',
        ],
    ],
    'palettes' => [
        'bubblegum' => 'Bubblegum',
        'lemonade' => 'Lemonade',
        'circus' => 'Circus',
    ],
];
