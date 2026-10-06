<?php

namespace App\Application\World;

use App\Application\Battle\BattleService;
use App\Application\Player\Vitals;
use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\BattleReport;
use App\Models\Character;
use App\Models\User;

final class WorldService
{
    public const HUNT_STAMINA = 1;

    public function __construct(private GameAction $actions, private ContentCatalog $catalog, private BattleService $battles, private Vitals $vitals) {}

    public function areas(User $user): array
    {
        $inventory = $user->inventory()->where('location', 'warehouse')->get()->groupBy('item_id')->map->sum('quantity')->all();

        return $this->catalog->availableAreas($inventory, now()->toDateTimeImmutable());
    }

    public function hunt(int $userId, string $key, string $areaId, array $party, bool $remember): array
    {
        $party = array_map('intval', $party);

        return $this->actions->execute($userId, 'world.hunt', $key, compact('areaId', 'party', 'remember'), function (User $user, int $operation, int $seed) use ($areaId, $party, $remember) {
            $areas = $this->areas($user);
            $this->actions->ensure(isset($areas[$areaId]), 'This map is not available.');
            $characters = Character::where('user_id', $user->id)->whereIn('id', $party)->lockForUpdate()->get();
            $this->actions->ensure($characters->count() === count(array_unique($party)), 'Party contains an unavailable character.');
            foreach ($characters as $character) {
                $this->vitals->spendStamina($character, self::HUNT_STAMINA, $operation, 'ordinary hunt');
            }
            $report = $this->battles->fight($user, $party, $areas[$areaId], $operation, $seed);
            if ($remember) {
                $user->preferences = [...($user->preferences ?? []), 'party' => $party];
                $user->save();
            }
            $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'pve', 'public' => (bool) ($user->preferences['record_battle_log'] ?? true), 'report' => $report]);

            return ['report_id' => $record->id, 'message' => 'Battle completed.'];
        });
    }

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
