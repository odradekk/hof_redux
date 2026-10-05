<?php

declare(strict_types=1);

namespace App\Application\World;

use App\Application\Battle\BattleService;
use App\Application\Community\CommunityService;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;

/**
 * Numbers for the "数值规则" page and the manual. Every value is read from the constant or
 * function the game itself uses, so the published rules cannot drift from the implementation.
 */
final class GameRules
{
    public const MAX_LEVEL = 50;

    public const STAT_CAP = 255;

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

    /** INT thresholds where the tactic row count changes, with the level-30 bonus. */
    public function patternTable(): array
    {
        $rows = [];
        $previous = null;
        for ($int = 0; $int <= self::STAT_CAP; $int++) {
            $count = PlayerRules::maxPatterns($int, 1);
            if ($count !== $previous) {
                $rows[] = ['from' => $int, 'rows' => $count, 'rows30' => PlayerRules::maxPatterns($int, 30)];
                $previous = $count;
            }
        }
        foreach ($rows as $i => &$row) {
            $row['to'] = isset($rows[$i + 1]) ? $rows[$i + 1]['from'] - 1 : self::STAT_CAP;
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

    /** Maximum HP/SP of a job at a level and attribute, the same formula as PlayerService::refreshVitals(). */
    public static function vital(float $coefficient, int $level, int $attribute): int
    {
        return (int) round(100 * $coefficient * (1 + ($level - 1) / 49) * (1 + (255 ** 2 - (255 - $attribute) ** 2) / (255 ** 2)));
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
            'party_max' => self::PARTY_MAX, 'max_level' => self::MAX_LEVEL, 'stat_cap' => self::STAT_CAP,
            'stamina_max' => GameAction::STAMINA_MAX, 'stamina_day' => GameAction::STAMINA_PER_DAY,
            'stamina_seconds' => 86400 / GameAction::STAMINA_PER_DAY,
            'hunt' => WorldService::HUNT_STAMINA, 'work_stamina' => PlayerService::WORK_STAMINA, 'work_pay' => PlayerService::WORK_PAY,
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
