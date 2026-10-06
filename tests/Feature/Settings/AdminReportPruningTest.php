<?php

namespace Tests\Feature\Settings;

use App\Application\Multiplayer\BossService;
use App\Models\AdminAudit;
use App\Models\BattleReport;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\RankingChallenge;
use App\Models\User;
use App\Services\CharacterFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminReportPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pve_pruning_matches_only_ordinary_mode_before_the_cutoff(): void
    {
        [$admin, $ordinary, $simulation] = $this->reports();
        $ordinaryBefore = $ordinary->getAttributes();
        $simulationBefore = $simulation->getAttributes();
        $atCutoff = $ordinary->replicate()->fill(['created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00']);
        $atCutoff->save();
        $atCutoff->refresh();
        $afterCutoff = $simulation->replicate()->fill(['created_at' => '2026-10-06 00:00:01', 'updated_at' => '2026-10-06 00:00:01']);
        $afterCutoff->save();
        $afterCutoff->refresh();
        $privateOrdinary = $ordinary->replicate()->fill(['public' => false, 'created_at' => '2026-10-05 23:59:59']);
        $privateOrdinary->save();
        [$pvp, $boss] = $this->otherReports($admin);
        $pvpBefore = $pvp->getAttributes();
        $bossBefore = $boss->getAttributes();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('<option value="pve">普通</option>', false);
        $data = $this->pruneData();
        $this->from('/admin')->post('/admin/reports', $data)->assertRedirect('/admin')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('battle_reports', ['id' => $ordinary->id]);
        $this->assertDatabaseMissing('battle_reports', ['id' => $privateOrdinary->id]);
        $this->assertDatabaseHas('battle_reports', ['id' => $simulation->id]);
        $this->assertSame($simulationBefore, $simulation->fresh()->getAttributes());
        $this->assertSame($atCutoff->getAttributes(), $atCutoff->fresh()->getAttributes());
        $this->assertSame($afterCutoff->getAttributes(), $afterCutoff->fresh()->getAttributes());
        $this->assertSame($pvpBefore, $pvp->fresh()->getAttributes());
        $this->assertSame($bossBefore, $boss->fresh()->getAttributes());
        $audit = AdminAudit::where('action', 'reports.prune')->sole();
        $this->assertSame('pve', $audit->target);
        $this->assertSame(['count' => 2, 'before' => '2026-10-06'], $audit->details);
        $this->get('/reports/'.$ordinaryBefore['id'])->assertNotFound();
        foreach ([$simulation, $atCutoff, $afterCutoff] as $report) {
            $this->get('/reports/'.$report->id)->assertOk();
        }
        $this->post('/admin/reports', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, AdminAudit::where('action', 'reports.prune')->count());
    }

    public function test_invalid_confirmation_or_unauthorized_user_cannot_prune_reports(): void
    {
        [$admin, $ordinary, $simulation] = $this->reports();
        $this->actingAs($admin)->from('/admin')->post('/admin/reports', [...$this->pruneData(), 'confirm' => 'NO'])->assertRedirect('/admin')->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('battle_reports', 2);
        $this->assertDatabaseCount('admin_audits', 0);
        $this->assertDatabaseMissing('operations', ['command' => 'admin.reports']);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/reports', $this->pruneData())->assertForbidden();
        $this->assertDatabaseHas('battle_reports', ['id' => $ordinary->id]);
        $this->assertDatabaseHas('battle_reports', ['id' => $simulation->id]);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_boss_and_pvp_pruning_still_preserve_challenge_history(): void
    {
        [$admin, $ordinary, $simulation] = $this->reports();
        [$pvp, $boss] = $this->otherReports($admin);
        $atCutoff = $pvp->replicate()->fill(['created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00']);
        $atCutoff->save();
        $atCutoff->refresh();
        $this->actingAs($admin);
        foreach (['pvp', 'boss'] as $type) {
            $this->from('/admin')->post('/admin/reports', [...$this->pruneData(), 'type' => $type])->assertRedirect('/admin')->assertSessionHasNoErrors();
        }
        $this->assertNull($pvp->fresh()->report);
        $this->assertSame('challenger_win', $pvp->fresh()->result);
        $this->assertSame($atCutoff->getAttributes(), $atCutoff->fresh()->getAttributes());
        $this->assertSame([], $boss->fresh()->report['events']);
        $this->assertSame(25, $boss->fresh()->damage);
        $this->assertSame(1, $boss->fresh()->generation);
        $this->assertDatabaseCount('ranking_challenges', 2);
        $this->assertDatabaseCount('boss_challenges', 1);
        $this->assertDatabaseHas('battle_reports', ['id' => $ordinary->id]);
        $this->assertDatabaseHas('battle_reports', ['id' => $simulation->id]);
    }

    private function reports(): array
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        $admin = User::factory()->create(['is_admin' => true]);
        $character = app(CharacterFactory::class)->create($admin, 1, 'Hero', 0);
        $this->actingAs($admin);
        $this->post('/hunt/gb0', ['operation_id' => (string) Str::uuid(), 'party' => [$character->id]])->assertRedirect()->assertSessionHasNoErrors();
        $ordinary = BattleReport::where('mode', 'pve')->sole();
        $this->post('/simulation', ['operation_id' => (string) Str::uuid(), 'party' => [$character->id]])->assertRedirect()->assertSessionHasNoErrors();
        $simulation = BattleReport::where('mode', 'simulation')->sole();
        // Only timestamps are aged; both reports come from the real gameplay endpoints.
        foreach ([$ordinary, $simulation] as $report) {
            $report->update(['created_at' => '2026-10-05 23:59:59']);
            $report->refresh();
        }

        return [$admin, $ordinary, $simulation];
    }

    private function otherReports(User $user): array
    {
        $pvp = RankingChallenge::create(['challenger_id' => $user->id, 'defender_id' => null, 'result' => 'challenger_win', 'report' => ['mode' => 'pvp', 'events' => [['type' => 'BattleStarted']]], 'created_at' => '2026-10-05 23:59:59']);
        app(BossService::class)->bootstrap();
        $boss = BossChallenge::create(['boss_instance_id' => BossInstance::firstOrFail()->id, 'user_id' => $user->id, 'generation' => 1, 'damage' => 25, 'killed' => false, 'report' => ['mode' => 'boss', 'events' => [['type' => 'BattleStarted']]], 'created_at' => '2026-10-05 23:59:59']);

        return [$pvp->fresh(), $boss->fresh()];
    }

    private function pruneData(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'type' => 'pve', 'before' => '2026-10-06', 'confirm' => 'DELETE'];
    }
}
