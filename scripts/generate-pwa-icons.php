<?php
/**
 * PWA icon generator - draws the app icon with the GD extension and writes
 * the PNGs into public/assets/icons/.
 *
 * Usage:  php scripts/generate-pwa-icons.php
 * Output: icon-192.png (192x192, motif drawn natively at this size),
 *         icon-512.png (512x512, full-bleed motif),
 *         icon-512-maskable.png (512x512, motif at 70% for the maskable safe zone)
 *
 * Tweak the design ONLY via the constants below, then re-run the script and
 * commit script + PNGs together so they never drift apart.
 *
 * Requires ext-gd (bundled with typical WAMP installs): php -m | grep gd
 */

declare(strict_types=1);

/* ---- Design constants (the only places to touch for visual changes) ---- */
const COLOR_TOP = '#14532d';          // gradient top
const COLOR_BOTTOM = '#16a34a';       // gradient bottom
const MOTIF_COLOR = '#ffffff';        // book + base bar
const CORNER_RADIUS_RATIO = 0.22;     // rounded-square corner radius / canvas size
const BOOK_WIDTH_RATIO = 0.52;        // open-book width / canvas (at motif scale 1)
const BOOK_HEIGHT_RATIO = 0.30;       // open-book height / canvas
const BOOK_CENTER_Y_RATIO = 0.44;     // book vertical center, slightly above middle
const SPINE_GAP_RATIO = 0.02;         // gap between the two pages / canvas
const BAR_WIDTH_RATIO = 0.44;         // base bar width / canvas
const BAR_HEIGHT_RATIO = 0.07;        // base bar height / canvas
const BAR_GAP_RATIO = 0.06;           // gap between book bottom and bar / canvas
const MASKABLE_MOTIF_SCALE = 0.70;    // motif scale for the maskable variant (20% safe zone)
const SUPERSAMPLE = 4;                // render 4x, downscale once: antialiasing without
                                      // blurring thin shapes (each size is drawn natively)

const OUTPUT_DIR = __DIR__ . '/../public/assets/icons';

/** @return array{0: int, 1: int, 2: int} */
function hexToRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Filled rounded rectangle. Uses the native imagefilledroundedrectangle()
 * on PHP >= 8.1 when available, otherwise a manual rect + end-ellipse fallback.
 */
function filledRoundedRect(
    GdImage $img,
    float $x0,
    float $y0,
    float $x1,
    float $y1,
    float $radius,
    int $color
): void {
    if (PHP_VERSION_ID >= 80100 && function_exists('imagefilledroundedrectangle')) {
        imagefilledroundedrectangle($img, (int)round($x0), (int)round($y0), (int)round($x1), (int)round($y1), (int)round($radius), $color);
        return;
    }
    // Manual fallback: middle rectangle + two end ellipses.
    $r = min($radius, ($x1 - $x0) / 2, ($y1 - $y0) / 2);
    imagefilledrectangle($img, (int)round($x0 + $r), (int)round($y0), (int)round($x1 - $r), (int)round($y1), $color);
    imagefilledellipse($img, (int)round($x0 + $r), (int)round(($y0 + $y1) / 2), (int)round(2 * $r), (int)round($y1 - $y0), $color);
    imagefilledellipse($img, (int)round($x1 - $r), (int)round(($y0 + $y1) / 2), (int)round(2 * $r), (int)round($y1 - $y0), $color);
}

/**
 * Draw one icon variant. The background (rounded square + vertical gradient)
 * always fills the whole canvas; the motif (book + bar) is scaled by
 * $motifScale so the maskable variant keeps the 20% safe zone.
 */
function drawIcon(int $size, float $motifScale): GdImage
{
    $ss = SUPERSAMPLE;
    $big = $size * $ss;
    $img = imagecreatetruecolor($big, $big);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imageantialias($img, true);

    // Background: vertical linear gradient, one scanline at a time, clipped
    // to the rounded square (corner radius = CORNER_RADIUS_RATIO * size).
    [$tr, $tg, $tb] = hexToRgb(COLOR_TOP);
    [$br, $bg, $bb] = hexToRgb(COLOR_BOTTOM);
    $radius = CORNER_RADIUS_RATIO * $big;
    for ($y = 0; $y < $big; $y++) {
        $t = $big <= 1 ? 0.0 : $y / ($big - 1);
        $color = imagecolorallocate(
            $img,
            (int)round($tr + ($br - $tr) * $t),
            (int)round($tg + ($bg - $tg) * $t),
            (int)round($tb + ($bb - $tb) * $t)
        );
        $inset = 0.0;
        if ($y < $radius) {
            $inset = $radius - sqrt($radius * $radius - ($radius - $y) * ($radius - $y));
        } elseif ($y > $big - 1 - $radius) {
            $dy = $y - ($big - 1 - $radius);
            $inset = $radius - sqrt($radius * $radius - $dy * $dy);
        }
        imageline($img, (int)round($inset), $y, (int)round($big - 1 - $inset), $y, $color);
    }

    // Motif, centered, white.
    [$mr, $mg, $mb] = hexToRgb(MOTIF_COLOR);
    $motif = imagecolorallocate($img, $mr, $mg, $mb);
    $cx = $big / 2;
    $cy = BOOK_CENTER_Y_RATIO * $big;
    $bw = BOOK_WIDTH_RATIO * $big * $motifScale;
    $bh = BOOK_HEIGHT_RATIO * $big * $motifScale;
    $gap = SPINE_GAP_RATIO * $big * $motifScale;
    $pw = ($bw - $gap) / 2;
    $top = $cy - $bh / 2;
    $bottom = $cy + $bh / 2;
    $fan = $bh * 0.10; // outer page edges sit slightly lower: fanned open book

    // Left page (quadrilateral), mirrored right page, narrow spine gap.
    imagefilledpolygon($img, [
        (int)round($cx - $gap / 2 - $pw), (int)round($top + $fan),
        (int)round($cx - $gap / 2), (int)round($top),
        (int)round($cx - $gap / 2), (int)round($bottom),
        (int)round($cx - $gap / 2 - $pw), (int)round($bottom + $fan),
    ], $motif);
    imagefilledpolygon($img, [
        (int)round($cx + $gap / 2), (int)round($top),
        (int)round($cx + $gap / 2 + $pw), (int)round($top + $fan),
        (int)round($cx + $gap / 2 + $pw), (int)round($bottom + $fan),
        (int)round($cx + $gap / 2), (int)round($bottom),
    ], $motif);

    // Single horizontal rounded bar directly below the book.
    $barW = BAR_WIDTH_RATIO * $big * $motifScale;
    $barH = BAR_HEIGHT_RATIO * $big * $motifScale;
    $barY = $bottom + BAR_GAP_RATIO * $big * $motifScale;
    filledRoundedRect($img, $cx - $barW / 2, $barY, $cx + $barW / 2, $barY + $barH, $barH / 2, $motif);

    // Downscale the supersampled canvas once to the exact target size.
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);
    imagedestroy($img);
    return $out;
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "ext-gd is not enabled - install/enable GD (e.g. php-gd) and re-run.\n");
    exit(1);
}
$nativeRounded = PHP_VERSION_ID >= 80100 && function_exists('imagefilledroundedrectangle');
fwrite(STDOUT, 'PHP ' . PHP_VERSION . ', rounded-rect path: ' . ($nativeRounded ? 'native imagefilledroundedrectangle' : 'manual fallback') . "\n");

if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0775, true);
}
$targets = [
    'icon-192.png' => [192, 1.0],
    'icon-512.png' => [512, 1.0],
    'icon-512-maskable.png' => [512, MASKABLE_MOTIF_SCALE],
];
foreach ($targets as $file => [$size, $scale]) {
    $img = drawIcon($size, $scale);
    $path = OUTPUT_DIR . '/' . $file;
    imagepng($img, $path);
    imagedestroy($img);
    fwrite(STDOUT, 'wrote ' . $path . ' (' . $size . 'x' . $size . ", motif scale $scale)\n");
}
