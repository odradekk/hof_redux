<?php

declare(strict_types=1);

namespace App\Application\Battle;

use App\Application\Player\ItemDetails;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Application\Player\Vitals;
use App\Application\Support\GameAction;
use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\Fatigue;
use App\Domain\Combat\RandomSource;
use App\Domain\Combat\SeededRandom;
use App\Domain\Combat\SnapshotFactory;
use App\Domain\Content\ContentCatalog;
use App\Models\Character;
use App\Models\User;
use Carbon\CarbonImmutable;

final class BattleService
{
    /** Actions before a battle ends without elimination (draw, or survivor count in the arena). */
    public const ACTION_LIMIT = 100;

    public const SIMULATION_ACTION_LIMIT = 50;

    public function __construct(private ContentCatalog $catalog, private ItemDetails $items, private PlayerService $players, private GameAction $actions) {}

    /**
     * Resolve a party. $wounded starts from stored HP/SP (dungeon battles) instead of full
     * restoration; $fatigue applies the stamina penalty for modes that consume stamina.
     */
    public function party(User $user, array $ids, string $prefix = 'player', bool $wounded = false, bool $fatigue = false): array
    {
        $ids = array_map('intval', $ids);
        $this->actions->ensure(count($ids) >= 1 && count($ids) <= 5 && count(array_unique($ids)) === count($ids), 'Choose one to five different characters.');
        $characters = Character::where('user_id', $user->id)->whereIn('id', $ids)->with('equipment')->get()->keyBy('id');
        $this->actions->ensure($characters->count() === count($ids), 'Party contains an unavailable character.');
        $party = [];
        foreach ($ids as $id) {
            $character = $characters[$id];
            $this->players->refreshVitals($character);
            $base = $character->stats;
            if (! $wounded) {
                // Reference saves growth, never battle injuries; town battles start restored.
                $base['hp'] = $base['maxhp'];
                $base['sp'] = $base['maxsp'];
            }
            $job = $this->catalog->get('jobs', $character->job_id);
            $base += ['id' => $prefix.':'.$id, 'character_id' => $id, 'gender' => $character->gender, 'name' => $character->name, 'level' => $character->level, 'img' => $job[$character->gender ? 'img_female' : 'img_male'] ?? 'NoImage.gif'];
            $base['exp'] = $character->xp;
            $base['experienceThresholds'] = [];
            for ($level = 1; $level < 50; $level++) {
                $base['experienceThresholds'][$level] = PlayerRules::experienceRequired($level);
            }
            $base['position'] = $character->position;
            $base['guard'] = $character->guard_policy['mode'] ?? 'always';
            $base['tactics'] = array_map(static fn (array $row): array => ['condition' => (int) $row['judge'], 'quantity' => (int) $row['quantity'], 'skill' => (int) $row['action']], $character->tactics);
            $equipment = [];
            foreach ($character->equipment as $item) {
                $equipment[$item->slot] = $this->items->resolve($item);
            }
            $passives = [];
            foreach ($character->skills as $skillId) {
                $skill = $this->catalog->get('skills', $skillId);
                if ($skill['passive'] ?? false) {
                    $passives[] = $skill;
                }
            }
            $snapshot = SnapshotFactory::character($base, $equipment, $passives);
            // Dungeon characters do not rest, so their stored stamina is already current.
            $party[] = $fatigue ? Fatigue::apply($snapshot, Vitals::stamina($character, CarbonImmutable::now(), ! $wounded)) : $snapshot;
        }

        return $party;
    }

    public function weighted(array $weights, RandomSource $random): string
    {
        $total = 0;
        foreach ($weights as $weight) {
            $total += max(0, (int) (is_array($weight) ? $weight[0] : $weight));
        }
        if ($total < 1) {
            throw new \UnexpectedValueException('Encounter has no positive weights.');
        }
        $roll = $random->integer(1, $total);
        foreach ($weights as $id => $weight) {
            $roll -= max(0, (int) (is_array($weight) ? $weight[0] : $weight));
            if ($roll <= 0) {
                return (string) $id;
            }
        }
        throw new \LogicException('Weighted selection failed.');
    }

    public function enemies(array $weights, int $count, RandomSource $random, array $specified = []): array
    {
        $ids = $specified;
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->weighted($weights, $random);
        }
        $population = User::count();
        $party = [];
        foreach ($ids as $i => $id) {
            $party[] = SnapshotFactory::monster($this->catalog->monster($id, $population, (bool) $random->integer(0, 1)), 'enemy:'.$i, $random);
        }

        return $party;
    }

    public function simulateParties(User $user, array $partyIds, User $opponent, array $opponentPartyIds, string $mode, int $seed, ?int $actionLimit = null): array
    {
        $this->actions->ensure(in_array($mode, ['pvp', 'simulation'], true), 'Invalid simulation mode.');

        return $this->run([$this->party($user, $partyIds), $this->party($opponent, $opponentPartyIds, 'opponent')], $mode, new SeededRandom($seed), $seed, [$user->name, $opponent->name], $actionLimit);
    }

    /**
     * A dungeon battle starts from current HP/SP with fatigue. Experience settles at once;
     * money is returned in the settlement for the run to hold and items become run loot.
     */
    public function fightDungeon(User $user, array $partyIds, array $weights, int $count, array $fixed, string $land, string $name, int $operationId, int $seed): array
    {
        $random = new SeededRandom($seed);
        $party = $this->party($user, $partyIds, wounded: true, fatigue: true);
        $enemies = $this->enemies($weights, $count, $random, array_map('strval', $fixed));
        $report = $this->run([$party, $enemies], 'pve', $random, $seed, [$user->name, $name]);
        $report['background'] = $land;
        $report['dungeon'] = true;
        $this->settle($user, $report, $operationId, holdLoot: true);

        return $report;
    }

    public function fightBoss(User $user, array $partyIds, array $definition, int $currentHp, int $currentSp, int $operationId, int $seed): array
    {
        $random = new SeededRandom($seed);
        $enemies = $this->enemies($definition['Slave'], (int) ($definition['SlaveAmount'] ?? 4), $random, $definition['SlaveSpecify'] ?? []);
        $definition['hp'] = $currentHp;
        $definition['sp'] = $currentSp;
        $boss = SnapshotFactory::monster($definition, 'boss', $random, true);
        array_splice($enemies, intdiv(count($enemies), 2), 0, [$boss]);
        $report = $this->run([$this->party($user, $partyIds, fatigue: true), $enemies], 'boss', $random, $seed, [$user->name, $definition['UnionName'] ?? $definition['name']]);
        foreach ($report['teams'][1] as $unit) {
            if ($unit['id'] === 'boss') {
                $report['boss_hp'] = $unit['hp'];
                $report['boss_sp'] = $unit['sp'];
            }
        }
        $report['background'] = $definition['land'] ?? 'grass';
        $this->settle($user, $report, $operationId);

        return $report;
    }

    private function summonPrototype(string|int $id): array
    {
        $prototype = $this->catalog->monster($id, 0, true);
        // Unspecified placement must be rolled independently when each summon appears.
        if (! isset($this->catalog->get('monsters', $id)['position'])) {
            unset($prototype['position']);
        }

        return $prototype;
    }

    private function run(array $teams, string $mode, RandomSource $random, int $seed, array $names, ?int $actionLimit = null): array
    {
        $summons = [];
        // Skill 5803 chooses among these source-defined prototypes dynamically.
        foreach ([1018, 1019, 1020, 1021, 5002] as $id) {
            $summons[$id] = $this->summonPrototype($id);
        }
        foreach ($this->catalog->all('skills') as $skill) {
            foreach ((array) ($skill['summon'] ?? []) as $id) {
                $summons[$id] = $this->summonPrototype($id);
            }
        }
        $actionLimit ??= $mode === 'simulation' ? self::SIMULATION_ACTION_LIMIT : self::ACTION_LIMIT;
        $outcome = (new BattleEngine($this->catalog->all('skills'), $summons))->simulate(new BattleSnapshot($teams, $mode, $this->catalog->version(), $actionLimit), $random);

        return ['winner' => $outcome->winner, 'reason' => $outcome->reason, 'teams' => $outcome->teams, 'events' => $outcome->events, 'actions' => $outcome->actions, 'action_limit' => $actionLimit, 'random' => $outcome->randomState, 'rewards' => $outcome->rewardCandidates, 'damage' => $outcome->damage, 'mode' => $mode, 'content_version' => $outcome->contentVersion, 'rules_version' => $outcome->rulesVersion, 'seed' => $seed, 'names' => $names, 'initial_teams' => $teams];
    }

    private function settle(User $user, array &$report, int $operationId, bool $holdLoot = false): void
    {
        $summary = ['money' => 0, 'items' => [], 'experience' => []];
        foreach ($report['rewards'][0] ?? [] as $reward) {
            $summary['money'] += (int) $reward['money'];
            foreach ($reward['items'] ?? [] as $id => $quantity) {
                $this->catalog->get('items', (string) $id);
                $summary['items'][$id] = ($summary['items'][$id] ?? 0) + (int) $quantity;
            }
            $recipients = $reward['recipients'] ?? [];
            if ($recipients && $reward['experience'] > 0) {
                $xp = (int) $reward['experiencePerRecipient'];
                foreach ($recipients as $unitId) {
                    if (! str_starts_with((string) $unitId, 'player:')) {
                        continue;
                    }
                    $id = (int) substr($unitId, 7);
                    $character = Character::where('user_id', $user->id)->findOrFail($id);
                    PlayerRules::grantExperience($character, $xp);
                    $this->players->refreshVitals($character);
                    $character->save();
                    $summary['experience'][$id] = ($summary['experience'][$id] ?? 0) + $xp;
                }
            }
        }
        if ($summary['money'] && ! $holdLoot) {
            $this->actions->money($user, $summary['money'], $operationId, 'battle reward');
        }
        foreach ($summary['items'] as $id => $quantity) {
            $this->actions->addItem($user, (string) $id, $quantity, $operationId, 'battle drop', location: $holdLoot ? 'loot' : 'warehouse');
        }
        $report['settlement'] = $summary;
    }

    public function publicReport(array $report): array
    {
        $report['presentation'] = (new BattlePresenter($this->catalog))->present($report);
        unset($report['seed'],$report['random'],$report['rewards']);
        if (($report['mode'] ?? '') !== 'boss') {
            return $report;
        }
        unset($report['boss_hp'],$report['boss_sp'],$report['damage'],$report['initial_teams']);
        // Boss reports expose narrative events only; arbitrary event payloads can reveal HP.
        foreach ($report['teams'][1] as &$unit) {
            if ($unit['boss'] ?? false) {
                $unit = array_intersect_key($unit, array_flip(['id', 'name', 'img', 'position', 'state', 'boss', 'team']));
            }
        }
        unset($unit);
        $report['events'] = array_map(static fn (array $event): array => array_intersect_key($event, array_flip(['type', 'sequence', 'tick', 'actor', 'target', 'skill', 'amount', 'reason'])), $report['events'] ?? []);

        return $report;
    }
}
