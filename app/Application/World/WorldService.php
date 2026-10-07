<?php

namespace App\Application\World;

use App\Application\Battle\BattleService;
use App\Application\Support\GameAction;
use App\Models\BattleReport;
use App\Models\User;

final class WorldService
{
    public function __construct(private GameAction $actions, private BattleService $battles) {}

    public function simulate(int $userId, string $key, array $party, bool $remember, int $actionLimit = 50): array
    {
        $party = array_map('intval', $party);
        $this->actions->ensure(in_array($actionLimit, [10, 50], true), 'Invalid simulation limit.');

        return $this->actions->execute($userId, 'world.simulation', $key, compact('party', 'remember', 'actionLimit'), function (User $user, int $operation, int $seed) use ($party, $remember, $actionLimit) {
            $report = $this->battles->simulateParties($user, $party, $user, $party, 'simulation', $seed, $actionLimit);
            if ($remember) {
                $user->preferences = [...($user->preferences ?? []), 'party' => $party];
                $user->save();
            }
            $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'simulation', 'public' => false, 'report' => $report]);

            return ['report_id' => $record->id, 'message' => 'Simulation completed. No rewards or injuries were saved.'];
        });
    }
}
