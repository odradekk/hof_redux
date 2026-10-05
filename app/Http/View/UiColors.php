<?php

declare(strict_types=1);

namespace App\Http\View;

final class UiColors
{
    public static function all(): array
    {
        $colors = [];
        foreach (['ff', 'cc', '99', '66', '33', '00'] as $r) {
            foreach (['ff', 'cc', '99', '66', '33', '00'] as $g) {
                foreach (['ff', 'cc', '99', '66', '33', '00'] as $b) {
                    $colors[] = $r.$g.$b;
                }
            }
        }

        return $colors;
    }

    /** Choose an opaque backing so every retained user text color remains readable. */
    public static function backing(string $color): string
    {
        $channels = array_map(static function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(strtolower($color), 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return ($luminance + 0.05) / 0.05 >= 1.05 / ($luminance + 0.05) ? 'dark' : 'light';
    }

    public static function valid(?string $color): bool
    {
        return $color === null || $color === '' || in_array(strtolower($color), self::all(), true);
    }
}
