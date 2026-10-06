<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Pure boundary helper; inputs are already-resolved content, never model objects. */
final class SnapshotFactory
{
    /** Equipment is keyed by slot (weapon/shield/armor/item); enchants/refinement already resolved. */
    public static function character(array $base, array $equipment = [], array $passives = []): array
    {
        $base['baseStats'] = array_intersect_key($base, array_flip(['str', 'int', 'dex', 'spd', 'luk']));
        $flat = array_fill_keys(['MAXHP', 'MAXSP', 'STR', 'INT', 'DEX', 'SPD', 'LUK'], 0);
        $percent = ['MAXHP' => 0, 'MAXSP' => 0];
        $base['atk'] = [0, 0];
        $base['def'] = [0, 0, 0, 0];
        $base['SPECIAL'] = $base['SPECIAL'] ?? [];
        foreach ($passives as $skill) {
            foreach ($flat as $stat => $unused) {
                $flat[$stat] += (int) ($skill['P_'.$stat] ?? $skill['p_'.strtolower($stat)] ?? 0);
            }
        }
        foreach ($equipment as $slot => $item) {
            if ($slot === 'weapon') {
                $base['weaponType'] = $item['type'] ?? '';
            }
            foreach ([0, 1] as $i) {
                $base['atk'][$i] += (int) ($item['atk'][$i] ?? 0);
            }
            foreach ([0, 1, 2, 3] as $i) {
                $base['def'][$i] += (int) ($item['def'][$i] ?? 0);
            }
            foreach ($flat as $stat => $unused) {
                $flat[$stat] += (int) ($item['P_'.$stat] ?? 0);
            }
            foreach ($percent as $stat => $unused) {
                $percent[$stat] += (int) ($item['M_'.$stat] ?? 0);
            }
            $base['SPECIAL']['Summon'] = ($base['SPECIAL']['Summon'] ?? 0) + (int) ($item['P_SUMMON'] ?? 0);
            foreach ([0, 1] as $i) {
                $base['SPECIAL']['Pierce'][$i] = ($base['SPECIAL']['Pierce'][$i] ?? 0) + (int) ($item['P_PIERCE'][$i] ?? 0);
            }
        }
        foreach (['hp', 'sp'] as $r) {
            $stat = 'MAX'.strtoupper($r);
            $max = (int) ($base['max'.$r] ?? ($r === 'hp' ? 1 : 0));
            $base[$r] = (int) round(($base[$r] ?? $max) * (1 + $percent[$stat] / 100) + $flat[$stat]);
            $base['max'.$r] = (int) round($max * (1 + $percent[$stat] / 100) + $flat[$stat]);
        }
        foreach (['str', 'int', 'dex', 'spd', 'luk'] as $stat) {
            $base[$stat] = (int) ($base[$stat] ?? 0) + $flat[strtoupper($stat)];
        }
        $base['monster'] = false;
        $base['summon'] = false;

        return $base;
    }

    /** Content resolves population formulas first. Random placement/drop use the same explicit stream. */
    public static function monster(array $content, string $id, RandomSource $random, bool $boss = false): array
    {
        $content['id'] = $id;
        $content['monster'] = true;
        $content['boss'] = $boss;
        if (empty($content['position'])) {
            $content['position'] = $random->integer(0, 1) ? 'front' : 'back';
        }
        if (! empty($content['itemtable'])) {
            $roll = $random->integer(1, 10000);
            $sum = 0;
            foreach ($content['itemtable'] as $item => $weight) {
                $sum += (int) $weight;
                if ($roll <= $sum) {
                    $content['itemdrop'] = (string) $item;
                    break;
                }
            }
        }

        // No persistence or external mutation; normalize catalog scalars only at the boundary.
        return self::withMonsterImage($content, $id, $random);
    }

    /** Resolve appearance once without advancing the gameplay/drop random stream. */
    public static function withMonsterImage(array $content, string $id, RandomSource $random): array
    {
        if (empty($content['img']) && ! empty($content['image_variants'])) {
            $variants = array_values($content['image_variants']);
            $state = json_encode(['monster-image-v1', $random->state(), $id], JSON_THROW_ON_ERROR);
            $appearance = new SeededRandom((int) hexdec(substr(hash('sha256', $state), 0, 8)));
            $content['img'] = $variants[$appearance->integer(0, count($variants) - 1)];
        }

        return $content;
    }
}
