<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Domain\Dungeon\DungeonMap;

/** Fog-of-war map projection for the run screen. Unvisited rooms reveal nothing unless scouted. */
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
        // Crop to the visible rooms so the drawing does not reveal the dungeon's full extent.
        $xs = $ys = [];
        foreach (array_keys($visible) as $id) {
            [$xs[], $ys[]] = $map->room($id)['pos'];
        }
        [$minX, $minY] = [min($xs), min($ys)];
        $point = static fn (array $room): array => [self::PAD + ($room['pos'][0] - $minX) * self::CELL_W, self::PAD + ($room['pos'][1] - $minY) * self::CELL_H];
        $rooms = [];
        foreach ($map->rooms() as $id => $room) {
            if (! isset($visible[$id])) {
                continue;
            }
            $known = $state[$id]['visited'] ?? false;
            // Scouted rooms show their name and type before anyone has entered them.
            $identified = $known || ($state[$id]['scouted'] ?? false);
            [$x, $y] = $point($room);
            $rooms[] = [
                'id' => $id, 'x' => $x, 'y' => $y, 'known' => $known, 'scouted' => ! $known && $identified, 'current' => $id === $current,
                'cleared' => $state[$id]['cleared'] ?? false, 'type' => $identified ? $room['type'] : 'unknown',
                'label' => $identified ? $room['name'] : '？', 'kind' => $identified ? self::TYPES[$room['type']].($known ? '' : '（侦察）') : '未探索',
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

        return ['width' => 2 * self::PAD + (max($xs) - $minX) * self::CELL_W, 'height' => 2 * self::PAD + (max($ys) - $minY) * self::CELL_H, 'rooms' => $rooms, 'edges' => $edges];
    }
}
