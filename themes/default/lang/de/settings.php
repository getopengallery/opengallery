<?php

/*
 * The Default theme's own texts (theme.json settings form), used as
 * __('theme-default::settings.…'). Core texts live in /lang.
 */
return [
    'sections' => [
        'colours' => [
            'label' => 'Farben',
        ],
        'header' => [
            'label' => 'Kopfzeile',
        ],
        'footer' => [
            'label' => 'Fußzeile',
        ],
        'albums' => [
            'label' => 'Alben',
        ],
        'landing' => [
            'label' => 'Startseite',
            'description' => 'Wird nicht angemeldeten Besuchern gezeigt, solange öffentliche Alben ausgeschaltet sind (Einstellungen → Zugang). Sie kann auch die Albenliste auf der Startseite eröffnen.',
        ],
        'auth' => [
            'label' => 'Anmeldung und Registrierung',
        ],
        'custom_css' => [
            'label' => 'Eigenes CSS',
            'description' => 'Wird auf jeder Seite der Website (nicht in der Verwaltung) nach den Styles des Themes eingefügt.',
        ],
    ],
    'fields' => [
        'palette' => [
            'label' => 'Palette',
            'help' => 'Jede Palette enthält passende helle und dunkle Farben.',
        ],
        'custom_colors' => [
            'label' => 'Eigene Farben verwenden',
            'help' => 'Überschreibt die Palette für die Farben, die du unten festlegst. Lass eine Farbe leer, um die der Palette zu behalten.',
        ],
        'radius' => [
            'label' => 'Ecken',
            'options' => [
                'from_the_palette' => 'Aus der Palette',
                'square' => 'Eckig',
                'slightly_rounded' => 'Leicht abgerundet',
                'rounded' => 'Abgerundet',
            ],
        ],
        'font' => [
            'label' => 'Schrift',
            'help' => 'System und Serif verwenden Schriften, die auf dem Gerät der Besucher bereits vorhanden sind.',
            'options' => [
                'from_the_palette' => 'Aus der Palette',
                'hanken_grotesk' => 'Hanken Grotesk',
                'system' => 'System',
                'serif' => 'Serif',
            ],
        ],
        'header_layout' => [
            'label' => 'Layout',
            'help' => 'Klassisch: Logo links, Links rechts. Zentriert: Logo über den Links. Minimal: Logo und ein Menüknopf.',
            'options' => [
                'classic' => 'Klassisch',
                'centered' => 'Zentriert',
                'minimal' => 'Minimal',
            ],
        ],
        'header_pages' => [
            'label' => 'Seiten in der Kopfzeile',
        ],
        'header_mode_toggle' => [
            'label' => 'Hell/Dunkel-Schalter zeigen',
        ],
        'header_sticky' => [
            'label' => 'Kopfzeile beim Scrollen sichtbar halten',
        ],
        'footer_layout' => [
            'label' => 'Layout',
            'options' => [
                'one_line' => 'Eine Zeile',
                'columns' => 'Spalten',
                'no_footer' => 'Keine Fußzeile',
            ],
        ],
        'footer_text' => [
            'label' => 'Text',
            'help' => 'Zum Beispiel eine kurze Beschreibung, ein Copyright-Hinweis oder Kontaktdaten.',
        ],
        'footer_pages' => [
            'label' => 'Seiten in der Fußzeile',
            'help' => 'Zum Beispiel Impressum und Datenschutzerklärung.',
        ],
        'album_grid_layout' => [
            'label' => 'Albumkacheln',
            'help' => 'Raster zeigt bis zu vier Alben pro Reihe. Groß zeigt zwei mit größeren Titelbildern.',
            'options' => [
                'grid' => 'Raster',
                'large' => 'Groß',
            ],
        ],
        'photo_grid_layout' => [
            'label' => 'Fotolayout',
            'help' => 'Bündige Reihen behalten die Form jedes Fotos. Quadratische Ausschnitte zeigen ein gleichmäßiges Raster.',
            'options' => [
                'justified_rows' => 'Bündige Reihen',
                'square_crops' => 'Quadratische Ausschnitte',
            ],
        ],
        'landing_on_home' => [
            'label' => 'Über der Albenliste zeigen',
            'help' => 'Die Startseite beginnt mit dieser Seite, die Alben folgen darunter. Nicht angemeldete Besucher sehen weiterhin nur die Startseite, solange öffentliche Alben ausgeschaltet sind.',
            'options' => [
                'never' => 'Nie',
                'guests' => 'Nicht angemeldeten Besuchern',
                'everyone' => 'Allen',
            ],
        ],
        'landing_layout' => [
            'label' => 'Layout',
            'options' => [
                'text_only' => 'Nur Text',
                'text_and_image' => 'Text und Bild',
                'full_screen_image' => 'Bild im Vollbild',
            ],
        ],
        'landing_heading' => [
            'label' => 'Überschrift',
            'help' => 'Leer lassen, um den Namen der Website zu verwenden.',
        ],
        'landing_text' => [
            'label' => 'Text',
        ],
        'landing_image' => [
            'label' => 'Bild',
            'help' => 'Für die Layouts „Text und Bild“ und „Bild im Vollbild“. Querformatige Fotos wirken am besten.',
        ],
        'landing_show_register' => [
            'label' => '„Konto erstellen“ zeigen (wenn die Registrierung offen ist)',
        ],
        'auth_layout' => [
            'label' => 'Layout',
            'options' => [
                'centered' => 'Zentriert',
                'form_and_image' => 'Formular und Bild',
                'form_on_an_image' => 'Formular auf einem Bild',
            ],
        ],
        'auth_image' => [
            'label' => 'Bild',
            'help' => 'Für die Layouts mit Bild.',
        ],
        'auth_caption' => [
            'label' => 'Bildunterschrift',
            'help' => 'Optional, z. B. „Fotos des Vereins seit 1987“.',
        ],
        'custom_css' => [
            'label' => 'CSS',
            'help' => 'Verwende die Farb-Tokens, z. B. color: rgb(var(--og-primary));',
        ],
    ],
    'palettes' => [
        'neutral' => 'Schwarz-Weiß',
        'moss' => 'Moos',
        'exp' => 'Experimentell',
    ],
];
