<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Dungeon\DungeonMap;

/** Fog-of-war map projection for the run screen. Unvisited rooms reveal nothing. */
final class DungeonMapView
{
    public const TYPES = ['empty' => '空房间', 'battle' => '战斗', 'chest' => '宝箱', 'trap' => '陷阱', 'rest' => '休息处', 'event' => '事件', 'exit' => '出口'];

    private const CELL_W = 112;

    private const CELL_H = 72;

    private const PAD = 52;

    /** @param array<string, array> $state per-room run state */
    public static function build(DungeonMap $map, array $state, string $current): array
    {
        $visited = array_keys(array_filter($state, static fn (array $room): bool => $room['visited'] ?? false));
        $visible = array_flip($map->visible($visited));
        $maxX = $maxY = 0;
        foreach ($map->rooms() as $room) {
            $maxX = max($maxX, $room['pos'][0]);
            $maxY = max($maxY, $room['pos'][1]);
        }
        $point = static fn (array $room): array => [self::PAD + $room['pos'][0] * self::CELL_W, self::PAD + $room['pos'][1] * self::CELL_H];
        $rooms = [];
        foreach ($map->rooms() as $id => $room) {
            if (! isset($visible[$id])) {
                continue;
            }
            $known = $state[$id]['visited'] ?? false;
            [$x, $y] = $point($room);
            $rooms[] = [
                'id' => $id, 'x' => $x, 'y' => $y, 'known' => $known, 'current' => $id === $current,
                'cleared' => $state[$id]['cleared'] ?? false, 'type' => $known ? $room['type'] : 'unknown',
                'label' => $known ? $room['name'] : '？', 'kind' => $known ? self::TYPES[$room['type']] : '未探索',
            ];
        }
        $edges = [];
        foreach ($map->edges() as [$a, $b]) {
            if (isset($visible[$a], $visible[$b]) && (($state[$a]['visited'] ?? false) || ($state[$b]['visited'] ?? false))) {
                [$x1, $y1] = $point($map->room($a));
                [$x2, $y2] = $point($map->room($b));
                $edges[] = ['x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'known' => ($state[$a]['visited'] ?? false) && ($state[$b]['visited'] ?? false)];
            }
        }

        return ['width' => 2 * self::PAD + $maxX * self::CELL_W, 'height' => 2 * self::PAD + $maxY * self::CELL_H, 'rooms' => $rooms, 'edges' => $edges];
    }
}
