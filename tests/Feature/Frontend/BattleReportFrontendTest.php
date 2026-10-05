<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Application\Battle\BattlePresenter;
use App\Application\Battle\BattleService;
use App\Application\Multiplayer\BossService;
use App\Application\World\WorldService;
use App\Models\BattleReport;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BattleReportFrontendTest extends TestCase
{
    use RefreshDatabase;

    private function player(): array
    {
        $user = User::factory()->create(['name' => '战报测试队']);

        return [$user, app(CharacterFactory::class)->create($user, 1, '出战者', 0)];
    }

    public function test_real_hunt_report_has_svg_segments_safe_attributes_and_same_party_retry(): void
    {
        [$user, $character] = $this->player();
        $result = app(WorldService::class)->hunt($user->id, (string) Str::uuid(), 'gb0', [$character->id], false);
        $response = $this->actingAs($user)->get(route('reports.show', $result['report_id']))->assertOk()
            ->assertSee('viewBox="0 0 480 200"', false)->assertSee('aria-label="第 1 段战况', false)
            ->assertSee('再战一次')->assertSee('返回狩猎')->assertSee('name="party[]" value="'.$character->id.'"', false)
            ->assertSee('生命：')->assertSee('魔力：')->assertDontSee('battle.css')->assertDontSee(' style="', false)->assertDontSee('<style', false);
        $html = $response->getContent();
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        self::assertStringContainsString('action="'.route('hunt.area', 'gb0').'"', $html);
        $report = BattleReport::findOrFail($result['report_id'])->report;
        $selected = count(array_filter($report['events'], static fn (array $event): bool => $event['type'] === 'ActorSelected'));
        self::assertSame(max(1, (int) ceil($selected / 10)), substr_count($html, 'viewBox="0 0 480 200"'));
    }

    public function test_real_boss_report_never_embeds_initial_segment_or_final_hidden_resources(): void
    {
        [$user, $character] = $this->player();
        $bosses = app(BossService::class);
        $bosses->bootstrap();
        $boss = BossInstance::where('monster_id', '2004')->firstOrFail();
        $definition = $boss->definition;
        $definition['maxhp'] = 987654321;
        $definition['maxsp'] = 876543219;
        $boss->forceFill(['definition' => $definition, 'hp' => 987654319, 'sp' => 876543217])->save();
        $result = $bosses->challenge($user->id, (string) Str::uuid(), $boss->id, [$character->id]);
        $report = BossChallenge::findOrFail($result['challenge_id'])->report;
        $response = $this->actingAs($user)->get(route('reports.boss', $result['challenge_id']))->assertOk()->assertSee('????/????', false)->assertSee('BOSS 战');
        $html = $response->getContent();
        $secrets = [];
        foreach (array_merge($report['initial_teams'][1], $report['teams'][1]) as $unit) {
            if ($unit['boss'] ?? false) {
                foreach (['hp', 'maxhp', 'sp', 'maxsp'] as $resource) {
                    $secrets[] = $unit[$resource];
                }
            }
        }
        foreach ($report['events'] as $event) {
            if (($event['target'] ?? '') === 'boss' && in_array($event['type'], ['DamageApplied', 'ResourceRecovered', 'StatChanged'], true)) {
                $secrets[] = $event['before'];
                $secrets[] = $event['after'];
            }
        }
        foreach (array_unique($secrets) as $secret) {
            // Distinct large resources cannot collide with incidental IDs, dates or sprite dimensions.
            if ($secret > 100000000) {
                self::assertStringNotContainsString((string) $secret, $html);
                self::assertStringNotContainsString(number_format($secret), $html);
            }
        }
        $safe = app(BattleService::class)->publicReport($report);
        self::assertArrayNotHasKey('initial_teams', $safe);
        self::assertSame('????', $safe['presentation']['header']['foe']['hp']);
        self::assertSame('????', $safe['presentation']['result']['summary']['foe']['hp']);
    }

    public function test_long_reports_collapse_only_after_the_first_five_scenes(): void
    {
        [$user, $character] = $this->player();
        $party = app(BattleService::class)->party($user, [$character->id]);
        $foe = $party[0];
        $foe['id'] = 'foe';
        $events = [];
        for ($i = 0; $i < 51; $i++) {
            $events[] = ['type' => 'ActorSelected', 'actor' => $party[0]['id']];
            $events[] = ['type' => 'ActionSkipped', 'actor' => $party[0]['id']];
        }
        $report = ['initial_teams' => [$party, [$foe]], 'teams' => [$party, [$foe]], 'names' => ['出战队', '镜像队'], 'mode' => 'simulation', 'winner' => null, 'events' => $events];
        $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'simulation', 'public' => false, 'report' => $report]);
        $response = $this->actingAs($user)->get(route('reports.show', $record))->assertOk()->assertSee('展开全部战况')->assertSee('无更多行动模式');
        $html = $response->getContent();
        self::assertSame(6, substr_count($html, 'viewBox="0 0 480 200"'));
        self::assertLessThan(strpos($html, '<details class="more">'), strpos($html, 'id="s5"'));
        self::assertGreaterThan(strpos($html, '<details class="more">'), strpos($html, 'id="s6"'));
        self::assertStringContainsString('href="#s6"', $html);
    }

    public function test_records_render_registered_tabs_and_safe_summaries_for_guests(): void
    {
        [$user, $character] = $this->player();
        $result = app(WorldService::class)->hunt($user->id, (string) Str::uuid(), 'gb0', [$character->id], false);
        $this->get('/reports')->assertOk()->assertSee('普通')->assertSee('BOSS')->assertSee('竞技场')->assertSee('战报测试队')->assertSee('平均')->assertDontSee('<svg', false);
        $this->get('/reports?type=boss')->assertOk();
        $this->get('/reports?type=pvp')->assertOk();
        $this->get('/reports?type=private')->assertNotFound();
        $this->get('/reports/'.$result['report_id'])->assertOk()->assertDontSee('再战一次');
        $report = BattleReport::findOrFail($result['report_id']);
        $report->forceFill(['user_id' => null])->save();
        $this->get('/reports/'.$result['report_id'])->assertOk()->assertDontSee('再战一次');
        self::assertArrayNotHasKey('events', app(BattlePresenter::class)->summary($report->report));
    }

    public function test_hunt_index_integrates_safe_boss_cards_and_recent_reports_without_writing(): void
    {
        [$user] = $this->player();
        app(BossService::class)->bootstrap();
        $boss = BossInstance::where('monster_id', '2004')->firstOrFail();
        $boss->forceFill(['hp' => 987654321, 'sp' => 876543219])->save();
        $before = $boss->fresh()->getAttributes();
        $this->actingAs($user)->get('/hunt')->assertOk()->assertSee('普通怪物')->assertSee('BOSS战记录')->assertSee(route('bosses.show', $boss), false)
            ->assertDontSee('987654321', false)->assertDontSee('876543219', false)->assertDontSee('987,654,321', false);
        self::assertSame($before, $boss->fresh()->getAttributes());
    }

    public function test_hunt_area_uses_shared_picker_and_does_not_expose_raw_positions(): void
    {
        [$user, $character] = $this->player();
        $user->forceFill(['preferences' => ['party' => [$character->id]]])->save();
        $this->actingAs($user)->get('/hunt/gb0')->assertOk()->assertSee('队伍')->assertSee('出现敌人')->assertSee('carpet-stage', false)->assertSee('image/other/land_grass.gif', false)->assertSee('checked', false)->assertDontSee(' · front');
        $this->get('/simulation')->assertOk()->assertSee('模拟战')->assertSee('不消耗体力');
    }
}
