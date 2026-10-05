<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Stateless façade: callers resolve catalog/equipment before entering the simulator. */
final readonly class BattleEngine
{
    public function __construct(private array $skills, private array $summons = [])
    {
        SkillCoverage::validate($skills);
    }

    public function simulate(BattleSnapshot $snapshot, RandomSource $random): BattleOutcome
    {
        return (new BattleRun($this->skills, $this->summons, $snapshot, $random))->run();
    }
}
