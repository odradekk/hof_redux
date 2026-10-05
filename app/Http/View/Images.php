<?php

declare(strict_types=1);

namespace App\Http\View;

final class Images
{
    /** Return intrinsic sizes only for trusted local asset paths. */
    public static function size(string $path): array
    {
        static $sizes = [];
        if (! preg_match('~\Aimage/(?:char/|char_rev/|icon/|other/|manual/)?[A-Za-z0-9_.-]+\z~', $path) || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid image path.');
        }
        if (! isset($sizes[$path])) {
            $size = getimagesize(public_path($path));
            if ($size === false) {
                throw new \UnexpectedValueException('Missing image: '.$path);
            }
            $sizes[$path] = [$size[0], $size[1]];
        }

        return $sizes[$path];
    }
}
