<?php

declare(strict_types=1);

namespace App\Application\Battle;

use App\Domain\Content\ContentCatalog;

/** Legacy cssimage geometry, represented as CSP-safe SVG presentation attributes. */
final class BattleStage
{
    // The archived abandoned-area artwork is missing; reuse the intact building scene.
    public const BACKGROUND_FALLBACKS = ['aband' => 'build01'];

    private array $characters = ['NoImage.gif' => true, 'mon_145.gif' => true, 'mon_110r.gif' => true, 'mon_149r.gif' => true];

    private array $backgrounds = ['grass' => true, 'colosseum' => true];

    private static array $sizes = [];

    public function __construct(ContentCatalog $catalog, private ?string $publicDirectory = null)
    {
        $this->publicDirectory ??= dirname(__DIR__, 3).'/public';
        foreach (['jobs', 'monsters'] as $kind) {
            foreach ($catalog->all($kind) as $row) {
                foreach (['img', 'img_male', 'img_female'] as $field) {
                    if (isset($row[$field]) && is_string($row[$field]) && basename($row[$field]) === $row[$field]) {
                        $this->characters[$row[$field]] = true;
                    }
                }
                if (isset($row['land'])) {
                    $this->backgrounds[$row['land']] = true;
                }
            }
        }
        foreach ($catalog->all('areas') as $row) {
            $this->backgrounds[$row['land']] = true;
        }
    }

    public function present(array $teams, string $background = 'grass', array $circles = [0, 0]): array
    {
        $land = basename($background) === $background && isset($this->backgrounds[$background]) ? $background : 'grass';
        $land = self::BACKGROUND_FALLBACKS[$land] ?? $land;
        $backgroundPath = 'image/other/bg_'.$land.'.gif';
        if (! is_file($this->publicDirectory.'/'.$backgroundPath)) {
            $backgroundPath = 'image/other/bg_grass.gif';
        }
        $stage = ['background' => $backgroundPath, 'circles' => [], 'sprites' => []];
        foreach ([0, 1] as $team) {
            $count = max(0, min(5, (int) ($circles[$team] ?? 0)));
            if ($count > 0) {
                $path = 'image/other/mc'.$team.'_'.$count.'.gif';
                [$width, $height] = $this->size($path);
                $stage['circles'][] = ['src' => $path, 'x' => $team === 0 ? 280 : 0, 'y' => 0, 'width' => $width, 'height' => $height];
            }
        }
        // Preserve the old back-to-front paint order independently on each side.
        foreach ([1, 0] as $team) {
            foreach (['back', 'front'] as $position) {
                $row = array_values(array_filter($teams[$team] ?? [], static fn (array $unit): bool => ($unit['position'] ?? 'front') === $position && ! (($unit['summon'] ?? false) && (int) ($unit['state'] ?? 0) === 1)));
                $direction = $team === 0 ? 1 : 0;
                $axis = $direction ? ($position === 'front' ? 320 : 400) : ($position === 'front' ? 160 : 80);
                foreach ($row as $index => $unit) {
                    $filename = (int) ($unit['state'] ?? 0) === 1 ? 'mon_145.gif' : (string) ($unit['img'] ?? 'NoImage.gif');
                    if (basename($filename) !== $filename || ! isset($this->characters[$filename])) {
                        $filename = 'NoImage.gif';
                    }
                    $path = 'image/'.($direction ? 'char_rev' : 'char').'/'.$filename;
                    $mirror = false;
                    if (! is_file($this->publicDirectory.'/'.$path)) {
                        $opposite = 'image/'.($direction ? 'char' : 'char_rev').'/'.$filename;
                        if (is_file($this->publicDirectory.'/'.$opposite)) {
                            $path = $opposite;
                            $mirror = true;
                        } else {
                            $path = 'image/char/NoImage.gif';
                            $mirror = (bool) $direction;
                        }
                    }
                    [$width, $height] = $this->size($path);
                    $x = (int) floor($axis + ($direction ? -40 : 40) + 80 / (count($row) + 1) * ($direction ? 1 : -1) * ($index + 1)) - (int) round($width / 2);
                    $y = (int) floor(200 / (count($row) + 1) * ($index + 1)) - (int) round($height / 2);
                    $stage['sprites'][] = ['id' => (string) ($unit['id'] ?? ''), 'src' => $path, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'transform' => $mirror ? 'translate('.(2 * $x + $width).' 0) scale(-1 1)' : null];
                }
            }
        }

        return $stage;
    }

    private function size(string $path): array
    {
        $absolute = $this->publicDirectory.'/'.$path;
        if (! isset(self::$sizes[$absolute])) {
            $size = is_file($absolute) ? getimagesize($absolute) : false;
            self::$sizes[$absolute] = $size ? [(int) $size[0], (int) $size[1]] : [48, 48];
        }

        return self::$sizes[$absolute];
    }
}
