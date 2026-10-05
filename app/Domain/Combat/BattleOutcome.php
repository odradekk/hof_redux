<?php

declare(strict_types=1);

namespace App\Domain\Combat;

final readonly class BattleOutcome
{
    public function __construct(
        public ?int $winner,
        public string $reason,
        public array $teams,
        public array $events,
        public int $actions,
        public array $randomState,
        public array $rewardCandidates,
        public array $damage,
        public string $mode,
        public string $contentVersion,
        public string $rulesVersion = 'hof-combat-type1-v1',
        public array $retired = [],
        public array $magicCircles = [0, 0],
    ) {}
}
