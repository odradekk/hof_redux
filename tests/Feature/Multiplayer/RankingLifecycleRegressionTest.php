<?php

namespace Tests\Feature\Multiplayer;

use App\Application\Community\AccountDeletion;
use App\Application\Support\GameAction;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RankingLifecycleRegressionTest extends TestCase
{
    use DatabaseMigrations;

    private const PASSWORD = 'Synthetic-pass-2026';

    private int $client = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        self::assertSame(0, DB::transactionLevel(), 'Committed state must not be hidden by an outer test transaction.');
    }

    private function player(string $login): array
    {
        if (Auth::check()) {
            $this->post('/logout')->assertRedirect('/login');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(++$this->client)]);
        $this->post('/register', ['login' => $login, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect('/setup');
        $this->post('/setup', ['name' => $login, 'character_name' => $login.'hero', 'base_type' => 1, 'gender' => 0])
            ->assertSessionHasNoErrors()->assertRedirect('/');
        $user = User::where('login', $login)->firstOrFail();
        self::assertSame(10000, $user->money);

        return [$user, $user->characters()->firstOrFail()->id];
    }

    private function postAs(User $user, string $path, array $payload = [])
    {
        $this->app['session']->forget('errors');

        return $this->actingAs($user->fresh())->post($path, ['operation_id' => (string) Str::uuid()] + $payload);
    }

    private function register(User $user, array $party)
    {
        return $this->postAs($user, '/ranking/team', ['party' => $party]);
    }

    private function recruit(User $user, string $name = 'Second'): int
    {
        $before = $user->fresh()->money;
        $this->postAs($user, '/player/recruit', ['base_type' => 2, 'gender' => 0, 'name' => $name])->assertSessionHasNoErrors();
        self::assertSame($before - 2000, $user->fresh()->money);

        return (int) $user->characters()->max('id');
    }

    private function dismiss(User $user, int $id): void
    {
        $this->postAs($user, '/player/dismiss', ['character_id' => $id])->assertSessionHasNoErrors();
        self::assertFalse($user->characters()->whereKey($id)->exists());
    }

    private function entry(User $user): RankingEntry
    {
        return RankingEntry::where('user_id', $user->id)->firstOrFail();
    }

    private function committedEntry(User $user): RankingEntry
    {
        self::assertSame(0, DB::transactionLevel());
        if (DB::getDriverName() === 'pgsql') {
            DB::disconnect();
            DB::reconnect();
        }

        return $this->entry($user);
    }

    private function ladder(int $size = 2): array
    {
        for ($i = 0; $i < $size; $i++) {
            [$user, $character] = $this->player('ladder'.$i);
            $this->register($user, [$character])->assertSessionHasNoErrors();
            $this->postAs($user, '/ranking/challenge')->assertSessionHasNoErrors();
        }
        self::assertSame(range(1, $size), RankingEntry::orderBy('position')->pluck('position')->all());

        return RankingEntry::orderBy('position')->get()->map(fn ($entry) => User::findOrFail($entry->user_id))->all();
    }

    public function test_self_deleting_first_place_commits_repack_even_when_survivor_challenges_are_rejected(): void
    {
        [$top, $survivor] = $this->ladder();
        $this->postAs($top, '/account/delete', ['current_password' => 'wrong-password', 'confirm' => 'DELETE'])
            ->assertSessionHasErrorsIn('deleteAccount', 'current_password');
        self::assertSame([1, 2], RankingEntry::orderBy('position')->pluck('position')->all());
        $this->postAs($top, '/account/delete', ['current_password' => self::PASSWORD, 'confirm' => 'DELETE'])
            ->assertSessionHasNoErrors()->assertRedirect('/login');
        self::assertNull(User::find($top->id));
        $positions = [$this->committedEntry($survivor)->position];
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $before = $this->entry($survivor)->only(['challenge_at', 'wins', 'losses', 'draws', 'defenses', 'party_set_at']);
        $operations = DB::table('operations')->count();
        $challenges = RankingChallenge::count();
        for ($i = 0; $i < 3; $i++) {
            $this->postAs($survivor, '/ranking/challenge')->assertSessionHasErrors(['game' => 'First place cannot challenge.']);
            $positions[] = $this->committedEntry($survivor)->position;
        }
        self::assertSame([1, 1, 1, 1], $positions, 'Deletion must commit compaction independently of subsequent challenge validation.');
        self::assertEquals($before, $this->entry($survivor)->only(array_keys($before)));
        self::assertSame($operations, DB::table('operations')->count());
        self::assertSame($challenges, RankingChallenge::count());
        $this->actingAs($survivor->fresh())->get('/ranking')->assertOk()->assertViewHas('ownPlace', 1)->assertViewHas('canChallenge', false);
    }

    public function test_deleting_middle_and_first_slots_preserves_survivor_order_and_unranked_registration(): void
    {
        $players = $this->ladder(4);
        [$waiting, $character] = $this->player('waiting');
        $this->register($waiting, [$character])->assertSessionHasNoErrors();
        foreach ([$players[1], $players[0]] as $deleted) {
            $this->postAs($deleted, '/account/delete', ['current_password' => self::PASSWORD, 'confirm' => 'DELETE'])->assertSessionHasNoErrors();
        }
        self::assertSame(1, $this->committedEntry($players[2])->position);
        self::assertSame(2, $this->entry($players[3])->position);
        self::assertNull($this->entry($waiting)->position);
    }

    public function test_administrator_account_deletion_also_commits_slot_compaction(): void
    {
        [$top, $survivor] = $this->ladder();
        [$admin] = $this->player('operator');
        $this->artisan('admin:access', ['login' => $admin->login, '--confirm' => true])->assertSuccessful();
        $this->postAs($admin, '/admin/users/'.$top->id.'/delete', ['confirm' => 'DELETE', 'current_password' => self::PASSWORD])->assertSessionHasNoErrors();
        self::assertNull(User::find($top->id));
        self::assertSame(1, $this->committedEntry($survivor)->position);
        self::assertSame([1], RankingEntry::pluck('position')->all());
    }

    public function test_partial_registered_dismissal_keeps_survivors_usable_without_adding_recruits(): void
    {
        [$defender, $d] = $this->player('defender');
        $this->register($defender, [$d])->assertSessionHasNoErrors();
        $this->postAs($defender, '/ranking/challenge')->assertSessionHasNoErrors();
        [$attacker, $a] = $this->player('attacker');
        $b = $this->recruit($attacker);
        $c = $this->recruit($attacker, 'Third');
        $registered = [$c, $b, $a];
        $this->register($attacker, $registered)->assertSessionHasNoErrors();
        $setAt = $this->entry($attacker)->party_set_at;
        $this->dismiss($attacker, $b);
        $new = $this->recruit($attacker, 'Newcomer');
        $characters = $attacker->characters()->orderBy('id')->get()->toArray();
        $money = $attacker->fresh()->money;
        $key = (string) Str::uuid();
        $this->actingAs($attacker->fresh())->post('/ranking/challenge', ['operation_id' => $key])->assertSessionHasNoErrors();
        $report = RankingChallenge::firstOrFail()->report;
        self::assertSame([$c, $a], array_column($report['initial_teams'][0], 'character_id'));
        self::assertNotContains($new, array_column($report['initial_teams'][0], 'character_id'));
        $entry = $this->committedEntry($attacker);
        self::assertSame($registered, $entry->party);
        self::assertEquals($setAt, $entry->party_set_at);
        self::assertNotNull($entry->challenge_at);
        $this->actingAs($attacker->fresh())->post('/ranking/challenge', ['operation_id' => $key])->assertSessionHasNoErrors();
        self::assertSame(1, RankingChallenge::count());
        $this->postAs($attacker, '/ranking/challenge')->assertSessionHasErrors(['game' => 'Ranking challenge cooldown has not expired.']);
        self::assertSame($money, $attacker->fresh()->money);
        self::assertSame($characters, $attacker->characters()->orderBy('id')->get()->toArray());
    }

    public function test_dismissal_never_bypasses_or_restarts_the_48_hour_reselection_cooldown(): void
    {
        [$user, $a] = $this->player('cooldown');
        $b = $this->recruit($user);
        $this->register($user, [$a, $b])->assertSessionHasNoErrors();
        $setAt = $this->entry($user)->party_set_at;
        $error = ['game' => 'The ranking team can be changed once every 48 hours.'];
        $this->register($user, [$a])->assertSessionHasErrors($error);
        $this->travel(1)->hours();
        $this->dismiss($user, $b);
        $new = $this->recruit($user, 'Newcomer');
        $this->register($user, [$a, $new])->assertSessionHasErrors($error);
        $this->travelTo($setAt->addHours(48)->subSecond());
        $this->register($user, [$a])->assertSessionHasErrors($error);
        self::assertEquals($setAt, $this->entry($user)->party_set_at);
        self::assertSame([$a, $b], $this->entry($user)->party);
        $this->travel(1)->seconds();
        $this->register($user, [$a, $new])->assertSessionHasNoErrors();
        self::assertSame([$a, $new], $this->entry($user)->party);
        self::assertEquals(now(), $this->entry($user)->party_set_at);
    }

    public function test_all_registered_members_missing_is_rejected_even_when_an_unregistered_character_remains(): void
    {
        [$user, $registered] = $this->player('emptyparty');
        $this->register($user, [$registered])->assertSessionHasNoErrors();
        $replacement = $this->recruit($user);
        $this->dismiss($user, $registered);
        self::assertSame([$replacement], $user->characters()->pluck('id')->all());
        $before = $this->entry($user)->toArray();
        $operations = DB::table('operations')->count();
        $this->postAs($user, '/ranking/challenge')->assertSessionHasErrors('game');
        self::assertSame($before, $this->committedEntry($user)->toArray());
        self::assertSame($operations, DB::table('operations')->count());
        self::assertSame(0, RankingChallenge::count());
        $this->register($user, [$replacement])->assertSessionHasErrors(['game' => 'The ranking team can be changed once every 48 hours.']);
    }

    public function test_unregistered_dismissal_and_partial_defense_keep_existing_behavior(): void
    {
        [$defender, $d] = $this->player('controldef');
        $removed = $this->recruit($defender);
        $this->register($defender, [$d, $removed])->assertSessionHasNoErrors();
        $this->postAs($defender, '/ranking/challenge')->assertSessionHasNoErrors();
        $this->dismiss($defender, $removed);
        [$attacker, $a] = $this->player('controlatk');
        $extra = $this->recruit($attacker);
        $this->register($attacker, [$a])->assertSessionHasNoErrors();
        $this->dismiss($attacker, $extra);
        $this->postAs($attacker, '/ranking/challenge')->assertSessionHasNoErrors();
        $report = RankingChallenge::firstOrFail()->report;
        self::assertSame([$a], array_column($report['initial_teams'][0], 'character_id'));
        self::assertSame([$d], array_column($report['initial_teams'][1], 'character_id'));
        self::assertSame([$d, $removed], $this->entry($defender)->party);
    }

    public function test_a_parallel_challenge_waits_for_deletion_and_observes_the_committed_compact_ladder(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and an independent PHP process.');
        }
        [$top, $survivor] = $this->ladder();
        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        $connection = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Date::setTestNow($argv[4]);
Illuminate\Support\Facades\DB::statement("SET application_name = 'ranking-lifecycle-regression'");
try {
    $result = app(App\Application\Multiplayer\RankingService::class)->challenge((int) $argv[2], $argv[3]);
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['errors' => $e->errors()], JSON_THROW_ON_ERROR);
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, base_path(), (string) $survivor->id, (string) Str::uuid(), now()->toIso8601String()], base_path(), $env, timeout: 30);
        DB::beginTransaction();
        try {
            app(GameAction::class)->lock();
            app(AccountDeletion::class)->delete($top->id);
            $process->start();
            $blocked = false;
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = 'ranking-lifecycle-regression' AND wait_event_type = 'Lock' AND wait_event = 'advisory') AS blocked")->blocked;
                if ($blocked || ! $process->isRunning()) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertTrue((bool) $blocked, 'The independent challenge must wait on the shared gameplay advisory lock. '.$process->getErrorOutput());
            DB::commit();
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(['errors' => ['game' => ['First place cannot challenge.']]], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->committedEntry($survivor)->position);
            self::assertSame([1], RankingEntry::pluck('position')->all());
            self::assertSame(1, RankingChallenge::count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}
