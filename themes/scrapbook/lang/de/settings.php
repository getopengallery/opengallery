<?php

return [
    'sections' => [
        'colours' => [
            'label' => 'Farben und Papier',
        ],
        'whimsy' => [
            'label' => 'Verspieltheit',
            'description' => 'Wie viel los ist. Schalte Dinge ab, wenn es ruhiger sein soll.',
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
            'description' => 'Wird nicht angemeldeten Besuchern gezeigt, solange öffentliche Alben ausgeschaltet sind (Einstellungen → Zugang).',
        ],
        'auth' => [
            'label' => 'Anmeldung und Registrierung',
        ],
        'custom_css' => [
            'label' => 'Eigenes CSS',
            'description' => 'Wird auf jeder Seite der Website (nicht im Adminbereich) nach den Styles des Themes eingefügt.',
        ],
    ],
    'fields' => [
        'palette' => [
            'label' => 'Palette',
            'help' => 'Legt die Farben von Seite, Kopfzeile und Stickern fest, im hellen und dunklen Modus.',
        ],
        'custom_colors' => [
            'label' => 'Eigene Farben verwenden',
            'help' => 'Ersetzt die Farben der Palette, die du unten festlegst. Sticker-Farben kommen immer aus der Palette.',
        ],
        'radius' => [
            'label' => 'Ecken',
            'options' => [
                'from_the_palette' => 'Aus der Palette',
                'snipped' => 'Abgeschnitten',
                'rounded' => 'Abgerundet',
                'bubbly' => 'Blubberig',
            ],
        ],
        'display_font' => [
            'label' => 'Schrift der Überschriften',
            'help' => 'Verwendet Schriften, die auf dem Gerät des Besuchers vorhanden sind, und sieht daher überall etwas anders aus.',
            'options' => [
                'from_the_palette' => 'Aus der Palette',
                'handwritten' => 'Handschrift',
                'rounded' => 'Abgerundet',
                'chunky_serif' => 'Fette Serifenschrift',
            ],
        ],
        'paper' => [
            'label' => 'Papier',
            'options' => [
                'dots' => 'Gepunktet',
                'grid' => 'Kariert',
                'confetti' => 'Konfetti',
                'plain' => 'Schlicht',
            ],
        ],
        'wobble' => [
            'label' => 'Fotos und Alben schief einkleben',
            'help' => 'Abzüge sitzen etwas schräg und richten sich auf, wenn man auf sie zeigt.',
        ],
        'doodles' => [
            'label' => 'Kritzeleien verstreuen',
        ],
        'ticker' => [
            'label' => 'Laufband unter der Kopfzeile anzeigen',
        ],
        'ticker_text' => [
            'label' => 'Text des Laufbands',
            'help' => 'Leer lassen für den Text des Themes.',
        ],
        'header_pages' => [
            'label' => 'Seiten in der Kopfzeile',
        ],
        'header_mode_toggle' => [
            'label' => 'Schalter für hell/dunkel anzeigen',
        ],
        'header_sticky' => [
            'label' => 'Kopfzeile beim Scrollen sichtbar lassen',
        ],
        'footer_text' => [
            'label' => 'Text',
            'help' => 'Leer lassen für die Zeile des Themes.',
        ],
        'footer_pages' => [
            'label' => 'Seiten in der Fußzeile',
        ],
        'photo_grid_layout' => [
            'label' => 'Foto-Layout',
            'help' => 'Stapel behält das Format jedes Fotos in vollen Spalten. Polaroid-Wand zeigt quadratische Abzüge mit ihren Titeln.',
            'options' => [
                'pile' => 'Stapel',
                'polaroids' => 'Polaroid-Wand',
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
            'help' => 'Wird neben dem Text in die Collage geklebt.',
        ],
        'landing_show_register' => [
            'label' => '„Konto erstellen" anzeigen (wenn die Registrierung offen ist)',
        ],
        'auth_caption' => [
            'label' => 'Notiz unter dem Formular',
            'help' => 'Leer lassen für die Notiz des Themes.',
        ],
        'custom_css' => [
            'label' => 'CSS',
            'help' => 'Verwende die Farb-Tokens, z. B. color: rgb(var(--og-pop-1));',
        ],
    ],
    'palettes' => [
        'bubblegum' => 'Kaugummi',
        'lemonade' => 'Limonade',
        'circus' => 'Zirkus',
    ],
];
