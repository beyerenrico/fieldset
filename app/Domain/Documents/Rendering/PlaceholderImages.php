<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use GdImage;

/** Deterministic placeholder images for the builder's "Preview PDF" (no real attachments exist). */
final class PlaceholderImages
{
    private static ?string $photo = null;

    private static ?string $signature = null;

    public static function photo(): string
    {
        return self::$photo ??= self::draw(800, 600, static function (GdImage $img): void {
            imagefilledrectangle($img, 0, 0, 799, 599, (int) imagecolorallocate($img, 226, 232, 240));
            $shape = (int) imagecolorallocate($img, 148, 163, 184);
            imagefilledpolygon($img, [120, 480, 330, 230, 520, 480], $shape);
            imagefilledpolygon($img, [380, 480, 540, 300, 700, 480], $shape);
            imagefilledellipse($img, 610, 170, 110, 110, $shape);
        });
    }

    public static function signature(): string
    {
        return self::$signature ??= self::draw(600, 200, static function (GdImage $img): void {
            imagesavealpha($img, true);
            imagealphablending($img, false);
            imagefilledrectangle($img, 0, 0, 599, 199, (int) imagecolorallocatealpha($img, 255, 255, 255, 127));
            imagealphablending($img, true);
            imagesetthickness($img, 4);
            $ink = (int) imagecolorallocate($img, 30, 58, 138);
            $points = [];
            for ($x = 40; $x <= 560; $x += 8) {
                $points[] = [$x, (int) round(110 + 45 * sin($x / 23) * cos($x / 71))];
            }
            for ($i = 1, $n = count($points); $i < $n; $i++) {
                imageline($img, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $ink);
            }
        });
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     * @param  callable(GdImage): void  $paint
     */
    private static function draw(int $width, int $height, callable $paint): string
    {
        $img = imagecreatetruecolor($width, $height);
        $paint($img);
        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
