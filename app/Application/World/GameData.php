<?php

declare(strict_types=1);

namespace App\Application\World;

use App\Application\Multiplayer\AuctionService;
use App\Application\Player\ItemDetails;
use App\Application\Player\PlayerRules;
use App\Domain\Content\ContentCatalog;
use App\Http\View\Images;
use App\Http\View\UnitCards;

/**
 * Read-only projections of the content catalog for the public game data pages.
 * Lists and details are plain arrays; cross references (drops, encounters, learners,
 * recipes) are derived from the same definitions the game rules use.
 */
final class GameData
{
    /** Kinds with one page per record. */
    public const DETAILS = ['jobs', 'items', 'skills', 'monsters'];

    public const FAMILIES = [100 => '战士系', 200 => '法师系', 300 => '牧师系', 400 => '猎人系'];

    /** Job descriptions from the legacy data.gd_job.php page; they are not part of the content package. */
    public const JOB_NOTES = [
        100 => '战士系基本职业。攻防力强。',
        101 => '战士系高级职业。更高级的攻防。',
        102 => '战士系高级职业。专职负责攻击，以牺牲自己生命的方式释放强力技能。',
        103 => '战士系高级职业。夺取对手的魔力，非正统意义上的战士。',
        200 => '法师系基本职业。攻击力弱，但可使用强力的魔法。',
        201 => '法师系高级职业。可以使用更加强大的魔法。',
        202 => '法师系高级职业。可以花费时间召唤强力的召唤兽。',
        203 => '法师系高级职业。降低对手的能力，制作僵尸，使毒。',
        300 => '牧师系基本职业。回复我方的生命、魔力。',
        301 => '牧师系高级职业。提高我方的能力值。',
        302 => '牧师系高级职业。具有一些特殊的支援能力。',
        400 => '猎人系基本职业。拥有不会被对方前卫影响的攻击技能。',
        401 => '猎人系高级职业。可进行强力的攻击。',
        402 => '猎人系高级职业。更快的召唤，擅长强化召唤兽。',
        403 => '猎人系高级职业。善于使用毒。',
    ];

    private const ITEM_CATEGORIES = [
        '武器' => PlayerRules::WEAPONS, '防具' => ['盾', '书', '甲', '衣服', '长袍'],
        '饰品' => ['道具'], '材料' => ['材料'], '其他' => ['其他', '地图', '钥匙', '特殊'],
    ];

    private array $memo = [];

    public function __construct(private ContentCatalog $catalog, private ItemDetails $details, private GameText $text) {}

    public function version(): string
    {
        return $this->catalog->version();
    }

    public function text(): GameText
    {
        return $this->text;
    }

    /** Record counts shown on the index page; only records a player can meet are counted. */
    public function counts(): array
    {
        return [
            'jobs' => count($this->catalog->all('jobs')),
            'items' => count($this->catalog->all('items')),
            'skills' => count($this->catalog->playableSkills()),
            'monsters' => count($this->catalog->playableMonsters()),
            'areas' => count($this->exposedAreas()),
            'conditions' => count($this->catalog->selectableConditions()),
            'enchants' => count($this->catalog->all('enchants')),
        ];
    }

    public function exists(string $kind, string $id): bool
    {
        return match ($kind) {
            'skills' => array_key_exists($id, $this->catalog->playableSkills()),
            'monsters' => array_key_exists($id, $this->catalog->playableMonsters()),
            'jobs', 'items' => $this->catalog->has($kind, $id),
            default => false,
        };
    }

    /** Name or ID matches across the detail kinds, at most 50 per kind. */
    public function search(string $query): array
    {
        $matches = static fn (string $id, string ...$names): bool => $id === $query || array_filter($names, static fn ($name) => mb_stripos($name, $query) !== false) !== [];
        $results = ['jobs' => [], 'items' => [], 'skills' => [], 'monsters' => []];
        foreach ($this->catalog->all('jobs') as $id => $job) {
            if ($matches((string) $id, trim($job['name_male']), trim($job['name_female']))) {
                $results['jobs'][] = $this->jobLine($id);
            }
        }
        foreach ($this->catalog->all('items') as $id => $item) {
            if ($matches((string) $id, $item['name'], $item['type'])) {
                $results['items'][] = $this->itemLine($id);
            }
        }
        foreach ($this->catalog->playableSkills() as $id => $skill) {
            if ($matches((string) $id, $skill['name'], (string) ($skill['exp'] ?? ''))) {
                $results['skills'][] = $this->skillLine($id);
            }
        }
        foreach ($this->catalog->playableMonsters() as $id => $monster) {
            if ($matches((string) $id, $monster['name'], (string) ($monster['UnionName'] ?? ''))) {
                $results['monsters'][] = $this->monsterLine($id);
            }
        }

        return array_filter(array_map(static fn (array $rows) => array_slice($rows, 0, 50), $results));
    }

    // ---- Shared line shapes ------------------------------------------------------------

    public function itemLine(string|int $id): array
    {
        $item = $this->details->resolve(['item_id' => (string) $id]);

        return ['id' => (string) $id, 'name' => $item['name'], 'icon' => 'image/icon/'.basename($item['img']), 'refine' => 0,
            'type' => $item['type'], 'qty' => 1, 'stats' => $this->text->itemStats($item), 'option' => '', 'note' => '',
            'href' => route('catalog.entry', ['items', $id])];
    }

    public function skillLine(string|int $id): array
    {
        $skill = $this->catalog->get('skills', $id);

        return ['id' => (string) $id, 'name' => $skill['name'], 'icon' => 'image/icon/'.basename($skill['img']),
            'parts' => $this->text->skillParts($skill), 'exp' => trim((string) ($skill['exp'] ?? '')),
            'learn' => (int) ($skill['learn'] ?? 0),
            'href' => $this->exists('skills', (string) $id) ? route('catalog.entry', ['skills', $id]) : null];
    }

    public function monsterLine(string|int $id): array
    {
        $monster = $this->catalog->get('monsters', $id);
        $img = 'image/char/'.basename($monster['img'] ?? 'NoImage.gif');
        [$width, $height] = Images::size($img);

        return ['id' => (string) $id, 'name' => $monster['name'], 'img' => $img, 'width' => $width, 'height' => $height,
            'level' => (string) ($monster['level'] ?? '?'), 'label' => '', 'boss' => isset($monster['UnionName']),
            'href' => $this->exists('monsters', (string) $id) ? route('catalog.entry', ['monsters', $id]) : null];
    }

    public function jobLine(string|int $id, int $gender = 0): array
    {
        $job = $this->catalog->get('jobs', $id);
        $key = $gender ? 'female' : 'male';

        $img = 'image/char/'.basename($job['img_'.$key]);
        [$width, $height] = Images::size($img);

        return ['id' => (string) $id, 'name' => trim($job['name_'.$key]), 'img' => $img, 'width' => $width, 'height' => $height,
            'label' => '', 'href' => route('catalog.entry', ['jobs', $id])];
    }

    // ---- Jobs --------------------------------------------------------------------------

    public function jobs(): array
    {
        return array_map(fn ($id) => $this->job((string) $id, false), array_keys($this->catalog->all('jobs')));
    }

    public function job(string $id, bool $withTree = true): array
    {
        $job = $this->catalog->get('jobs', $id);
        $from = $this->catalog->has('class_changes', $id) ? $this->catalog->get('class_changes', $id) : null;
        $view = [
            'id' => $id, 'name' => trim($job['name_male']), 'name_female' => trim($job['name_female']),
            'images' => [$this->jobLine($id, 0), $this->jobLine($id, 1)],
            'note' => self::JOB_NOTES[(int) $id] ?? '', 'family' => self::FAMILIES[intdiv((int) $id, 100) * 100] ?? '',
            'equip' => $job['equip'], 'coe' => $job['coe'],
            'from' => $from ? ['job' => $this->jobLine($from['from_job']), 'level' => $from['minimum_level']] : null,
            'to' => array_map(fn ($to) => ['job' => $this->jobLine($to), 'level' => $this->catalog->get('class_changes', $to)['minimum_level']], $job['change'] ?? []),
            'starter' => $this->starter($id),
            'href' => route('catalog.entry', ['jobs', $id]),
        ];
        if ($withTree) {
            $view['tree'] = [];
            foreach ($this->catalog->all('skill_tree') as $rule) {
                $residual = $this->residual($rule['when'], $id);
                if ($residual !== false && $this->exists('skills', $rule['skill'])) {
                    $view['tree'][] = ['skill' => $this->skillLine($rule['skill']), 'requires' => $residual === true ? '' : $this->requirement($residual)];
                }
            }
        }

        return $view;
    }

    /** Recruitable starting setup for a base job, or null for advanced jobs. */
    private function starter(string $job): ?array
    {
        foreach ($this->catalog->all('base_characters') as $type => $base) {
            if ((string) $base['job'] !== $job) {
                continue;
            }
            $equipment = [];
            foreach (['weapon' => '武器', 'shield' => '盾', 'armor' => '甲', 'item' => '道具'] as $slot => $label) {
                if (! empty($base[$slot])) {
                    $equipment[] = ['slot' => $label, 'item' => $this->itemLine($base[$slot])];
                }
            }

            return [
                'type' => (int) $type, 'price' => PlayerRules::RECRUIT_PRICES[(int) $type] ?? null,
                'stats' => array_combine(GameText::STAT_KEYS, array_map(fn ($key) => (int) $base[$key], GameText::STAT_KEYS)),
                'skills' => array_map(fn ($skill) => $this->skillLine($skill), $base['skill']),
                'equipment' => $equipment, 'tactics' => $this->pattern($base),
                'position' => __('hof.positions.'.$base['position']), 'guard' => __('hof.guards.'.$base['guard']),
            ];
        }

        return null;
    }

    /** Legacy "judge<>judge|quantity<>quantity|skill<>skill" starter pattern, or monster judge/quantity/action arrays. */
    private function pattern(array $definition): array
    {
        if (isset($definition['Pattern'])) {
            [$judges, $quantities, $skills] = array_map(fn ($part) => explode('<>', $part), explode('|', $definition['Pattern']));
        } else {
            [$judges, $quantities, $skills] = [$definition['judge'] ?? [], $definition['quantity'] ?? [], $definition['action'] ?? []];
        }
        $rows = [];
        foreach ($judges as $i => $judge) {
            $rows[] = ['no' => $i + 1, 'condition' => $this->text->condition($judge, (int) ($quantities[$i] ?? 0)), 'skill' => $this->skillLine($skills[$i] ?? 1000)];
        }

        return $rows;
    }

    /**
     * Resolve job nodes of a skill-tree condition for one job. Returns true (always met),
     * false (never met), or the remaining learned/level prerequisites.
     */
    private function residual(array $node, string $job): array|bool
    {
        $operation = array_key_first($node);
        $value = $node[$operation];
        if ($operation === 'job') {
            return (string) $value === $job;
        }
        if (! in_array($operation, ['all', 'any'], true)) {
            return $node;
        }
        $children = [];
        foreach ($value as $child) {
            $result = $this->residual($child, $job);
            if ($result === ($operation === 'any')) {
                return $result;
            }
            if (is_array($result)) {
                $children[] = $result;
            }
        }

        return match (count($children)) {
            0 => $operation === 'all',
            1 => $children[0],
            default => [$operation => $children],
        };
    }

    private function requirement(array $node, bool $nested = false): string
    {
        $operation = array_key_first($node);
        $value = $node[$operation];
        $text = match ($operation) {
            'learned' => '已习得「'.$this->text->name('skills', $value).'」',
            'not_learned' => '未习得「'.$this->text->name('skills', $value).'」',
            'minimum_level' => '等级 '.$value.' 以上',
            'all' => implode(' 且 ', array_map(fn ($child) => $this->requirement($child, true), $value)),
            'any' => implode(' 或 ', array_map(fn ($child) => $this->requirement($child, true), $value)),
            default => '',
        };

        return $nested && in_array($operation, ['all', 'any'], true) ? '（'.$text.'）' : $text;
    }

    // ---- Items -------------------------------------------------------------------------

    /** Items grouped by legacy type, each group under a broad category. */
    public function items(): array
    {
        $groups = [];
        foreach ($this->catalog->all('items') as $id => $item) {
            $category = '其他';
            foreach (self::ITEM_CATEGORIES as $name => $types) {
                if (in_array($item['type'], $types, true)) {
                    $category = $name;
                    break;
                }
            }
            $groups[$category][$item['type']][] = $this->itemRow((string) $id);
        }

        return array_replace(array_fill_keys(array_keys(self::ITEM_CATEGORIES), []), $groups);
    }

    private function itemRow(string $id): array
    {
        $item = $this->catalog->get('items', $id);
        $sources = [];
        if (in_array($id, $this->shop(), true)) {
            $sources[] = '商店';
        }
        if ($this->catalog->has('recipes', $id)) {
            $sources[] = '制作';
        }
        $drops = $this->index()['drops'][$id] ?? [];
        if ($drops) {
            $sources[] = '掉落 ×'.count($drops);
        }

        return $this->itemLine($id) + ['buy' => (int) $item['buy'], 'sell' => $this->sellPrice($id), 'sources' => $sources];
    }

    public function item(string $id): array
    {
        $item = $this->catalog->get('items', $id);
        $type = $item['type'];
        $view = $this->itemRow($id);
        $view['option'] = trim(rtrim(trim((string) ($item['option'] ?? '')), ','));
        $view['slot'] = ['weapon' => '武器', 'shield' => '盾', 'armor' => '甲', 'item' => '道具'][PlayerRules::slot($type)] ?? null;
        $view['jobs'] = [];
        foreach ($this->catalog->all('jobs') as $jobId => $job) {
            if (in_array($type, $job['equip'], true)) {
                $view['jobs'][] = $this->jobLine($jobId);
            }
        }
        $view['shop'] = in_array($id, $this->shop(), true);
        $view['refinable'] = in_array($type, PlayerRules::REFINABLE, true);
        $view['auction'] = in_array($type, AuctionService::TYPES, true);
        $view['recipe'] = null;
        if ($this->catalog->has('recipes', $id)) {
            $recipe = $this->catalog->get('recipes', $id);
            $view['recipe'] = ['fee' => (int) $recipe['fee'], 'materials' => $this->quantities($recipe['ingredients'])];
        }
        $view['used_in'] = [];
        foreach ($this->catalog->all('recipes') as $product => $recipe) {
            if (isset($recipe['ingredients'][$id])) {
                $view['used_in'][] = ['item' => $this->itemLine($product), 'quantity' => (int) $recipe['ingredients'][$id]];
            }
        }
        $view['drops'] = array_map(fn ($drop) => ['monster' => $this->monsterLine($drop['monster']), 'rate' => GameText::percent($drop['weight'] / 100)], $this->index()['drops'][$id] ?? []);
        $view['pool'] = null;
        if ($this->catalog->has('enchant_pools', $type)) {
            $pool = $this->catalog->get('enchant_pools', $type);
            $view['pool'] = ['low' => array_map(fn ($e) => $this->enchantRow((string) $e), $pool['low']), 'high' => array_map(fn ($e) => $this->enchantRow((string) $e), $pool['high'])];
        }
        $view['special_material'] = ! empty($item['Add']) && (int) $id >= 7000 && (int) $id < 7200 ? $this->enchantRow((string) $item['Add']) : null;
        $view['unlocks'] = [];
        foreach ($this->exposedAreas() as $areaId => $area) {
            if (($area['unlock']['item'] ?? null) === $id) {
                $view['unlocks'][] = ['id' => $areaId, 'name' => $area['name']];
            }
        }

        return $view;
    }

    public function sellPrice(string $id): int
    {
        return $this->details->resolve(['item_id' => $id])['sell_price'];
    }

    public function shop(): array
    {
        return $this->memo['shop'] ??= array_map('strval', $this->catalog->get('economy_rules', 'shop')['values']);
    }

    private function quantities(array $ingredients): array
    {
        $rows = [];
        foreach ($ingredients as $material => $quantity) {
            $rows[] = ['item' => $this->itemLine($material), 'quantity' => (int) $quantity];
        }

        return $rows;
    }

    // ---- Skills ------------------------------------------------------------------------

    /** Skills grouped by the job family that can learn them. */
    public function skills(): array
    {
        $groups = array_fill_keys([...array_values(self::FAMILIES), '多系通用', '怪物技能', '其他'], []);
        foreach (array_keys($this->catalog->playableSkills()) as $id) {
            $families = array_unique(array_map(fn ($job) => self::FAMILIES[intdiv((int) $job, 100) * 100], array_keys($this->index()['learners'][$id] ?? [])));
            $group = match (true) {
                count($families) === 1 => reset($families),
                count($families) > 1 => '多系通用',
                isset($this->index()['skill_users'][$id]) => '怪物技能',
                default => '其他',
            };
            $groups[$group][] = $this->skillLine($id);
        }

        return array_filter($groups);
    }

    public function skill(string $id): array
    {
        $skill = $this->catalog->get('skills', $id);
        $view = $this->skillLine($id);
        $view['category'] = (int) $id === 9000 ? '多重判定' : (! empty($skill['passive']) ? '被动技能' : ((int) ($skill['type'] ?? 0) === 1 ? '魔法' : (! empty($skill['support']) ? '辅助技能' : '战斗技能')));
        $view['special'] = GameText::SPECIAL_EFFECTS[(int) $id] ?? '';
        $view['twice'] = ! empty($skill['support']) && ! empty($skill['pow']) && preg_grep('/^(Up|Down|Plus)/', array_keys($skill)) && ! isset(GameText::SPECIAL_EFFECTS[(int) $id]);
        $view['action'] = PlayerRules::isTacticAction((int) $id) && empty($skill['passive']);
        $view['learners'] = [];
        foreach ($this->index()['learners'][$id] ?? [] as $job => $requires) {
            $view['learners'][] = ['job' => $this->jobLine($job), 'requires' => $requires];
        }
        $view['unlocks'] = array_map(fn ($next) => $this->skillLine($next), $this->index()['unlocks'][$id] ?? []);
        $view['users'] = array_map(fn ($monster) => $this->monsterLine($monster), $this->index()['skill_users'][$id] ?? []);

        return $view;
    }

    // ---- Monsters ----------------------------------------------------------------------

    /** Monsters grouped by the first open map they appear on, then bosses, minions and summons. */
    public function monsters(): array
    {
        $groups = [];
        $index = $this->index();
        foreach ($this->exposedAreas() as $area) {
            $groups[$area['name']] = [];
        }
        $groups += ['共享首领' => [], '首领随从' => [], '召唤物' => [], '未开放地区' => [], '其他' => []];
        foreach (array_keys($this->catalog->playableMonsters()) as $id) {
            $id = (string) $id;
            $open = array_values(array_filter($index['areas'][$id] ?? [], fn ($entry) => $entry['open']));
            $group = match (true) {
                $open !== [] => $open[0]['name'],
                isset($this->catalog->get('monsters', $id)['UnionName']) => '共享首领',
                isset($index['bosses_of'][$id]) => '首领随从',
                isset($index['summoned_by'][$id]) => '召唤物',
                isset($index['areas'][$id]) => '未开放地区',
                default => '其他',
            };
            $groups[$group][] = $this->monsterRow($id);
        }

        return array_filter($groups);
    }

    private function monsterRow(string $id): array
    {
        $monster = $this->catalog->get('monsters', $id);
        $boss = isset($monster['UnionName']);
        $row = $this->monsterLine($id);
        $row['hp'] = $boss ? null : (int) $monster['maxhp'];
        // Shared bosses keep the legacy "????/????" rule: their HP and SP are never published.
        $row['sp'] = $boss ? null : (int) $monster['maxsp'];
        $row['stats'] = array_combine(GameText::STAT_KEYS, array_map(fn ($key) => (int) $monster[$key], GameText::STAT_KEYS));
        $row['exp'] = $boss ? null : (int) ($monster['exphold'] ?? 0);
        $row['money'] = $boss ? null : (int) ($monster['moneyhold'] ?? 0);
        $row['drops'] = count($monster['itemtable'] ?? []);

        return $row;
    }

    public function monster(string $id): array
    {
        $monster = $this->catalog->get('monsters', $id);
        $index = $this->index();
        $view = $this->monsterRow($id);
        $view['atk'] = array_map('intval', $monster['atk']);
        $view['def'] = array_map('intval', $monster['def']);
        $view['position'] = isset($monster['position']) ? __('hof.positions.'.$monster['position']) : '每次出现时随机';
        $view['guard'] = __('hof.guards.'.$monster['guard']);
        $view['specials'] = [];
        foreach ($monster['SPECIAL'] ?? [] as $key => $value) {
            $view['specials'][] = match ($key) {
                'PoisonResist' => '毒耐性 '.$value.'%', 'Undead' => '不死系', default => $key,
            };
        }
        $view['pattern'] = $this->pattern($monster);
        $view['drops'] = [];
        $total = 0;
        foreach ($monster['itemtable'] ?? [] as $item => $weight) {
            $total += (int) $weight;
            $view['drops'][] = ['item' => $this->itemLine($item), 'rate' => GameText::percent((int) $weight / 100)];
        }
        $view['no_drop'] = $view['drops'] ? GameText::percent(max(0, 10000 - $total) / 100) : null;
        $view['areas'] = $index['areas'][$id] ?? [];
        $view['summoned_by'] = array_map(fn ($skill) => $this->skillLine($skill), $index['summoned_by'][$id] ?? []);
        $view['bosses_of'] = array_map(fn ($boss) => $this->monsterLine($boss), $index['bosses_of'][$id] ?? []);
        // Legacy ShowCharWithLand(): bosses stand on their own land, others on their first map's land.
        $land = $monster['land'] ?? ($view['areas'][0]['land'] ?? null);
        $land = UnitCards::LAND_FALLBACKS[$land] ?? $land;
        $view['base'] = $land !== null ? 'image/other/land_'.$land.'.gif' : null;
        $view['variants'] = array_map(fn ($img) => 'image/char/'.basename($img), $monster['image_variants'] ?? []);
        $view['union'] = null;
        if ($view['boss']) {
            $weights = $monster['Slave'];
            $sum = array_sum(array_map(fn ($entry) => (int) $entry[0], $weights));
            $view['union'] = [
                'name' => $monster['UnionName'], 'limit' => (int) $monster['LevelLimit'],
                'cycle' => GameText::cycle($monster['cycle']), 'land' => $monster['land'],
                'amount' => (int) ($monster['SlaveAmount'] ?? 4),
                'fixed' => array_map(fn ($minion) => $this->monsterLine($minion), $monster['SlaveSpecify'] ?? []),
                'minions' => array_map(fn ($minion, $entry) => ['monster' => $this->monsterLine($minion), 'rate' => GameText::percent((int) $entry[0] / $sum * 100)], array_keys($weights), $weights),
            ];
        }

        return $view;
    }

    // ---- Areas, conditions, enchantments -----------------------------------------------

    public function areas(): array
    {
        $areas = [];
        foreach ($this->catalog->all('areas') as $id => $area) {
            $playable = array_filter($area['encounters'], fn ($entry, $monster) => (int) $entry[0] > 0 && $this->exists('monsters', (string) $monster), ARRAY_FILTER_USE_BOTH);
            $total = array_sum(array_map(fn ($entry) => (int) $entry[0], $playable));
            $encounters = [];
            foreach ($playable as $monster => $entry) {
                $encounters[] = ['monster' => $this->monsterLine($monster), 'rate' => GameText::percent((int) $entry[0] / $total * 100), 'shown' => (bool) $entry[1]];
            }
            $unlock = $area['unlock'];
            $areas[$unlock['kind'] === 'unavailable' ? 'closed' : 'open'][$id] = [
                'id' => $id, 'name' => $area['name'], 'name0' => $area['name0'] ?? '',
                'base' => 'image/other/land_'.(UnitCards::LAND_FALLBACKS[$area['land']] ?? $area['land']).'.gif',
                'proper' => $area['proper'], 'encounters' => $encounters,
                'unlock' => match ($unlock['kind']) {
                    'always' => '随时可进入',
                    'item' => '持有「'.$this->text->name('items', $unlock['item']).'」时出现',
                    'daily_window' => '每天 '.$unlock['timezone'].' '.$unlock['from'].'–'.$unlock['until'].' 之间出现（不含结束时刻）',
                    default => '当前未开放',
                },
                'unlock_item' => $unlock['kind'] === 'item' ? $this->itemLine($unlock['item']) : null,
            ];
        }

        return $areas + ['open' => [], 'closed' => []];
    }

    /** Selectable conditions grouped under the legacy heading records. */
    public function conditions(): array
    {
        $groups = [];
        $heading = '基本';
        $selectable = $this->catalog->selectableConditions();
        foreach ($this->catalog->all('conditions') as $id => $condition) {
            if ($condition['css'] ?? false) {
                $heading = $condition['exp'];

                continue;
            }
            if (isset($selectable[$id])) {
                $groups[$heading][] = ['id' => (string) $id, 'text' => $this->text->condition($id)];
            }
        }

        return $groups;
    }

    public function enchantRow(string $id): array
    {
        return ['id' => $id, 'name' => $this->text->enchantName($id), 'effect' => $this->text->enchantEffect($id)];
    }

    public function enchants(): array
    {
        $rows = array_map(fn ($id) => $this->enchantRow((string) $id), array_keys($this->catalog->all('enchants')));
        $pools = [];
        foreach ($this->catalog->all('enchant_pools') as $type => $pool) {
            $pools[] = ['type' => $type, 'low' => count($pool['low']), 'high' => count($pool['high']),
                'low_names' => array_map(fn ($e) => $this->text->enchantName((string) $e), $pool['low']),
                'high_names' => array_map(fn ($e) => $this->text->enchantName((string) $e), $pool['high'])];
        }
        $materials = [];
        foreach ($this->catalog->all('items') as $id => $item) {
            if (! empty($item['Add']) && (int) $id >= 7000 && (int) $id < 7200) {
                $materials[] = ['item' => $this->itemLine($id), 'enchant' => $this->enchantRow((string) $item['Add'])];
            }
        }

        return ['rows' => $rows, 'pools' => $pools, 'materials' => $materials];
    }

    /** Areas a player can reach at some time (always, by item, or by daily window). */
    public function exposedAreas(): array
    {
        return array_filter($this->catalog->all('areas'), fn ($area) => $area['unlock']['kind'] !== 'unavailable');
    }

    // ---- Reverse indexes ---------------------------------------------------------------

    private function index(): array
    {
        if (isset($this->memo['index'])) {
            return $this->memo['index'];
        }
        $index = ['drops' => [], 'areas' => [], 'summoned_by' => [], 'bosses_of' => [], 'skill_users' => [], 'learners' => [], 'unlocks' => []];
        foreach ($this->catalog->playableMonsters() as $id => $monster) {
            foreach ($monster['itemtable'] ?? [] as $item => $weight) {
                $index['drops'][(string) $item][] = ['monster' => (string) $id, 'weight' => (int) $weight];
            }
            foreach (array_unique($monster['action'] ?? []) as $skill) {
                if ((int) $skill !== 9000) {
                    $index['skill_users'][(string) $skill][] = (string) $id;
                }
            }
            foreach (array_unique([...array_keys($monster['Slave'] ?? []), ...($monster['SlaveSpecify'] ?? [])]) as $minion) {
                $index['bosses_of'][(string) $minion][] = (string) $id;
            }
        }
        foreach ($this->catalog->all('areas') as $areaId => $area) {
            $total = array_sum(array_map(fn ($entry) => max(0, (int) $entry[0]), $area['encounters']));
            foreach ($area['encounters'] as $monster => $entry) {
                if ((int) $entry[0] > 0) {
                    $index['areas'][(string) $monster][] = ['id' => $areaId, 'name' => $area['name'], 'land' => $area['land'], 'open' => $area['unlock']['kind'] !== 'unavailable',
                        'rate' => GameText::percent((int) $entry[0] / $total * 100), 'shown' => (bool) $entry[1]];
                }
            }
        }
        foreach ($this->catalog->playableSkills() as $id => $skill) {
            foreach (array_unique((array) ($skill['summon'] ?? [])) as $monster) {
                $index['summoned_by'][(string) $monster][] = (string) $id;
            }
        }
        foreach ([1018, 1019, 1020, 1021, 5002] as $monster) {
            $index['summoned_by'][(string) $monster][] = '5803';
        }
        foreach ($this->catalog->all('base_characters') as $base) {
            foreach ($base['skill'] as $skill) {
                $index['learners'][(string) $skill][(string) $base['job']] = '初始技能';
            }
        }
        foreach (array_keys($this->catalog->all('jobs')) as $job) {
            foreach ($this->catalog->all('skill_tree') as $rule) {
                $residual = $this->residual($rule['when'], (string) $job);
                if ($residual === false) {
                    continue;
                }
                $index['learners'][$rule['skill']][(string) $job] ??= $residual === true ? '' : $this->requirement($residual);
                if (is_array($residual)) {
                    array_walk_recursive($residual, function ($value, $key) use (&$index, $rule) {
                        if ($key === 'learned' && ! in_array($rule['skill'], $index['unlocks'][(string) $value] ?? [], true)) {
                            $index['unlocks'][(string) $value][] = $rule['skill'];
                        }
                    });
                }
            }
        }

        return $this->memo['index'] = $index;
    }
}
