<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\Character;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class PlayerService
{
    public function __construct(
        private GameAction $actions, private ContentCatalog $catalog,
        private CharacterFactory $characters, private Inventory $inventory,
        private Crafting $crafting, private ItemDetails $details,
    ) {}

    public function execute(int $actor, string $command, string $key, array $input): array
    {
        $data = $this->validate($command, $input);

        return $this->actions->execute($actor, 'player.'.$command, $key, $data,
            function (User $user, int $operation, int $seed) use ($command, $data): array {
                $random = new Randomizer(new Mt19937($seed));

                return match ($command) {
                    'recruit' => $this->recruit($user, $data, $operation),
                    'buy' => $this->buy($user, $data['items'], $operation),
                    'sell' => $this->sell($user, $data['items'], $operation),
                    'work' => $this->work($user, $operation),
                    'craft' => $this->crafting->create($user, (string) $data['item_id'], $data['material'] ?? null, $operation, $random),
                    'refine' => $this->crafting->refine($user, (int) $data['inventory_id'], (int) $data['times'], $operation, $random),
                    'preferences' => $this->preferences($user, $data),
                    'party' => $this->party($user, $data['characters']),
                    'team-name' => $this->teamName($user, $data['name'], $operation),
                    default => $this->characterCommand($user, $command, $data, $operation),
                };
            });
    }

    private function validate(string $command, array $input): array
    {
        $character = ['character_id' => ['required', 'integer', 'min:1']];
        $name = ['required', 'string', 'min:1', 'max:16', 'regex:/^[^\p{C}]+$/u'];
        $rules = match ($command) {
            'recruit' => ['base_type' => ['required', 'integer', Rule::in([1, 2, 3, 4])], 'gender' => ['required', 'integer', Rule::in([0, 1])], 'name' => $name],
            'buy', 'sell' => ['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999']],
            'work' => [],
            'craft' => ['item_id' => ['required', 'integer', 'min:1'], 'material' => ['nullable', 'string', 'regex:/^7[0-1][0-9]{2}$/']],
            'refine' => ['inventory_id' => ['required', 'integer', 'min:1'], 'times' => ['required', 'integer', 'between:1,10']],
            'preferences' => ['record_battle_log' => ['required', 'boolean'], 'no_js_inventory' => ['required', 'boolean'], 'color' => ['required', 'regex:/^[a-fA-F0-9]{6}$/']],
            'party' => ['characters' => ['required', 'array', 'between:1,5'], 'characters.*' => ['required', 'integer', 'distinct', 'min:1']],
            'team-name' => ['name' => $name],
            'dismiss', 'memo', 'unequip-all' => $character,
            'rename' => $character + ['name' => $name],
            'reset' => $character + ['item_id' => ['required', 'integer', Rule::in([7510, 7511, 7512, 7513, 7520])]],
            'stats' => $character + ['stats' => ['required', 'array:str,int,dex,spd,luk'], 'stats.*' => ['required', 'integer', 'between:0,255']],
            'position' => $character + ['position' => ['required', Rule::in(['front', 'back'])], 'guard' => ['required', Rule::in(PlayerRules::GUARDS)]],
            'learn' => $character + ['skill_id' => ['required', 'integer', 'min:1']],
            'job' => $character + ['job_id' => ['required', 'integer', 'min:1']],
            'equip' => $character + ['inventory_id' => ['required', 'integer', 'min:1']],
            'unequip' => $character + ['slot' => ['required', Rule::in(['weapon', 'shield', 'armor', 'item'])]],
            'tactics' => $character + ['tactics' => ['required', 'array', 'between:1,11'], 'tactics.*.judge' => ['required', 'integer'], 'tactics.*.quantity' => ['required', 'integer', 'between:0,9999'], 'tactics.*.action' => ['required', 'integer']],
            'tactics-insert', 'tactics-delete' => $character + ['row' => ['required', 'integer', 'between:0,10']],
            default => throw ValidationException::withMessages(['command' => 'Unknown player action.']),
        };
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                Inventory::reject('Name cannot be empty.');
            }
        }
        foreach (['item_id' => 'items', 'job_id' => 'jobs', 'skill_id' => 'skills'] as $field => $kind) {
            if (isset($data[$field]) && ! $this->catalog->has($kind, $data[$field])) {
                Inventory::reject('Unknown '.$kind.' selection.');
            }
        }
        if ($command === 'craft' && ! $this->catalog->has('recipes', $data['item_id'])) {
            Inventory::reject('This recipe is unavailable.');
        }
        if (! empty($data['material']) && ! $this->catalog->has('items', $data['material'])) {
            Inventory::reject('Unknown special material.');
        }

        return $data;
    }

    private function recruit(User $user, array $data, int $operation): array
    {
        $this->actions->money($user, -PlayerRules::RECRUIT_PRICES[$data['base_type']], $operation, 'recruit');
        $character = $this->characters->create($user, (int) $data['base_type'], $data['name'], (int) $data['gender']);
        foreach ($character->equipment as $item) {
            $this->actions->ledger($user->id, $operation, 'item', 1, 'recruit starter equipment', $item->item_id, ['inventory_id' => $item->id]);
        }

        return ['message' => 'Character recruited.', 'character_id' => $character->id];
    }

    private function buy(User $user, array $items, int $operation): array
    {
        $stock = array_map('strval', $this->catalog->get('economy_rules', 'shop')['values']);
        $total = 0;
        foreach ($items as $selection) {
            $id = (string) $selection['id'];
            if (! in_array($id, $stock, true)) {
                Inventory::reject('This item is not sold here.');
            }
            $total += (int) $this->catalog->get('items', $id)['buy'] * (int) $selection['quantity'];
        }
        $this->actions->money($user, -$total, $operation, 'shop buy');
        foreach ($items as $selection) {
            $this->actions->addItem($user, (string) $selection['id'], (int) $selection['quantity'], $operation, 'shop buy');
        }

        return ['message' => 'Purchase completed.', 'total' => $total];
    }

    private function sell(User $user, array $items, int $operation): array
    {
        $total = 0;
        foreach ($items as $selection) {
            $item = $this->inventory->owned($user, (int) $selection['id']);
            $total += $this->details->resolve($item)['sell_price'] * (int) $selection['quantity'];
            $this->actions->takeItem($user, $item->id, (int) $selection['quantity'], $operation, 'shop sell');
        }
        $this->actions->money($user, $total, $operation, 'shop sell');

        return ['message' => 'Sale completed.', 'total' => $total];
    }

    private function work(User $user, int $operation): array
    {
        $this->actions->stamina($user, 100, $operation, 'work');
        $this->actions->money($user, 500, $operation, 'work');

        return ['message' => 'Work completed: 100 stamina exchanged for 500 gold.'];
    }

    private function preferences(User $user, array $data): array
    {
        $user->preferences = array_merge($user->preferences ?? [], [
            'record_battle_log' => (bool) $data['record_battle_log'],
            'no_js_inventory' => (bool) $data['no_js_inventory'], 'color' => strtolower($data['color']),
        ]);
        $user->save();

        return ['message' => 'Preferences saved.'];
    }

    private function party(User $user, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if ($user->characters()->whereIn('id', $ids)->count() !== count($ids)) {
            Inventory::reject('Party must contain only your characters.');
        }
        $user->preferences = array_merge($user->preferences ?? [], ['party' => $ids]);
        $user->save();

        return ['message' => 'Party saved.'];
    }

    private function teamName(User $user, string $name, int $operation): array
    {
        if (User::where('name', $name)->where('id', '<>', $user->id)->exists()) {
            Inventory::reject('That team name is already in use.');
        }
        if ($user->name === $name) {
            Inventory::reject('Choose a different team name.');
        }
        $this->actions->money($user, -100000, $operation, 'team rename');
        $user->name = $name;
        $user->save();

        return ['message' => 'Team renamed.'];
    }

    private function characterCommand(User $user, string $command, array $data, int $operation): array
    {
        $character = $user->characters()->lockForUpdate()->findOrFail((int) $data['character_id']);
        switch ($command) {
            case 'dismiss':
                if ($user->characters()->count() <= 1) {
                    Inventory::reject('Keep at least one character in your team.');
                }
                $this->inventory->unequip($user, $character, $operation);
                $preferences = $user->preferences ?? [];
                $preferences['party'] = array_values(array_filter($preferences['party'] ?? [], fn ($id) => (int) $id !== $character->id));
                $user->preferences = $preferences;
                $user->save();
                $character->delete();

                return ['message' => 'Character dismissed; equipment returned.'];
            case 'rename':
                if ($character->name === $data['name']) {
                    Inventory::reject('Choose a different character name.');
                }
                $this->inventory->consumeBase($user, '7500', 1, $operation, 'character rename');
                $character->name = $data['name'];
                break;
            case 'stats':
                $stats = $character->stats;
                $spent = array_sum($data['stats']);
                if ($spent < 1 || $spent > $character->stat_points) {
                    Inventory::reject('Not enough status points.');
                }
                foreach ($data['stats'] as $stat => $amount) {
                    if ($stats[$stat] + $amount > 255) {
                        Inventory::reject('A status cannot exceed 255.');
                    }
                    $stats[$stat] += (int) $amount;
                }
                $character->stats = $stats;
                $character->stat_points -= $spent;
                $this->refreshVitals($character);
                break;
            case 'position':
                $character->position = $data['position'];
                $character->guard_policy = ['mode' => $data['guard']];
                break;
            case 'learn':
                $skill = (string) $data['skill_id'];
                $available = array_map('strval', $this->catalog->availableSkills($character->job_id, $character->level, $character->skills));
                if (! in_array($skill, $available, true)) {
                    Inventory::reject('Skill prerequisites are not satisfied.');
                }
                $cost = (int) ($this->catalog->get('skills', $skill)['learn'] ?? 0);
                if ($cost < 0 || $cost > $character->skill_points) {
                    Inventory::reject('Not enough skill points.');
                }
                $character->skill_points -= $cost;
                $skills = [...$character->skills, (int) $skill];
                sort($skills, SORT_NUMERIC);
                $character->skills = $skills;
                break;
            case 'job':
                if (! $this->catalog->canChangeJob($character->job_id, (string) $data['job_id'], $character->level)) {
                    Inventory::reject('Job prerequisites are not satisfied.');
                }
                $this->inventory->unequip($user, $character, $operation);
                $character->job_id = (string) $data['job_id'];
                $this->refreshVitals($character);
                break;
            case 'equip':
                $this->inventory->equip($user, $character, (int) $data['inventory_id'], $operation);
                break;
            case 'unequip':
            case 'unequip-all':
                $this->inventory->unequip($user, $character, $operation, $data['slot'] ?? null);
                break;
            case 'reset':
                $this->reset($user, $character, (int) $data['item_id'], $operation);
                break;
            case 'tactics':
                $character->tactics = $this->validateTactics($character, $data['tactics']);
                break;
            case 'memo':
                $old = $character->tactics;
                $character->tactics = $this->validateTactics($character, $character->tactics_memo ?: [PlayerRules::defaultTactic($character)]);
                $character->tactics_memo = $old;
                break;
            case 'tactics-insert':
            case 'tactics-delete':
                $patterns = $this->validateTactics($character, $character->tactics);
                $max = PlayerRules::maxPatterns((int) $character->stats['int'], $character->level);
                while (count($patterns) < $max) {
                    $patterns[] = PlayerRules::defaultTactic($character);
                }
                if ((int) $data['row'] >= $max) {
                    Inventory::reject('Invalid tactic row.');
                }
                if ($command === 'tactics-insert') {
                    array_splice($patterns, (int) $data['row'], 0, [PlayerRules::defaultTactic($character)]);
                    array_pop($patterns);
                } else {
                    array_splice($patterns, (int) $data['row'], 1);
                    $patterns[] = PlayerRules::defaultTactic($character);
                }
                $character->tactics = $patterns;
                break;
            default:
                Inventory::reject('Unknown character action.');
        }
        $character->save();

        return ['message' => 'Character updated.', 'character_id' => $character->id];
    }

    public function validateTactics(Character $character, array $tactics): array
    {
        $max = PlayerRules::maxPatterns((int) $character->stats['int'], $character->level);
        if (count($tactics) < 1 || count($tactics) > $max) {
            Inventory::reject('Tactic row limit exceeded.');
        }
        $known = array_map('strval', $character->skills);
        $result = [];
        foreach ($tactics as $row) {
            $judge = (string) $row['judge'];
            $skill = (string) $row['action'];
            if (! array_key_exists($judge, $this->catalog->selectableConditions())) {
                Inventory::reject('Unknown battle condition.');
            }
            if (! in_array($skill, $known, true) || ! PlayerRules::isTacticAction((int) $skill)) {
                Inventory::reject('Action must be a learned active skill.');
            }
            if ((int) $row['quantity'] < 0 || (int) $row['quantity'] > 9999) {
                Inventory::reject('Invalid condition quantity.');
            }
            $result[] = ['judge' => (int) $judge, 'quantity' => (int) $row['quantity'], 'action' => (int) $skill];
        }

        return $result;
    }

    private function reset(User $user, Character $character, int $itemId, int $operation): void
    {
        if ($itemId === 7520) {
            $base = $this->catalog->get('base_characters', $character->base_type);
            $innate = array_map('intval', $base['skill']);
            $refund = 0;
            $learned = array_diff(array_map('intval', $character->skills), $innate);
            if ($learned === []) {
                Inventory::reject('No learned skills to reset.');
            }
            foreach ($learned as $skill) {
                $refund += (int) ($this->catalog->get('skills', $skill)['learn'] ?? 0);
            }
            $this->inventory->consumeBase($user, '7520', 1, $operation, 'skill reset');
            $character->skill_points += $refund;
            $character->skills = $innate;
            $parts = array_map(fn ($part) => explode('<>', $part), explode('|', $base['Pattern']));
            $tactics = [];
            foreach ($parts[0] as $i => $judge) {
                $tactics[] = ['judge' => (int) $judge, 'quantity' => (int) $parts[1][$i], 'action' => (int) $parts[2][$i]];
            }
            $character->tactics = array_slice($tactics, 0, PlayerRules::maxPatterns((int) $character->stats['int'], $character->level));
            $character->tactics_memo = null;

            return;
        }
        $limit = [7510 => 1, 7511 => 30, 7512 => 50, 7513 => 100][$itemId];
        $stats = $character->stats;
        $refund = 0;
        foreach (PlayerRules::STATS as $stat) {
            $refund += max(0, $stats[$stat] - $limit);
            $stats[$stat] = min($stats[$stat], $limit);
        }
        if ($refund === 0) {
            Inventory::reject('No status points to reset.');
        }
        $this->inventory->consumeBase($user, (string) $itemId, 1, $operation, 'status reset');
        $this->inventory->unequip($user, $character, $operation);
        $character->stats = $stats;
        $character->stat_points += $refund;
        $max = PlayerRules::maxPatterns((int) $stats['int'], $character->level);
        $character->tactics = array_slice($character->tactics, 0, $max);
        $character->tactics_memo = $character->tactics_memo ? array_slice($character->tactics_memo, 0, $max) : null;
        $this->refreshVitals($character);
    }

    public function refreshVitals(Character $character): void
    {
        $job = $this->catalog->get('jobs', $character->job_id);
        $stats = $character->stats;
        foreach (['hp' => ['str', 0], 'sp' => ['int', 1]] as $resource => [$attribute, $coefficient]) {
            $maximum = (int) round(100 * $job['coe'][$coefficient] * (1 + ($character->level - 1) / 49) * (1 + (255 ** 2 - (255 - $stats[$attribute]) ** 2) / (255 ** 2)));
            $stats['max'.$resource] = $maximum;
            $stats[$resource] = min($maximum, $stats[$resource] ?? $maximum);
        }
        $character->stats = $stats;
    }
}
