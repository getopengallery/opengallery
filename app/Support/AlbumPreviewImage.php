<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Extensions\ThemeManager;
use App\Models\Album;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Link preview cards for albums that are not open to everyone: the cover,
 * blurred, with a badge in the middle. Unlisted albums (shared by link) get
 * a link badge, password-protected albums a lock and a much stronger blur,
 * so the card shows colours, never the photo. Public albums use their plain
 * cover instead (see PageMeta::album()).
 *
 * Cards are JPEGs of WIDTH × HEIGHT (the shape preview apps show largest),
 * made with GD from the cover's `thumb` conversion on first use and kept on
 * the private `local` disk under DIRECTORY, named after the album, the badge
 * and the cover file, so a new cover makes a new card. Served by
 * AlbumController::sharedCover() and ::protectedCover().
 */
class AlbumPreviewImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public const DISK = 'local';

    public const DIRECTORY = 'link-previews';

    public const LINK = 'link';

    public const LOCK = 'lock';

    /** Raise to make every card again (after changing how they look). */
    private const VERSION = 1;

    /** Width the cover is shrunk to before it is enlarged again: smaller = more blur. */
    private const BLUR_WIDTH = [self::LINK => 48, self::LOCK => 12];

    /** Which card an album gets in link previews: lock for a password, link for unlisted, none for open albums. */
    public static function badgeFor(Album $album): ?string
    {
        return match (true) {
            $album->hasPassword() => self::LOCK,
            $album->visibility === Visibility::Unlisted => self::LINK,
            default => null,
        };
    }

    /**
     * Path of the album's card on DISK, made if needed. Null when the album
     * has no cover with a `thumb` conversion or the image cannot be read.
     */
    public static function path(Album $album, string $badge): ?string
    {
        $media = PageMeta::coverOf($album)?->original();

        if (! $media || ! $media->hasGeneratedConversion(PageMeta::IMAGE_CONVERSION)) {
            return null;
        }

        $source = Storage::disk($media->conversions_disk);
        $sourcePath = $media->getPathRelativeToRoot(PageMeta::IMAGE_CONVERSION);

        if (! $source->exists($sourcePath)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $fingerprint = substr(hash('xxh128', implode('|', [self::VERSION, $media->id, $sourcePath, $source->lastModified($sourcePath), ...array_values(self::colors())])), 0, 16);
        $path = self::DIRECTORY."/{$album->id}-{$badge}-{$fingerprint}.jpg";

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $card = self::render((string) $source->get($sourcePath), $badge);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($card === null) {
            return null;
        }

        self::forget($album);
        $disk->put($path, $card);

        return $path;
    }

    /** Removes the album's cards (a new cover or badge makes a new one; deleting the album removes them). */
    public static function forget(Album $album): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (str_starts_with(basename($file), $album->id.'-')) {
                $disk->delete($file);
            }
        }
    }

    /** The card as JPEG data, or null if GD cannot read the cover. */
    private static function render(string $imageData, string $badge): ?string
    {
        $cover = @imagecreatefromstring($imageData);

        if (! $cover instanceof GdImage) {
            return null;
        }

        $card = self::blurredCover($cover, self::BLUR_WIDTH[$badge] ?? self::BLUR_WIDTH[self::LOCK]);

        // Darken a little, so the white badge stands out on light photos.
        imagealphablending($card, true);
        imagefilledrectangle($card, 0, 0, self::WIDTH, self::HEIGHT, imagecolorallocatealpha($card, 0, 0, 0, 90));

        // A soft shadow under the badge, so it stands apart on light photos too.
        $size = 240;
        for ($spread = 48; $spread >= 0; $spread -= 2) {
            imagefilledellipse($card, (int) (self::WIDTH / 2), (int) (self::HEIGHT / 2) + 6, $size + $spread, $size + $spread, imagecolorallocatealpha($card, 0, 0, 0, 126));
        }
        imagecopyresampled($card, self::badge($badge), (int) ((self::WIDTH - $size) / 2), (int) ((self::HEIGHT - $size) / 2), 0, 0, $size, $size, $size * 2, $size * 2);

        ob_start();
        imagejpeg($card, null, 82);

        return (string) ob_get_clean();
    }

    /** The cover cut to the card's shape and blurred by shrinking it to $tinyWidth and enlarging it again. */
    private static function blurredCover(GdImage $cover, int $tinyWidth): GdImage
    {
        [$width, $height] = [imagesx($cover), imagesy($cover)];
        $ratio = self::WIDTH / self::HEIGHT;

        // The middle of the cover in the card's shape.
        [$cropWidth, $cropHeight] = $width / $height > $ratio
            ? [(int) round($height * $ratio), $height]
            : [$width, (int) round($width / $ratio)];

        $tinyHeight = max(1, (int) round($tinyWidth / $ratio));
        $tiny = imagecreatetruecolor($tinyWidth, $tinyHeight);
        imagecopyresampled($tiny, $cover, 0, 0, (int) (($width - $cropWidth) / 2), (int) (($height - $cropHeight) / 2), $tinyWidth, $tinyHeight, $cropWidth, $cropHeight);

        // Enlarge in steps, blurring at each, so the card has no visible blocks.
        $step = $tiny;
        while (imagesx($step) < 300) {
            $next = imagecreatetruecolor(imagesx($step) * 2, max(1, (int) round(imagesx($step) * 2 / $ratio)));
            imagecopyresampled($next, $step, 0, 0, 0, 0, imagesx($next), imagesy($next), imagesx($step), imagesy($step));
            imagefilter($next, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($next, IMG_FILTER_GAUSSIAN_BLUR);
            $step = $next;
        }

        $card = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($card, $step, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, imagesx($step), imagesy($step));

        return $card;
    }

    /**
     * Outline icons in the site's style (20 × 20 grid, round line ends; the
     * lock is the one on the password page, resources/views/albums/password.blade.php).
     * Paths use M, L, H, V and A commands (absolute or relative).
     */
    private const ICONS = [
        self::LOCK => ['M5.5 9H14.5A1.5 1.5 0 0 1 16 10.5V15.5A1.5 1.5 0 0 1 14.5 17H5.5A1.5 1.5 0 0 1 4 15.5V10.5A1.5 1.5 0 0 1 5.5 9Z', 'M7 9V6.5a3 3 0 0 1 6 0V9'],
        self::LINK => ['M8.5 11.5a3 3 0 0 0 4.24 0l2.5-2.5a3 3 0 0 0-4.24-4.24l-1 1', 'M11.5 8.5a3 3 0 0 0-4.24 0l-2.5 2.5a3 3 0 0 0 4.24 4.24l1-1'],
    ];

    /** Line width of the icons, in grid units (as on the password page). */
    private const ICON_STROKE = 1.6;

    /**
     * The badge like the one on the password page: a circle in the theme's
     * surface colour with the icon in its ink colour. Drawn at twice its size,
     * so scaling it down smooths the edges.
     */
    private static function badge(string $badge): GdImage
    {
        $size = 480;
        $colors = self::colors();
        $image = self::transparentCanvas($size);
        imagealphablending($image, true);
        imagefilledellipse($image, $size / 2, $size / 2, $size - 4, $size - 4, self::allocate($image, $colors['surface']));

        // The icon takes the same share of the circle as on the password page (20 px in 44 px).
        $scale = $size * 20 / 44 / 20;
        $offset = ($size - 20 * $scale) / 2;
        $ink = self::allocate($image, $colors['ink']);
        $radius = (int) round(self::ICON_STROKE * $scale / 2);

        foreach (self::ICONS[$badge] ?? self::ICONS[self::LOCK] as $path) {
            foreach (self::pathPoints($path) as [$x, $y]) {
                imagefilledellipse($image, (int) round($offset + $x * $scale), (int) round($offset + $y * $scale), $radius * 2, $radius * 2, $ink);
            }
        }

        return $image;
    }

    /**
     * Points along an icon path, close enough together (0.05 grid units) that
     * a round brush stamped on each draws the line with round ends and joins.
     *
     * @return list<array{float, float}>
     */
    private static function pathPoints(string $path): array
    {
        preg_match_all('/([MLHVAZmlhvaz])([^MLHVAZmlhvaz]*)/', $path, $commands, PREG_SET_ORDER);

        $points = [];
        $x = $y = $startX = $startY = 0.0;
        $line = function (float $toX, float $toY) use (&$points, &$x, &$y): void {
            $steps = max(1, (int) ceil(hypot($toX - $x, $toY - $y) / 0.05));
            for ($i = 1; $i <= $steps; $i++) {
                $points[] = [$x + ($toX - $x) * $i / $steps, $y + ($toY - $y) * $i / $steps];
            }
            [$x, $y] = [$toX, $toY];
        };

        foreach ($commands as [, $command, $arguments]) {
            preg_match_all('/-?(?:\d+\.?\d*|\.\d+)/', $arguments, $numbers);
            $values = array_map('floatval', $numbers[0]);
            $relative = ctype_lower($command);

            switch (strtoupper($command)) {
                case 'M':
                    [$x, $y] = $relative ? [$x + $values[0], $y + $values[1]] : [$values[0], $values[1]];
                    [$startX, $startY] = [$x, $y];
                    $points[] = [$x, $y];
                    foreach (array_chunk(array_slice($values, 2), 2) as [$toX, $toY]) {
                        $line($relative ? $x + $toX : $toX, $relative ? $y + $toY : $toY);
                    }
                    break;
                case 'L':
                    foreach (array_chunk($values, 2) as [$toX, $toY]) {
                        $line($relative ? $x + $toX : $toX, $relative ? $y + $toY : $toY);
                    }
                    break;
                case 'H':
                    foreach ($values as $toX) {
                        $line($relative ? $x + $toX : $toX, $y);
                    }
                    break;
                case 'V':
                    foreach ($values as $toY) {
                        $line($x, $relative ? $y + $toY : $toY);
                    }
                    break;
                case 'A':
                    foreach (array_chunk($values, 7) as [$rx, $ry, , $large, $sweep, $toX, $toY]) {
                        $toX = $relative ? $x + $toX : $toX;
                        $toY = $relative ? $y + $toY : $toY;
                        foreach (self::arcPoints($x, $y, $toX, $toY, $rx, $ry, (bool) $large, (bool) $sweep) as $point) {
                            $points[] = $point;
                        }
                        [$x, $y] = [$toX, $toY];
                    }
                    break;
                case 'Z':
                    $line($startX, $startY);
                    break;
            }
        }

        return $points;
    }

    /**
     * Points on a circular SVG arc (icons only use circles, so rx = ry and no rotation).
     *
     * @return list<array{float, float}>
     */
    private static function arcPoints(float $x1, float $y1, float $x2, float $y2, float $rx, float $ry, bool $large, bool $sweep): array
    {
        $radius = max($rx, $ry);
        $chord = hypot($x2 - $x1, $y2 - $y1);

        if ($chord === 0.0 || $radius === 0.0) {
            return [[$x2, $y2]];
        }

        $radius = max($radius, $chord / 2);
        $distance = sqrt(max(0, $radius ** 2 - ($chord / 2) ** 2));
        $direction = $large !== $sweep ? 1 : -1;
        [$midX, $midY] = [($x1 + $x2) / 2, ($y1 + $y2) / 2];
        $centerX = $midX + $direction * $distance * -($y2 - $y1) / $chord;
        $centerY = $midY + $direction * $distance * ($x2 - $x1) / $chord;

        $start = atan2($y1 - $centerY, $x1 - $centerX);
        $delta = atan2($y2 - $centerY, $x2 - $centerX) - $start;
        if ($sweep && $delta < 0) {
            $delta += 2 * M_PI;
        } elseif (! $sweep && $delta > 0) {
            $delta -= 2 * M_PI;
        }

        $steps = max(2, (int) ceil(abs($delta) * $radius / 0.05));
        $points = [];
        for ($i = 1; $i <= $steps; $i++) {
            $angle = $start + $delta * $i / $steps;
            $points[] = [$centerX + $radius * cos($angle), $centerY + $radius * sin($angle)];
        }

        return $points;
    }

    /**
     * The active theme's surface and ink colours in light mode (previews know no dark mode).
     *
     * @return array{surface: string, ink: string}
     */
    private static function colors(): array
    {
        [$light] = app(ThemeManager::class)->resolvedVariables();

        return [
            'surface' => AdminTheme::hex((string) ($light['--og-surface'] ?? '')) ?? '#F3F3F3',
            'ink' => AdminTheme::hex((string) ($light['--og-ink'] ?? '')) ?? '#1A1A1A',
        ];
    }

    private static function allocate(GdImage $image, string $hex): int
    {
        [$red, $green, $blue] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocatealpha($image, (int) $red, (int) $green, (int) $blue, 0);
    }

    /** A square, fully transparent canvas that keeps its alpha channel; drawing replaces pixels instead of blending. */
    private static function transparentCanvas(int $size): GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }
}
