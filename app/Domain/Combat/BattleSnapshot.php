<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Resolved values only. Arrays are copy-on-write; no persistent entity references. */
final readonly class BattleSnapshot
{
    public function __construct(
        public array $teams,
        public string $mode = 'pve',
        public string $contentVersion = 'unversioned',
        public int $maxActions = 100,
        public int $maxSteps = 10000,
        public int $maxUnits = 1000,
        public array $magicCircles = [0, 0],
    ) {
        if (count($magicCircles) !== 2 || ! isset($magicCircles[0],$magicCircles[1]) || min($magicCircles) < 0 || max($magicCircles) > 5) {
            throw new \InvalidArgumentException('Invalid magic circles');
        }
        if (count($teams) !== 2 || ! isset($teams[0], $teams[1])) {
            throw new \InvalidArgumentException('Exactly two teams required');
        }
        if (! in_array($mode, ['pve', 'pvp', 'boss', 'simulation'], true)) {
            throw new \InvalidArgumentException('Unknown battle mode');
        }
        if ($maxActions < 1 || $maxActions > 10000 || $maxSteps < $maxActions || $maxSteps > 100000 || $maxUnits < 2 || $maxUnits > 10000) {
            throw new \InvalidArgumentException('Invalid battle bounds');
        }
    }
}
