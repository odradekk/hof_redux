<?php

declare(strict_types=1);

namespace App\Application\Dungeon;

use App\Application\Battle\BattleService;
use App\Application\Player\Vitals;
use App\Application\Support\GameAction;
use App\Domain\Character\Attributes;
use App\Domain\Combat\RandomSource;
use App\Domain\Combat\SeededRandom;
use App\Domain\Content\ContentCatalog;
use App\Domain\Dungeon\DungeonMap;
use App\Domain\Dungeon\RoomRules;
use App\Models\BattleReport;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\DungeonRunEvent;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dungeon runs: enter with a party and a pack, move one room at a time, resolve rooms,
 * and settle. Every command runs inside GameAction's transaction and idempotency key.
 *
 * Members at 0 HP are dying (run state `members[id].dying` = moves left) until rescued,
 * which leaves them wounded for the rest of the run; a wounded member at 0 HP dies.
 */
final class DungeonService
{
    public const MOVE_STAMINA = 2;

    public const BATTLE_STAMINA = 5;

    public const ACTIONS = ['open', 'choose', 'rest', 'use', 'leave', 'retreat'];

    /** @var array<string, DungeonMap>|null */
    private ?array $maps = null;

    public function __construct(private GameAction $actions, private ContentCatalog $catalog, private BattleService $battles, private Vitals $vitals) {}

    /** @return array<string, DungeonMap> */
    public function maps(): array
    {
        if ($this->maps === null) {
            $this->maps = [];
            foreach ($this->catalog->all('dungeons') as $id => $definition) {
                $this->maps[(string) $id] = new DungeonMap((string) $id, $definition);
            }
        }

        return $this->maps;
    }

    public function map(string $id): DungeonMap
    {
        return $this->maps()[$id] ?? throw new \InvalidArgumentException('Unknown dungeon.');
    }

    /** Dungeons whose required map item is in the warehouse. */
    public function available(User $user): array
    {
        $owned = InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->pluck('item_id')->map('strval')->all();

        return array_filter($this->maps(), static function (DungeonMap $map) use ($owned): bool {
            $required = $map->definition()['requires_item'] ?? null;

            return $required === null || in_array((string) $required, $owned, true);
        });
    }

    public static function carryCapacity(Character $character): int
    {
        return Attributes::carryCapacity((int) $character->stats['str']);
    }

    /** @param list<array{id: int, quantity: int}> $pack warehouse rows to carry */
    public function enter(int $userId, string $key, string $dungeonId, array $party, array $pack): array
    {
        $party = array_map('intval', $party);
        $pack = array_values(array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'quantity' => (int) $row['quantity']], $pack));

        return $this->actions->execute($userId, 'dungeon.enter', $key, compact('dungeonId', 'party', 'pack'), function (User $user, int $operation, int $seed) use ($dungeonId, $party, $pack): array {
            $this->actions->ensureInTown($user);
            $map = $this->available($user)[$dungeonId] ?? null;
            $this->actions->ensure($map !== null, '这个地下城尚未开放。');
            $this->actions->ensure(count($party) >= 1 && count($party) <= 5 && count(array_unique($party)) === count($party), '请选择 1–5 名不同的角色。');
            $members = Character::where('user_id', $user->id)->whereIn('id', $party)->lockForUpdate()->get()->keyBy('id');
            $this->actions->ensure($members->count() === count($party), '队伍中有无法出战的角色。');
            $this->actions->ensure(count(array_unique(array_column($pack, 'id'))) === count($pack), '背包中的道具重复了。');

            $capacity = $members->sum(fn (Character $member): int => self::carryCapacity($member));
            $weight = 0;
            foreach ($pack as $row) {
                $item = InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->lockForUpdate()->find($row['id']);
                $this->actions->ensure($item !== null, '仓库中没有这件道具。');
                $data = $this->catalog->get('items', $item->item_id);
                $this->actions->ensure(isset($data['restore']), '背包只能携带消耗品。');
                $this->actions->ensure($row['quantity'] >= 1 && $row['quantity'] <= $item->quantity, '携带数量无效。');
                $weight += (int) $data['handle'] * $row['quantity'];
                $this->moveStack($user, $item, $row['quantity'], 'pack', $operation, 'pack for dungeon');
            }
            $this->actions->ensure($weight <= $capacity, "背包超重：{$weight} / {$capacity}。");

            $now = CarbonImmutable::now();
            foreach ($members as $member) {
                $this->vitals->settle($member, $now);
            }
            $entrance = $map->entrance();
            $run = DungeonRun::create([
                'user_id' => $user->id, 'dungeon_id' => $dungeonId, 'content_version' => $this->catalog->version(),
                'party' => $party, 'room' => $entrance, 'rooms' => [$entrance => ['visited' => true, 'cleared' => true]], 'members' => [],
            ]);
            $this->scout($run, $map, $members, $entrance, new SeededRandom($seed));
            $this->log($run, 'enter', $entrance, '队伍进入了「'.$map->name().'」。');

            return ['run_id' => $run->id, 'message' => '进入了「'.$map->name().'」。'];
        });
    }

    public function move(int $userId, string $key, string $room): array
    {
        return $this->actions->execute($userId, 'dungeon.move', $key, compact('room'), function (User $user, int $operation, int $seed) use ($room): array {
            [$run, $map, $members] = $this->active($user);
            $this->actions->ensure($map->adjacent($run->room, $room), '只能移动到相邻的房间。');
            $lines = $this->weaken($user, $run, $members, $operation);
            $standing = $this->standing($run, $members);
            foreach ($standing as $member) {
                $this->vitals->spendStamina($member, self::MOVE_STAMINA, $operation, 'dungeon move', resting: false, clamp: true);
                $this->recoverSp($member);
            }
            $run->previous_room = $run->room;
            $run->room = $room;
            $run->steps++;
            $this->setRoom($run, $room, ['visited' => true]);
            $definition = $map->room($room);
            $random = new SeededRandom($seed);
            $this->scout($run, $map, $standing, $room, $random);
            $this->log($run, 'move', $room, '前往「'.$definition['name'].'」。'.implode('', $lines));
            if (! ($run->rooms[$room]['cleared'] ?? false)) {
                match ($definition['type']) {
                    'empty' => $this->setRoom($run, $room, ['cleared' => true]),
                    'battle' => $this->battle($user, $run, $map, $members, $operation, $seed),
                    'trap' => $this->trap($user, $run, $definition, $members, $operation, $random),
                    default => null,
                };
            }
            $run->save();

            return $this->result($run);
        });
    }

    /** Room and pack actions: open, choose, rest, use, leave, retreat. */
    public function act(int $userId, string $key, string $action, array $input = []): array
    {
        $this->actions->ensure(in_array($action, self::ACTIONS, true), '无法识别此操作。');
        $input = array_map('intval', array_intersect_key($input, array_flip(['choice', 'item', 'character'])));

        return $this->actions->execute($userId, 'dungeon.'.$action, $key, $input, function (User $user, int $operation, int $seed) use ($action, $input): array {
            [$run, $map, $members] = $this->active($user);
            $definition = $map->room($run->room);
            $state = $run->rooms[$run->room] ?? [];
            $random = new SeededRandom($seed);
            switch ($action) {
                case 'open':
                    $this->actions->ensure($definition['type'] === 'chest' && ! ($state['cleared'] ?? false), '这里没有可以打开的宝箱。');
                    $standing = $this->standing($run, $members);
                    foreach ($standing as $member) {
                        $this->vitals->spendStamina($member, (int) ($definition['open_stamina'] ?? 0), $operation, 'dungeon chest', resting: false, clamp: true);
                    }
                    $loot = RoomRules::chest($definition, $random, $this->highest($standing, 'luk'));
                    $run->loot_money += $loot['money'];
                    $found = [];
                    foreach ($loot['items'] as $id => $quantity) {
                        $this->actions->addItem($user, (string) $id, $quantity, $operation, 'dungeon chest', location: 'loot');
                        $found[] = $this->catalog->get('items', $id)['name'].' ×'.$quantity;
                    }
                    $this->setRoom($run, $run->room, ['cleared' => true]);
                    $this->log($run, 'chest', $run->room, '打开了宝箱：$ '.number_format($loot['money']).($found ? '，'.implode('，', $found) : '').'。'.($loot['bonus'] ? '运气不错，多找到了一件。' : ''));
                    break;
                case 'choose':
                    $this->actions->ensure($definition['type'] === 'event' && ! ($state['cleared'] ?? false), '这里没有需要做出选择的事件。');
                    $choice = $input['choice'] ?? -1;
                    $this->actions->ensure(isset($definition['choices'][$choice]), '请选择有效的选项。');
                    $outcome = RoomRules::eventOutcome($definition, $choice, $random, $this->highest($this->standing($run, $members), 'luk'));
                    $this->setRoom($run, $run->room, ['cleared' => true]);
                    [$summary, $lines] = $this->applyEffects($user, $run, $members, $outcome['effects'], $operation);
                    $lines = [...$lines, ...$this->fall($user, $run, $members, $operation)];
                    $this->log($run, 'event', $run->room, '「'.$definition['choices'][$choice]['label'].'」'.$outcome['text'].($summary ? '（'.implode('，', $summary).'）' : '').implode('', $lines));
                    $this->wipeIfDown($user, $run, $members, $operation);
                    break;
                case 'rest':
                    $uses = $state['uses'] ?? ($definition['uses'] ?? 0);
                    $this->actions->ensure($definition['type'] === 'rest' && $uses > 0, '这里不能休息了。');
                    // Healing comes first so that a rescued member also recovers SP and stamina.
                    [$summary, $lines] = $this->applyEffects($user, $run, $members, [['heal_percent' => (int) ($definition['heal_percent'] ?? 0)], ['sp_percent' => (int) ($definition['heal_percent'] ?? 0)], ['stamina' => (int) ($definition['stamina'] ?? 0)]], $operation);
                    $this->setRoom($run, $run->room, ['uses' => $uses - 1, 'cleared' => $uses - 1 === 0]);
                    $this->log($run, 'rest', $run->room, '休息了一会儿'.($summary ? '（'.implode('，', $summary).'）' : '').'。'.implode('', $lines));
                    break;
                case 'use':
                    $member = $members->get($input['character'] ?? 0);
                    $this->actions->ensure($member !== null, '请选择队伍中存活的角色。');
                    $item = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->lockForUpdate()->find($input['item'] ?? 0);
                    $this->actions->ensure($item !== null, '背包中没有这件道具。');
                    $data = $this->catalog->get('items', $item->item_id);
                    $this->actions->ensure(isset($data['restore']), '这件道具不能使用。');
                    $dying = $this->isDying($run, $member->id);
                    $this->actions->ensure(! $dying || isset($data['restore']['hp']), '濒死的同伴只能用恢复生命的道具救起。');
                    $this->actions->takeItem($user, $item->id, 1, $operation, 'dungeon consumable', 'pack');
                    $summary = $this->restore($member, $data['restore'], $operation);
                    $this->log($run, 'item', $run->room, $member->name.' 使用了'.$data['name'].'（'.implode('，', $summary).'）。'.($dying ? $this->rescue($run, $member) : ''));
                    break;
                case 'leave':
                    $this->actions->ensure($definition['type'] === 'exit', '只有在出口才能离开地下城。');
                    $reward = $map->definition()['clear_reward'] ?? [];
                    $run->loot_money += (int) ($reward['money'] ?? 0);
                    foreach ($reward['items'] ?? [] as $id => $quantity) {
                        $this->actions->addItem($user, (string) $id, (int) $quantity, $operation, 'dungeon clear reward', location: 'loot');
                    }
                    $this->finish($user, $run, $members, 'cleared', $operation);
                    break;
                case 'retreat':
                    $this->finish($user, $run, $members, 'retreated', $operation);
                    break;
            }
            $run->save();

            return $this->result($run);
        });
    }

    /** Locked active run, its map and its living members keyed by character ID. */
    private function active(User $user): array
    {
        $run = DungeonRun::where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->first();
        $this->actions->ensure($run !== null, '当前没有进行中的地下城探索。');
        $map = $this->map($run->dungeon_id);
        $this->actions->ensure($map->has($run->room), '地下城内容已变更，请撤离后重新进入。');
        $members = Character::where('user_id', $user->id)->whereIn('id', $run->party)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$run, $map, $members];
    }

    private function battle(User $user, DungeonRun $run, DungeonMap $map, Collection $members, int $operation, int $seed): void
    {
        $room = $map->room($run->room);
        $weights = $room['encounters'] ?? [];
        if (isset($room['area'])) {
            $weights = $this->catalog->get('areas', $room['area'])['encounters'];
        }
        $count = ($room['count'] ?? 'party') === 'party' ? $this->standing($run, $members)->count() : (int) $room['count'];
        $ids = array_values(array_filter($run->party, static fn (int $id): bool => $members->has($id)));
        // Dying members are carried into battle fallen, where a revival skill can save them.
        $downed = array_values(array_filter($ids, fn (int $id): bool => $this->isDying($run, $id)));
        $report = $this->battles->fightDungeon($user, $ids, $weights, $count, $room['fixed'] ?? [], $room['land'] ?? $map->definition()['land'], $map->name().'·'.$room['name'], $operation, $seed, $downed);
        $run->loot_money += (int) $report['settlement']['money'];
        foreach ($report['teams'][0] as $unit) {
            if (! str_starts_with((string) $unit['id'], 'player:')) {
                continue;
            }
            // Settlement may have levelled the character; reload before writing HP/SP.
            $member = $members->get((int) substr((string) $unit['id'], 7))->refresh();
            $stats = $member->stats;
            $stats['hp'] = $unit['state'] === 1 ? 0 : max(0, min((int) $stats['maxhp'], (int) $unit['hp']));
            $stats['sp'] = max(0, min((int) $stats['maxsp'], (int) $unit['sp']));
            $member->stats = $stats;
            $member->save();
        }
        $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'pve', 'public' => (bool) ($user->preferences['record_battle_log'] ?? true), 'report' => $report]);
        $lines = match ($report['initiative']) {
            1 => ['队伍抢得了先机。'], -1 => ['队伍遭到了伏击！'], default => [],
        };
        foreach ($downed as $id) {
            if ((int) $members->get($id)->stats['hp'] > 0) {
                $lines[] = $this->rescue($run, $members->get($id));
            }
        }
        $lines = [...$lines, ...$this->fall($user, $run, $members, $operation)];
        foreach ($this->standing($run, $members) as $member) {
            $this->vitals->spendStamina($member, self::BATTLE_STAMINA, $operation, 'dungeon battle', resting: false, clamp: true);
        }
        if ($this->standing($run, $members)->isEmpty()) {
            $this->log($run, 'battle', $run->room, '队伍全灭。'.implode('', $lines), $record->id);
            $this->wipe($user, $run, $members, $operation);

            return;
        }
        if ($report['winner'] === 0) {
            $this->setRoom($run, $run->room, ['cleared' => true]);
            $this->log($run, 'battle', $run->room, '击败了敌人。'.implode('', $lines), $record->id);

            return;
        }
        $from = $run->room;
        $run->room = $run->previous_room ?? $map->entrance();
        $this->log($run, 'battle', $from, '未能击败敌人，退回「'.$map->room($run->room)['name'].'」。'.implode('', $lines), $record->id);
    }

    private function trap(User $user, DungeonRun $run, array $room, Collection $members, int $operation, RandomSource $random): void
    {
        $standing = $this->standing($run, $members);
        $this->setRoom($run, $run->room, ['cleared' => true]);
        // The member with the highest DEX tries to disarm it before anyone is hurt.
        $expert = $standing->sortByDesc(static fn (Character $member): int => (int) $member->stats['dex'])->first();
        if (RoomRules::disarm((int) $expert->stats['dex'], $random)) {
            $this->log($run, 'trap', $run->room, $expert->name.' 拆除了陷阱。');

            return;
        }
        $lines = [];
        foreach ($standing as $member) {
            $damage = RoomRules::trapDamage($room, (int) $member->stats['dex'], $random);
            if ($damage === null) {
                $lines[] = $member->name.' 躲开了';

                continue;
            }
            $stats = $member->stats;
            $stats['hp'] = max(0, (int) $stats['hp'] - $damage);
            $member->stats = $stats;
            $member->save();
            $lines[] = $member->name.' 受到 '.$damage.' 点伤害';
        }
        foreach ($standing as $member) {
            $this->vitals->spendStamina($member, (int) ($room['stamina_loss'] ?? 0), $operation, 'dungeon trap', resting: false, clamp: true);
        }
        $this->log($run, 'trap', $run->room, '触发了陷阱：'.implode('，', $lines).'。'.implode('', $this->fall($user, $run, $members, $operation)));
        $this->wipeIfDown($user, $run, $members, $operation);
    }

    /**
     * Healing reaches every living member and rescues the dying; damage, SP and stamina
     * effects reach standing members only.
     *
     * @return array{0: list<string>, 1: list<string>} summary fragments and rescue lines
     */
    private function applyEffects(User $user, DungeonRun $run, Collection $members, array $effects, int $operation): array
    {
        $summary = [];
        $lines = [];
        foreach ($effects as $effect) {
            $kind = array_key_first($effect);
            $value = $effect[$kind];
            switch ($kind) {
                case 'heal_percent':
                    if ($value === 0) {
                        break;
                    }
                    foreach ($members as $member) {
                        $dying = $this->isDying($run, $member->id);
                        $stats = $member->stats;
                        $stats['hp'] = min((int) $stats['maxhp'], (int) $stats['hp'] + RoomRules::percentOf((int) $stats['maxhp'], $value));
                        $member->stats = $stats;
                        $member->save();
                        if ($dying) {
                            $lines[] = $this->rescue($run, $member);
                        }
                    }
                    $summary[] = 'HP 恢复 '.$value.'%';
                    break;
                case 'sp_percent':
                    if ($value === 0) {
                        break;
                    }
                    foreach ($this->standing($run, $members) as $member) {
                        $stats = $member->stats;
                        $stats['sp'] = min((int) $stats['maxsp'], (int) $stats['sp'] + RoomRules::percentOf((int) $stats['maxsp'], $value));
                        $member->stats = $stats;
                        $member->save();
                    }
                    $summary[] = 'SP 恢复 '.$value.'%';
                    break;
                case 'damage':
                    if ($value === 0) {
                        break;
                    }
                    foreach ($this->standing($run, $members) as $member) {
                        $stats = $member->stats;
                        $stats['hp'] = max(0, (int) $stats['hp'] - $value);
                        $member->stats = $stats;
                        $member->save();
                    }
                    $summary[] = '每人受到 '.$value.' 点伤害';
                    break;
                case 'stamina':
                    if ($value === 0) {
                        break;
                    }
                    foreach ($this->standing($run, $members) as $member) {
                        $value > 0
                            ? $this->vitals->restoreStamina($member, $value, $operation, 'dungeon effect')
                            : $this->vitals->spendStamina($member, -$value, $operation, 'dungeon effect', resting: false, clamp: true);
                    }
                    $summary[] = '体力 '.($value > 0 ? '+' : '').$value;
                    break;
                case 'money':
                    $run->loot_money += $value;
                    $summary[] = '获得 $ '.number_format($value);
                    break;
                case 'item':
                    $this->actions->addItem($user, (string) $value, (int) $effect['quantity'], $operation, 'dungeon event', location: 'loot');
                    $summary[] = '获得 '.$this->catalog->get('items', $value)['name'].' ×'.$effect['quantity'];
                    break;
            }
        }

        return [$summary, $lines];
    }

    /** @return list<string> */
    private function restore(Character $member, array $restore, int $operation): array
    {
        $summary = [];
        $stats = $member->stats;
        foreach (['hp' => 'HP', 'sp' => 'SP'] as $resource => $label) {
            if (isset($restore[$resource])) {
                $before = (int) $stats[$resource];
                $stats[$resource] = min((int) $stats['max'.$resource], $before + RoomRules::percentOf((int) $stats['max'.$resource], (int) $restore[$resource]));
                $summary[] = $label.' +'.($stats[$resource] - $before);
            }
        }
        $member->stats = $stats;
        $member->save();
        if (isset($restore['stamina'])) {
            $before = $member->stamina_units;
            $this->vitals->restoreStamina($member, (int) $restore['stamina'], $operation, 'dungeon consumable');
            $summary[] = '体力 +'.intdiv($member->stamina_units - $before, Vitals::STAMINA_UNIT);
        }

        return $summary;
    }

    /** Living members who are not dying. */
    private function standing(DungeonRun $run, Collection $members): Collection
    {
        return $members->reject(fn (Character $member): bool => $this->isDying($run, $member->id));
    }

    private function isDying(DungeonRun $run, int $id): bool
    {
        return isset(($run->members ?? [])[$id]['dying']);
    }

    private function highest(Collection $members, string $stat): int
    {
        return (int) $members->max(static fn (Character $member): int => (int) $member->stats[$stat]);
    }

    /** Replace one member's run state; null clears it. */
    private function setMember(DungeonRun $run, int $id, ?array $state): void
    {
        $states = $run->members ?? [];
        if ($state === null) {
            unset($states[$id]);
        } else {
            $states[$id] = $state;
        }
        $run->members = $states;
    }

    /** A dying member whose HP rose above 0 is saved, but wounded for the rest of the run. */
    private function rescue(DungeonRun $run, Character $member): string
    {
        $this->setMember($run, $member->id, ['wounded' => true]);

        return $member->name.' 被救了回来，但受了重伤。';
    }

    /**
     * Members at 0 HP become dying with steps set by VIT, or die when already wounded.
     *
     * @return list<string> log fragments
     */
    private function fall(User $user, DungeonRun $run, Collection $members, int $operation): array
    {
        $lines = [];
        foreach ($members->all() as $id => $member) {
            if ((int) $member->stats['hp'] > 0 || $this->isDying($run, $id)) {
                continue;
            }
            if ($run->members[$id]['wounded'] ?? false) {
                $lines[] = $this->die($user, $run, $members, $id, $operation).' 再次倒下，永远离开了队伍。';

                continue;
            }
            $steps = Attributes::dyingSteps((int) $member->stats['vit']);
            $this->setMember($run, $id, ['dying' => $steps]);
            $lines[] = $member->name.' 倒下了，陷入濒死（还能坚持 '.$steps.' 步）。';
        }

        return $lines;
    }

    /**
     * Each move costs every dying member one step; at zero the member dies.
     *
     * @return list<string> log fragments
     */
    private function weaken(User $user, DungeonRun $run, Collection $members, int $operation): array
    {
        $lines = [];
        foreach ($members->keys()->all() as $id) {
            if (! $this->isDying($run, $id)) {
                continue;
            }
            $left = $run->members[$id]['dying'] - 1;
            if ($left > 0) {
                $this->setMember($run, $id, ['dying' => $left]);

                continue;
            }
            $lines[] = $this->die($user, $run, $members, $id, $operation).' 没能撑到获救，永远离开了队伍。';
        }

        return $lines;
    }

    /**
     * Permanent death. The member's equipment becomes run loot and the member leaves the
     * collection of living members.
     *
     * @return string the member's name
     */
    private function die(User $user, DungeonRun $run, Collection $members, int $id, int $operation): string
    {
        $member = $members->get($id);
        $stats = $member->stats;
        $stats['hp'] = 0;
        $member->stats = $stats;
        $member->died_at = CarbonImmutable::now();
        $member->save();
        foreach (InventoryItem::where('user_id', $user->id)->where('location', 'equipped')->where('character_id', $id)->orderBy('id')->lockForUpdate()->get() as $item) {
            $previous = ['character_id' => $item->character_id, 'slot' => $item->slot];
            $item->forceFill(['location' => 'loot', 'character_id' => null, 'slot' => null])->save();
            $this->actions->ledger($user->id, $operation, 'item_move', 1, 'fallen equipment', $item->item_id, $previous + ['inventory_id' => $item->id]);
        }
        $preferences = $user->preferences ?? [];
        $preferences['party'] = array_values(array_filter($preferences['party'] ?? [], static fn ($party) => (int) $party !== $id));
        $user->preferences = $preferences;
        $user->save();
        $members->forget($id);
        $this->setMember($run, $id, null);

        return $member->name;
    }

    /** With nobody standing nobody can carry the dying out: they die and the run is lost. */
    private function wipe(User $user, DungeonRun $run, Collection $members, int $operation): void
    {
        $names = [];
        foreach ($members->keys()->all() as $id) {
            $names[] = $this->die($user, $run, $members, $id, $operation);
        }
        if ($names) {
            $this->log($run, 'death', $run->room, implode('、', $names).' 没有人能带出去，永远留在了这里。');
        }
        $this->finish($user, $run, $members, 'wiped', $operation);
    }

    private function wipeIfDown(User $user, DungeonRun $run, Collection $members, int $operation): void
    {
        if ($this->standing($run, $members)->isEmpty()) {
            $this->wipe($user, $run, $members, $operation);
        }
    }

    /** Each move restores a share of maximum SP set by INT. */
    private function recoverSp(Character $member): void
    {
        $percent = Attributes::moveSpPercent((int) $member->stats['int']);
        $stats = $member->stats;
        $recovered = min((int) $stats['maxsp'], (int) $stats['sp'] + RoomRules::percentOf((int) $stats['maxsp'], $percent));
        if ($recovered !== (int) $stats['sp']) {
            $stats['sp'] = $recovered;
            $member->stats = $stats;
            $member->save();
        }
    }

    /** Rooms first seen at the edge of the fog may be identified, by the party's highest LUK. */
    private function scout(DungeonRun $run, DungeonMap $map, Collection $standing, string $room, RandomSource $random): void
    {
        $luk = $this->highest($standing, 'luk');
        foreach ($map->neighbors($room) as $next) {
            $state = $run->rooms[$next] ?? [];
            if (($state['visited'] ?? false) || array_key_exists('scouted', $state)) {
                continue;
            }
            $this->setRoom($run, $next, ['scouted' => RoomRules::scout($luk, $random)]);
        }
    }

    private function finish(User $user, DungeonRun $run, Collection $members, string $status, int $operation): void
    {
        $now = CarbonImmutable::now();
        $carried = InventoryItem::where('user_id', $user->id)->whereIn('location', ['pack', 'loot'])->orderBy('id')->lockForUpdate()->get();
        if ($status === 'wiped') {
            foreach ($carried as $item) {
                $this->actions->ledger($user->id, $operation, 'item', -$item->quantity, 'lost in dungeon', $item->item_id, ['inventory_id' => $item->id, 'location' => $item->location]);
                $item->delete();
            }
            $text = '探索失败：背包和战利品全部遗失。';
        } else {
            foreach ($carried as $item) {
                $this->returnToWarehouse($user, $item, $operation);
            }
            if ($run->loot_money > 0) {
                $this->actions->money($user, $run->loot_money, $operation, 'dungeon loot');
            }
            foreach ($members as $member) {
                // The dying are carried out alive.
                if ((int) $member->stats['hp'] < 1) {
                    $stats = $member->stats;
                    $stats['hp'] = 1;
                    $member->stats = $stats;
                }
                $this->vitals->resume($member, $now);
            }
            $text = ($status === 'cleared' ? '成功离开地下城' : '撤离了地下城').'：带回 $ '.number_format($run->loot_money).'，背包与战利品已存入仓库。';
        }
        $run->status = $status;
        $run->ended_at = $now;
        $run->members = [];
        $this->log($run, $status, $run->room, $text);
    }

    /** Plain items rejoin an existing plain warehouse stack; refined or enchanted items keep their row. */
    private function returnToWarehouse(User $user, InventoryItem $item, int $operation): void
    {
        $from = $item->location;
        $plain = static fn (InventoryItem $row): bool => $row->refinement === 0 && empty($row->enchantments);
        $stack = $plain($item) ? InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->where('item_id', $item->item_id)
            ->where('refinement', 0)->orderBy('id')->lockForUpdate()->get()->first($plain) : null;
        if ($stack) {
            $stack->increment('quantity', $item->quantity);
            $item->delete();
            $this->actions->ledger($user->id, $operation, 'item_move', $item->quantity, 'dungeon return', $item->item_id, ['inventory_id' => $stack->id, 'merged_from' => $item->id, 'from' => $from]);

            return;
        }
        $item->forceFill(['location' => 'warehouse'])->save();
        $this->actions->ledger($user->id, $operation, 'item_move', $item->quantity, 'dungeon return', $item->item_id, ['inventory_id' => $item->id, 'from' => $from]);
    }

    /** Move part or all of a stack to another location; splits keep refinement and enchantments. */
    private function moveStack(User $user, InventoryItem $item, int $quantity, string $location, int $operation, string $reason): void
    {
        $from = $item->location;
        if ($quantity < $item->quantity) {
            $item->decrement('quantity', $quantity);
            $item = InventoryItem::create(['user_id' => $user->id, 'item_id' => $item->item_id, 'quantity' => $quantity, 'refinement' => $item->refinement, 'enchantments' => $item->enchantments, 'location' => $location]);
        } else {
            $item->forceFill(['location' => $location])->save();
        }
        $this->actions->ledger($user->id, $operation, 'item_move', $quantity, $reason, $item->item_id, ['inventory_id' => $item->id, 'from' => $from, 'to' => $location]);
    }

    private function setRoom(DungeonRun $run, string $room, array $state): void
    {
        $rooms = $run->rooms;
        $rooms[$room] = $state + ($rooms[$room] ?? []);
        $run->rooms = $rooms;
    }

    private function log(DungeonRun $run, string $type, string $room, string $text, ?int $reportId = null): void
    {
        $run->save();
        DungeonRunEvent::create([
            'dungeon_run_id' => $run->id, 'sequence' => (int) DungeonRunEvent::where('dungeon_run_id', $run->id)->max('sequence') + 1,
            'type' => $type, 'room' => $room, 'text' => mb_substr($text, 0, 500), 'battle_report_id' => $reportId,
        ]);
    }

    private function result(DungeonRun $run): array
    {
        $last = DungeonRunEvent::where('dungeon_run_id', $run->id)->orderByDesc('sequence')->first();

        return ['run_id' => $run->id, 'status' => $run->status, 'room' => $run->room, 'message' => $last?->text ?? '', 'report_id' => $last?->battle_report_id];
    }
}
