<?php

namespace App\Application\Multiplayer;

use App\Application\Battle\BattleService;
use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BossService
{
    // Each of these source-defined union encounters receives one fresh instance.
    public const ROSTER = [2000, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010, 2011];

    public function __construct(private GameAction $actions, private ContentCatalog $content, private BattleService $battles) {}

    /** Return display-only values; never expose the boss definition or resources. */
    public function summaries(): array
    {
        return BossInstance::orderBy('id')->get(['id', 'definition', 'hp', 'respawns_at'])
            ->map(static fn (BossInstance $boss): array => [
                'id' => $boss->id,
                'name' => (string) $boss->definition['UnionName'],
                'limit' => (int) $boss->definition['LevelLimit'],
                'alive' => $boss->hp > 0,
                'respawns_at' => $boss->respawns_at,
                'img' => 'image/char/'.basename($boss->definition['img']),
            ])->all();
    }

    public function bootstrap(): int
    {
        return DB::transaction(function () {
            $this->actions->lock();
            $created = 0;
            foreach (self::ROSTER as $id) {
                if (BossInstance::where('monster_id', (string) $id)->exists()) {
                    continue;
                }
                $definition = $this->definition($id);
                BossInstance::create(['monster_id' => (string) $id, 'definition' => $definition, 'hp' => $definition['maxhp'], 'sp' => $definition['maxsp']]);
                $created++;
            }

            return $created;
        }, 3);
    }

    public function respawnDue(): int
    {
        return DB::transaction(function () {
            $this->actions->lock();
            $count = 0;
            foreach (BossInstance::where('hp', 0)->where('respawns_at', '<=', now())->lockForUpdate()->get() as $boss) {
                $this->respawn($boss);
                $count++;
            }

            return $count;
        }, 3);
    }

    private function definition(int|string $id): array
    {
        $definition = $this->content->monster($id, User::count(), true);
        $definition['cycle'] = $this->content->bossCycle($id, now()->toDateTimeImmutable());
        $definition['content_version'] = $this->content->version();

        return $definition;
    }

    private function respawn(BossInstance $boss): void
    {
        $definition = $this->definition($boss->monster_id);
        $boss->definition = $definition;
        $boss->hp = $definition['maxhp'];
        $boss->sp = $definition['maxsp'];
        $boss->generation++;
        $boss->defeated_at = null;
        $boss->respawns_at = null;
        $boss->save();
    }

    public function challenge(int $userId, string $key, int $bossId, array $party): array
    {
        $party = array_map('intval', $party);

        return $this->actions->execute($userId, 'boss.challenge', $key, compact('bossId', 'party'), function (User $user, int $op, int $seed) use ($bossId, $party) {
            $boss = BossInstance::lockForUpdate()->findOrFail($bossId);
            if ($boss->hp === 0 && $boss->respawns_at && $boss->respawns_at->lessThanOrEqualTo(now())) {
                $this->respawn($boss);
            }
            $this->actions->ensure($boss->hp > 0, 'This boss is defeated and has not respawned.');
            $this->actions->ensure(count($party) >= 1 && count($party) <= 5 && count(array_unique($party)) === count($party), 'Choose one to five different characters.');
            $characters = Character::where('user_id', $user->id)->whereIn('id', $party)->lockForUpdate()->get();
            $this->actions->ensure($characters->count() === count($party), 'Party contains an unavailable character.');
            $this->actions->ensure($characters->sum('level') <= (int) $boss->definition['LevelLimit'], 'Party level exceeds this boss limit.');
            $this->actions->ensure(! BossChallenge::where('user_id', $user->id)->where('created_at', '>', now()->subMinutes(20))->exists(), 'Wait 20 minutes between shared-boss challenges.');
            $this->actions->stamina($user, 10, $op, 'shared boss challenge');
            $before = $boss->hp;
            $report = $this->battles->fightBoss($user, $party, $boss->definition, $boss->hp, $boss->sp, $op, $seed);
            $boss->hp = max(0, min((int) $boss->definition['maxhp'], (int) $report['boss_hp']));
            $boss->sp = max(0, min((int) $boss->definition['maxsp'], (int) $report['boss_sp']));
            $killed = $boss->hp === 0;
            if ($killed) {
                $boss->defeated_at = now();
                $boss->respawns_at = now()->addSeconds((int) $boss->definition['cycle']);
            }
            $boss->save();
            $challenge = BossChallenge::create(['boss_instance_id' => $boss->id, 'user_id' => $user->id, 'generation' => $boss->generation, 'damage' => max(0, $before - $boss->hp), 'killed' => $killed, 'report' => $report]);

            return ['message' => $killed ? 'The shared boss was defeated.' : 'Shared-boss challenge completed.', 'challenge_id' => $challenge->id, 'killed' => $killed];
        });
    }
}
