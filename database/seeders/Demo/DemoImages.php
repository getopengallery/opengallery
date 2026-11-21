<?php

namespace Database\Seeders\Demo;

use GdImage;

/**
 * Draws the demo photos and profile pictures with GD, so the demo needs no
 * network access and ships no image files. Every image is deterministic for
 * its seed, so re-running the demo produces the same pictures.
 */
class DemoImages
{
    /** Motifs, each with its own drawing and title words. */
    public const MOTIFS = ['hills', 'mountains', 'sea', 'forest', 'city', 'lights'];

    private const RATIOS = [[1440, 960], [960, 1440], [1440, 810], [1440, 1080], [1080, 1080], [1440, 617], [1080, 1440], [1440, 960]];

    /** [sky top, sky bottom, land] per motif; one is picked per image. */
    private const PALETTES = [
        'hills' => [
            [[96, 140, 190], [222, 196, 160], [52, 70, 48]],
            [[150, 186, 214], [232, 236, 236], [96, 120, 72]],
            [[238, 168, 120], [250, 226, 190], [120, 92, 64]],
        ],
        'mountains' => [
            [[70, 110, 160], [200, 214, 228], [56, 66, 84]],
            [[34, 46, 84], [214, 140, 108], [30, 32, 44]],
            [[120, 150, 190], [236, 232, 224], [84, 96, 110]],
        ],
        'sea' => [
            [[90, 150, 200], [236, 222, 196], [32, 84, 120]],
            [[40, 52, 96], [236, 136, 96], [28, 44, 76]],
            [[170, 200, 220], [242, 242, 236], [70, 120, 140]],
        ],
        'forest' => [
            [[160, 196, 210], [236, 236, 220], [30, 64, 46]],
            [[214, 190, 150], [244, 230, 200], [44, 58, 36]],
            [[120, 140, 150], [210, 214, 206], [26, 44, 38]],
        ],
        'city' => [
            [[10, 14, 34], [60, 50, 96], [16, 18, 28]],
            [[20, 24, 40], [120, 70, 90], [22, 20, 30]],
            [[8, 20, 36], [40, 90, 120], [12, 18, 26]],
        ],
        'lights' => [
            [[30, 16, 40], [90, 40, 60], [255, 190, 110]],
            [[12, 20, 40], [40, 60, 100], [140, 200, 255]],
            [[40, 20, 14], [100, 50, 30], [255, 220, 150]],
        ],
    ];

    /** Title words per motif: "{adjective} {noun}". */
    private const WORDS = [
        'hills' => [['Golden', 'Misty', 'Quiet', 'Rolling', 'Late', 'Soft', 'Green', 'Windy'], ['hills', 'valley', 'meadow', 'fields', 'slopes', 'horizon']],
        'mountains' => [['Northern', 'Jagged', 'Blue', 'Distant', 'Snowy', 'Morning', 'Silent', 'High'], ['ridge', 'peaks', 'summit', 'pass', 'range', 'col']],
        'sea' => [['Calm', 'Low', 'Evening', 'Bright', 'Hazy', 'Open', 'Silver', 'Stormy'], ['tide', 'harbour', 'shore', 'bay', 'cliffs', 'sea']],
        'forest' => [['Tall', 'Dark', 'Morning', 'Mossy', 'Silent', 'Deep', 'Old', 'Misty'], ['pines', 'trail', 'clearing', 'woods', 'grove', 'path']],
        'city' => [['Neon', 'Late', 'Rainy', 'Quiet', 'Busy', 'Midnight', 'Electric', 'Empty'], ['street', 'skyline', 'crossing', 'rooftops', 'avenue', 'blocks']],
        'lights' => [['Warm', 'Festive', 'Evening', 'Bright', 'Golden', 'Soft', 'Late', 'Glowing'], ['lights', 'crowd', 'stage', 'reception', 'toast', 'glow']],
    ];

    /** A photo title for this motif and seed, e.g. "Misty valley". */
    public static function title(string $motif, int $seed): string
    {
        [$adjectives, $nouns] = self::WORDS[$motif];

        return $adjectives[$seed % count($adjectives)].' '.$nouns[intdiv($seed, count($adjectives)) % count($nouns)];
    }

    /** Draws one photo and returns the path of a temporary JPEG. */
    public static function photo(string $motif, int $seed): string
    {
        mt_srand($seed);
        [$w, $h] = self::RATIOS[$seed % count(self::RATIOS)];
        $palettes = self::PALETTES[$motif];
        [$top, $bottom, $land] = $palettes[$seed % count($palettes)];

        $img = imagecreatetruecolor($w, $h);
        $horizon = (int) ($h * (0.5 + mt_rand(-8, 14) / 100));
        self::gradient($img, $top, $bottom, 0, $horizon);

        match ($motif) {
            'hills' => self::hills($img, $horizon, $land, $bottom),
            'mountains' => self::mountains($img, $horizon, $land, $bottom),
            'sea' => self::sea($img, $horizon, $land, $bottom),
            'forest' => self::forest($img, $horizon, $land, $bottom),
            'city' => self::city($img, $horizon, $land),
            'lights' => self::lights($img, $land, $bottom),
        };

        return self::save($img, 'jpg');
    }

    /** A square, abstract profile picture (soft circles on a gradient). */
    public static function avatar(int $seed): string
    {
        mt_srand($seed * 7919);
        $size = 512;
        $img = imagecreatetruecolor($size, $size);
        $hue = mt_rand(0, 359);
        self::gradient($img, self::hsl($hue, 0.55, 0.62), self::hsl(($hue + 40) % 360, 0.6, 0.38), 0, $size);
        imagealphablending($img, true);

        for ($i = 0; $i < 4; $i++) {
            $color = self::hsl(($hue + 150 + $i * 30) % 360, 0.7, 0.7);
            $alpha = imagecolorallocatealpha($img, ...[...$color, 70]);
            $r = mt_rand(140, 300);
            imagefilledellipse($img, mt_rand(0, $size), mt_rand(0, $size), $r, $r, $alpha);
        }

        return self::save($img, 'png');
    }

    // ---- Motifs ------------------------------------------------------------

    /** @param array{int, int, int} $land */
    private static function hills(GdImage $img, int $horizon, array $land, array $haze): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        self::sun($img, $horizon, [255, 240, 210]);
        imagefilledrectangle($img, 0, $horizon, $w, $h, self::color($img, self::mix($haze, $land, 0.3)));

        foreach ([[0.45, 0.6], [0.75, 0.8], [1.0, 1.0]] as [$depth, $amp]) {
            $color = self::color($img, self::mix($haze, $land, $depth));
            $phase = mt_rand(0, 628) / 100;
            $freq = mt_rand(8, 20) / 10;
            $base = $horizon + (int) (($depth - 0.45) * $h * 0.25);

            for ($x = 0; $x < $w; $x += 2) {
                $y = $base - (int) (sin($x / $w * M_PI * $freq + $phase) * $h * 0.06 * $amp);
                imagefilledrectangle($img, $x, $y, $x + 1, $h, $color);
            }
        }
    }

    /** @param array{int, int, int} $land */
    private static function mountains(GdImage $img, int $horizon, array $land, array $haze): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];

        foreach ([0.35, 0.65, 1.0] as $layer => $depth) {
            $rgb = self::mix($haze, $land, $depth);
            $snow = self::mix($rgb, [245, 248, 252], 0.75);
            $base = $horizon + (int) ($layer * $h * 0.1);
            $x = -mt_rand(0, 200);

            while ($x < $w) {
                $width = mt_rand((int) ($w * 0.18), (int) ($w * 0.38));
                $peak = $base - mt_rand((int) ($h * 0.12), (int) ($h * 0.32)) * (1.2 - $layer * 0.25);
                $mid = $x + $width / 2 + mt_rand(-40, 40);
                imagefilledpolygon($img, [$x, $h, $x, $base, (int) $mid, (int) $peak, $x + $width, $base, $x + $width, $h], self::color($img, $rgb));

                // Snow on the top fifth of the nearer peaks.
                if ($layer < 2) {
                    $t = 0.22;
                    imagefilledpolygon($img, [
                        (int) $mid, (int) $peak,
                        (int) ($mid + ($x + $width - $mid) * $t), (int) ($peak + ($base - $peak) * $t),
                        (int) $mid, (int) ($peak + ($base - $peak) * $t * 0.7),
                        (int) ($mid - ($mid - $x) * $t), (int) ($peak + ($base - $peak) * $t),
                    ], self::color($img, $snow));
                }

                $x += (int) ($width * 0.6);
            }
        }
    }

    /** @param array{int, int, int} $water */
    private static function sea(GdImage $img, int $horizon, array $water, array $light): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $sunX = (int) ($w * mt_rand(25, 75) / 100);
        imagefilledellipse($img, $sunX, $horizon - (int) ($h * 0.08), (int) ($h * 0.12), (int) ($h * 0.12), self::color($img, self::mix($light, [255, 250, 230], 0.6)));
        self::gradient($img, self::mix($water, $light, 0.35), $water, $horizon, $h);

        // Glitter on the water below the sun.
        imagealphablending($img, true);
        for ($i = 0; $i < 90; $i++) {
            $y = mt_rand($horizon + 4, $h);
            $spread = ($y - $horizon) * 0.5 + 20;
            $x = $sunX + mt_rand((int) -$spread, (int) $spread);
            $len = mt_rand(10, 60);
            imagefilledrectangle($img, $x, $y, $x + $len, $y + 2, imagecolorallocatealpha($img, ...[...self::mix($light, [255, 255, 255], 0.5), mt_rand(40, 100)]));
        }

        // A headland on one side.
        $left = mt_rand(0, 1) === 0;
        $cliff = self::color($img, self::mix($water, [20, 24, 20], 0.6));
        $cw = (int) ($w * mt_rand(18, 35) / 100);
        $top = $horizon - (int) ($h * mt_rand(6, 16) / 100);
        imagefilledpolygon($img, $left
            ? [0, $h, 0, $top, (int) ($cw * 0.6), $top + 10, $cw, $horizon + 20, $cw, $h]
            : [$w, $h, $w, $top, $w - (int) ($cw * 0.6), $top + 10, $w - $cw, $horizon + 20, $w - $cw, $h], $cliff);
    }

    /** @param array{int, int, int} $land */
    private static function forest(GdImage $img, int $horizon, array $land, array $haze): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];

        foreach ([0.4, 0.7, 1.0] as $row => $depth) {
            $color = self::color($img, self::mix($haze, $land, $depth));
            $ground = $horizon + (int) ($row * $h * 0.12);
            imagefilledrectangle($img, 0, $ground, $w, $h, $color);
            $treeWidth = (int) ($w * (0.03 + $row * 0.02));

            for ($x = -$treeWidth; $x < $w + $treeWidth; $x += (int) ($treeWidth * mt_rand(55, 95) / 100)) {
                $height = (int) ($h * (0.12 + $row * 0.1) * mt_rand(70, 130) / 100);
                imagefilledpolygon($img, [$x - $treeWidth, $ground + 2, $x, $ground - $height, $x + $treeWidth, $ground + 2], $color);
            }
        }
    }

    /** @param array{int, int, int} $shade */
    private static function city(GdImage $img, int $horizon, array $shade): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $star = self::color($img, [230, 230, 255]);
        for ($i = 0; $i < 120; $i++) {
            imagesetpixel($img, mt_rand(0, $w), mt_rand(0, $horizon), $star);
        }

        $windowColors = [self::color($img, [255, 214, 130]), self::color($img, [255, 236, 190]), self::color($img, [150, 200, 255])];
        foreach ([0.6, 1.0] as $depth) {
            $building = self::color($img, self::mix([60, 60, 90], $shade, $depth));
            $x = -mt_rand(0, 40);
            while ($x < $w) {
                $bw = mt_rand((int) ($w * 0.05), (int) ($w * 0.12));
                $top = $horizon - mt_rand((int) ($h * 0.05), (int) ($h * ($depth === 1.0 ? 0.4 : 0.3)));
                imagefilledrectangle($img, $x, $top, $x + $bw, $h, $building);

                if ($depth === 1.0) {
                    for ($wy = $top + 12; $wy < $h - 12; $wy += 18) {
                        for ($wx = $x + 8; $wx < $x + $bw - 10; $wx += 16) {
                            if (mt_rand(0, 100) < 38) {
                                imagefilledrectangle($img, $wx, $wy, $wx + 7, $wy + 9, $windowColors[mt_rand(0, 2)]);
                            }
                        }
                    }
                }

                $x += $bw + mt_rand(0, 12);
            }
        }
    }

    /** Out-of-focus lights, as at an evening event. @param array{int, int, int} $glow */
    private static function lights(GdImage $img, array $glow, array $base): void
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        self::gradient($img, self::mix($base, [0, 0, 0], 0.5), $base, 0, $h);
        imagealphablending($img, true);

        for ($i = 0; $i < 70; $i++) {
            $r = mt_rand((int) ($h * 0.03), (int) ($h * 0.14));
            $tint = self::mix($glow, [255, 255, 255], mt_rand(0, 40) / 100);
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), $r, $r, imagecolorallocatealpha($img, ...[...$tint, mt_rand(70, 112)]));
        }

        // Silhouettes of people in the foreground.
        $people = self::color($img, [10, 8, 12]);
        for ($x = mt_rand(-40, 20); $x < $w; $x += mt_rand((int) ($w * 0.08), (int) ($w * 0.16))) {
            $head = (int) ($h * mt_rand(6, 9) / 100);
            $top = $h - (int) ($h * mt_rand(22, 34) / 100);
            imagefilledellipse($img, $x, $top, $head, (int) ($head * 1.2), $people);
            imagefilledellipse($img, $x, $h, (int) ($head * 3.2), (int) (($h - $top) * 1.6), $people);
        }
    }

    // ---- Helpers ---------------------------------------------------------------

    private static function sun(GdImage $img, int $horizon, array $rgb): void
    {
        $size = (int) (imagesy($img) * 0.1);
        imagefilledellipse($img, (int) (imagesx($img) * mt_rand(20, 80) / 100), $horizon - (int) ($size * 1.2), $size, $size, self::color($img, $rgb));
    }

    /** Vertical gradient from $from (at $y1) to $to (at $y2). */
    private static function gradient(GdImage $img, array $from, array $to, int $y1, int $y2): void
    {
        $w = imagesx($img);
        $span = max(1, $y2 - $y1);
        for ($y = $y1; $y < $y2; $y++) {
            imageline($img, 0, $y, $w, $y, self::color($img, self::mix($from, $to, ($y - $y1) / $span)));
        }
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     * @return array{int, int, int}
     */
    private static function mix(array $a, array $b, float $t): array
    {
        return array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $t), $a, $b);
    }

    private static function color(GdImage $img, array $rgb): int
    {
        return imagecolorallocate($img, ...$rgb);
    }

    /** @return array{int, int, int} */
    private static function hsl(int $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (intdiv($h, 60)) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x],
            3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return [(int) (($r + $m) * 255), (int) (($g + $m) * 255), (int) (($b + $m) * 255)];
    }

    private static function save(GdImage $img, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'og-demo');
        @unlink($base);
        $path = $base.'.'.$extension;
        $extension === 'png' ? imagepng($img, $path) : imagejpeg($img, $path, 85);

        return $path;
    }
}
