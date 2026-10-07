<?php

declare(strict_types=1);

namespace App\Application\World;

use App\Application\Battle\BattleService;
use App\Application\Community\CommunityService;
use App\Application\Dungeon\DungeonService;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Application\Player\Vitals;
use App\Domain\Character\Attributes;
use App\Domain\Combat\Fatigue;
use App\Domain\Content\ContentCatalog;

/**
 * Numbers for the "数值规则" page and the manual. Every value is read from the constant or
 * function the game itself uses, so the published rules cannot drift from the implementation.
 */
final class GameRules
{
    public const MAX_LEVEL = 50;

    /** INT where the tactic row count reaches its highest tier. */
    private const PATTERN_TOP = 200;

    public const PARTY_MAX = 5;

    public function __construct(private ContentCatalog $catalog) {}

    public function experienceTable(): array
    {
        $rows = [];
        for ($level = 1; $level < self::MAX_LEVEL; $level++) {
            $rows[] = ['level' => $level, 'next' => PlayerRules::experienceRequired($level)];
        }

        return $rows;
    }

    /** INT thresholds where the tactic row count changes, with the level-30 bonus; the last row is open-ended. */
    public function patternTable(): array
    {
        $rows = [];
        $previous = null;
        for ($int = 0; $int <= self::PATTERN_TOP; $int++) {
            $count = PlayerRules::maxPatterns($int, 1);
            if ($count !== $previous) {
                $rows[] = ['from' => $int, 'rows' => $count, 'rows30' => PlayerRules::maxPatterns($int, 30)];
                $previous = $count;
            }
        }
        foreach ($rows as $i => &$row) {
            $row['to'] = isset($rows[$i + 1]) ? $rows[$i + 1]['from'] - 1 : null;
        }

        return $rows;
    }

    public function refineTable(): array
    {
        $rows = [];
        for ($level = 0; $level < 10; $level++) {
            $rows[] = ['from' => $level, 'to' => $level + 1, 'chance' => PlayerRules::refineChance($level),
                'atk' => GameText::percent((($level + 1) ** 2) / 100 * 100, 0), 'def' => GameText::percent(3 * ($level + 1), 0)];
        }

        return $rows;
    }

    /** Starting attributes of each recruitable base character, including vitality. */
    public function startingAttributes(): array
    {
        $rows = [];
        foreach (array_keys(PlayerRules::RECRUIT_PRICES) as $type) {
            $base = $this->catalog->get('base_characters', $type);
            $job = $this->catalog->get('jobs', $base['job']);
            $stats = Attributes::starting($base, $type);
            $rows[] = ['type' => $type, 'job' => (string) $base['job'], 'name' => trim($job['name_male']), 'stats' => $stats,
                'hp' => Attributes::maxHp((float) $job['coe'][0], 1, $stats['vit']), 'sp' => Attributes::maxSp((float) $job['coe'][1], 1, $stats['int'])];
        }

        return $rows;
    }

    /** Fatigue tiers for display: [label, output penalty, speed penalty]. */
    public function fatigueTable(): array
    {
        $rows = [];
        foreach (Fatigue::TIERS as $minimum => [$output, $speed]) {
            $rows[] = ['label' => $minimum > 0 ? '≥ '.$minimum.'%' : '大于 0', 'output' => $output, 'speed' => $speed];
        }
        $rows[] = ['label' => '0', 'output' => Fatigue::EXHAUSTED[0], 'speed' => Fatigue::EXHAUSTED[1]];

        return $rows;
    }

    public function recruits(): array
    {
        $rows = [];
        foreach (PlayerRules::RECRUIT_PRICES as $type => $price) {
            $base = $this->catalog->get('base_characters', $type);
            $job = $this->catalog->get('jobs', $base['job']);
            $rows[] = ['type' => $type, 'job' => (string) $base['job'], 'name' => trim($job['name_male']), 'name_female' => trim($job['name_female']), 'price' => $price];
        }

        return $rows;
    }

    public function resetItems(): array
    {
        $rows = [];
        foreach ([7510 => '所有属性中超过 1 的部分', 7511 => '所有属性中超过 30 的部分', 7512 => '所有属性中超过 50 的部分', 7513 => '所有属性中超过 100 的部分', 7520 => '除初始技能外已学会的技能'] as $id => $effect) {
            $rows[] = ['id' => (string) $id, 'effect' => $effect];
        }

        return $rows;
    }

    /** Scalar constants grouped for the rules page. Each value names its source in a code comment only. */
    public function constants(): array
    {
        return [
            'party_max' => self::PARTY_MAX, 'max_level' => self::MAX_LEVEL, 'stat_points' => Attributes::POINTS_PER_LEVEL,
            'stamina_base' => Attributes::STAMINA_BASE, 'stamina_refills' => Attributes::STAMINA_REFILLS_PER_DAY,
            'stamina_hours' => 24 / Attributes::STAMINA_REFILLS_PER_DAY, 'health_hour' => Vitals::HEALTH_PERCENT_PER_HOUR,
            'dungeon_move' => DungeonService::MOVE_STAMINA, 'dungeon_battle' => DungeonService::BATTLE_STAMINA,
            'carry_base' => Attributes::CARRY_BASE, 'carry_str' => Attributes::CARRY_STR_STEP,
            'dodge_dex' => Attributes::DODGE_DEX_STEP, 'dodge_max' => Attributes::DODGE_MAX,
            'disarm_dex' => Attributes::DISARM_DEX_STEP, 'disarm_max' => Attributes::DISARM_MAX,
            'scout_base' => Attributes::SCOUT_BASE, 'scout_luk' => Attributes::SCOUT_LUK_STEP, 'scout_max' => Attributes::SCOUT_MAX,
            'chest_luk' => Attributes::CHEST_LUK_STEP, 'chest_max' => Attributes::CHEST_MAX,
            'dying_steps' => Attributes::DYING_STEPS, 'dying_vit' => Attributes::DYING_VIT_STEP,
            'initiative_ratio' => Attributes::INITIATIVE_RATIO, 'initiative_progress' => Attributes::INITIATIVE_PROGRESS,
            'work_stamina' => PlayerService::WORK_STAMINA, 'work_pay' => PlayerService::WORK_PAY,
            'rename_team' => PlayerService::TEAM_RENAME_PRICE,
            'actions' => BattleService::ACTION_LIMIT, 'simulation_actions' => BattleService::SIMULATION_ACTION_LIMIT,
            'boss_stamina' => BossService::CHALLENGE_STAMINA, 'boss_cooldown' => BossService::COOLDOWN_MINUTES,
            'rank_team_hours' => RankingService::TEAM_CHANGE_HOURS, 'rank_win' => RankingService::WIN_COOLDOWN_SECONDS,
            'rank_other' => RankingService::OTHER_COOLDOWN_SECONDS,
            'auction_card' => AuctionService::MEMBERSHIP_PRICE, 'auction_fee' => AuctionService::LISTING_FEE,
            'auction_hours' => AuctionService::DURATIONS, 'auction_max' => AuctionService::MAX_ACTIVE,
            'auction_interval' => AuctionService::LISTING_INTERVAL_SECONDS, 'auction_extend' => AuctionService::EXTENSION_MINUTES,
            'auction_types' => AuctionService::TYPES, 'refinable' => PlayerRules::REFINABLE,
            'board' => CommunityService::KEEP_MESSAGES,
        ];
    }

    /** Arena tiers for the first positions: 1 → tier 1, 2–3 → tier 2, then three places per tier. */
    public function rankTiers(int $positions = 12): array
    {
        $tiers = [];
        for ($position = 1; $position <= $positions; $position++) {
            $tiers[RankingService::place($position)][] = $position;
        }

        return $tiers;
    }
}
