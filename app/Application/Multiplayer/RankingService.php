<?php

namespace App\Application\Multiplayer;

use App\Application\Battle\BattleService;
use App\Application\Support\GameAction;
use App\Domain\Combat\SeededRandom;
use App\Domain\Content\ContentCatalog;
use App\Models\Character;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;

final class RankingService
{
    public const TEAM_CHANGE_HOURS = 48;

    public const WIN_COOLDOWN_SECONDS = 60;

    public const OTHER_COOLDOWN_SECONDS = 86400;

    public function __construct(private GameAction $actions, private ContentCatalog $content, private BattleService $battles) {}

    public static function place(int $position): int
    {
        return $position === 1 ? 1 : ($position <= 3 ? 2 : 3 + intdiv($position - 4, 3));
    }

    public function register(int $userId, string $key, array $party): array
    {
        $party = array_map('intval', $party);

        return $this->actions->execute($userId, 'ranking.register', $key, compact('party'), function (User $user) use ($party) {
            $this->validateParty($user->id, $party);
            $entry = RankingEntry::where('user_id', $user->id)->lockForUpdate()->first();
            $this->actions->ensure(! $entry || $entry->party_set_at->addHours(self::TEAM_CHANGE_HOURS)->lessThanOrEqualTo(now()), 'The ranking team can be changed once every 48 hours.');
            if (! $entry) {
                $entry = new RankingEntry(['user_id' => $user->id]);
            }
            $entry->party = $party;
            $entry->party_set_at = now();
            $entry->save();

            return ['message' => 'Ranking team registered.'];
        });
    }

    public function challenge(int $userId, string $key): array
    {
        return $this->actions->execute($userId, 'ranking.challenge', $key, [], function (User $user, int $op, int $seed) {
            $entry = RankingEntry::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $this->validateParty($user->id, $entry->party);
            $this->actions->ensure(! $entry->challenge_at || $entry->challenge_at->lessThanOrEqualTo(now()), 'Ranking challenge cooldown has not expired.');
            // Repacking mirrors rank2's file reload after deleted accounts leave gaps.
            $ladder = RankingEntry::whereNotNull('position')->orderBy('position')->lockForUpdate()->get();
            foreach ($ladder as $i => $rank) {
                if ($rank->position !== $i + 1) {
                    $rank->position = $i + 1;
                    $rank->save();
                }
            }
            $entry->refresh();
            if (! $entry->position) {
                $entry->position = $ladder->count() + 1;
                $entry->save();
            }
            if ($ladder->isEmpty()) {
                return ['message' => 'You established the ranking ladder.', 'place' => 1];
            }
            $place = self::place($entry->position);
            $this->actions->ensure($place > 1, 'First place cannot challenge.');
            $opponents = $ladder->filter(fn ($rank) => self::place($rank->position) === $place - 1)->values();
            $rng = new SeededRandom($seed);
            $defender = $opponents[$rng->integer(0, $opponents->count() - 1)];
            $opponent = User::lockForUpdate()->findOrFail($defender->user_id);
            $defenderParty = Character::where('user_id', $opponent->id)->whereIn('id', $defender->party)->pluck('id')->all();
            $report = null;
            if (! $defenderParty) {
                $winner = 0;
                $result = 'defender_no_party';
            } else {
                $report = $this->battles->simulateParties($user, $entry->party, $opponent, $defenderParty, 'pvp', $seed);
                $winner = $report['winner'];
                $result = $winner === 0 ? 'challenger_win' : ($winner === 1 ? 'defender_win' : 'draw');
            }
            $topDefense = self::place($defender->position) === 1;
            if ($winner === 0) {
                $position = $entry->position;
                $target = $defender->position;
                $entry->position = null;
                $entry->save();
                $defender->position = $position;
                $defender->save();
                $entry->position = $target;
                if ($result !== 'defender_no_party') {
                    $entry->wins++;
                }
                $defender->losses++;
            } elseif ($winner === 1) {
                $entry->losses++;
                $defender->wins++;
                if ($topDefense) {
                    $defender->defenses++;
                }
            } else {
                $entry->draws++;
                $defender->draws++;
                if ($topDefense) {
                    $defender->defenses++;
                }
            }
            $entry->challenge_at = now()->addSeconds($winner === 0 ? self::WIN_COOLDOWN_SECONDS : self::OTHER_COOLDOWN_SECONDS);
            $entry->save();
            $defender->save();
            $challenge = RankingChallenge::create(['challenger_id' => $user->id, 'defender_id' => $opponent->id, 'result' => $result, 'report' => $report]);

            return ['message' => 'Ranking challenge completed.', 'result' => $result, 'challenge_id' => $challenge->id, 'place' => self::place($entry->position)];
        });
    }

    private function validateParty(int $userId, array $party): void
    {
        $this->actions->ensure(count($party) >= 1 && count($party) <= 5 && count(array_unique($party)) === count($party), 'Choose one to five different characters.');
        $this->actions->ensure(Character::where('user_id', $userId)->whereIn('id', $party)->count() === count($party), 'Ranking party contains an unavailable character.');
    }
}
