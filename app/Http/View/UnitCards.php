<?php

declare(strict_types=1);

namespace App\Http\View;

/** Pure projections: cards expose display data, never database models. */
final class UnitCards
{
    // Both abandoned-town sprites are absent in the preserved archive.
    public const LAND_FALLBACKS = ['aband' => 'build01'];

    public static function character(array $character, array $job): array
    {
        $gender = (int) ($character['gender'] ?? 0) === 1 ? 'female' : 'male';

        return [
            'id' => $character['id'], 'name' => $character['name'], 'level' => (int) $character['level'],
            'label' => $job['name_'.$gender], 'position' => ($character['position'] ?? 'front') === 'front' ? '前卫' : '后卫',
            'img' => 'image/char/'.basename($job['img_'.$gender]),
            'star' => (int) ($character['stat_points'] ?? 0) > 0,
            'vitals' => array_intersect_key($character['stats'] ?? [], array_flip(['hp', 'maxhp', 'sp', 'maxsp'])),
        ];
    }

    public static function job(array $job, int|string $id, int $gender = 0): array
    {
        $sex = $gender === 1 ? 'female' : 'male';

        return ['id' => $id, 'name' => $job['name_'.$sex], 'level' => null, 'label' => '', 'img' => 'image/char/'.basename($job['img_'.$sex])];
    }

    public static function monster(array $monster, int|string $id): array
    {
        return ['id' => $id, 'name' => $monster['name'], 'level' => isset($monster['level']) ? (int) $monster['level'] : null,
            'label' => '', 'img' => 'image/char/'.basename($monster['img']),
            'base' => 'image/other/land_'.basename(self::LAND_FALLBACKS[$monster['land'] ?? 'grass'] ?? $monster['land'] ?? 'grass').'.gif'];
    }

    public static function boss(array $boss): array
    {
        return ['id' => $boss['id'], 'name' => $boss['name'], 'level' => null,
            'label' => '等级上限 '.($boss['limit'] ?? '不限'),
            'img' => 'image/char/'.basename($boss['img'] ?? 'mon_145.gif'), 'base' => 'image/other/land_sea.gif'];
    }
}
