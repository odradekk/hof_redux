<?php

namespace Tests\Feature\World;

use App\Application\Battle\BattleService;
use App\Domain\Content\ContentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class BattlePresentationTest extends TestCase
{
    use RefreshDatabase;

    private function report(): array
    {
        return ['mode' => 'simulation', 'reason' => 'action_limit', 'winner' => null, 'content_version' => 'private-content-hash', 'names' => ['先锋', '镜像'], 'teams' => [[['id' => 'player:1', 'name' => '勇士', 'hp' => 100, 'maxhp' => 120, 'sp' => 40, 'maxsp' => 50]], [['id' => 'enemy:1', 'name' => '哥布林', 'hp' => 0, 'maxhp' => 100, 'sp' => 0, 'maxsp' => 0]]], 'events' => [
            ['type' => 'ActorSelected', 'actor' => 'player:1', 'progress' => 80],
            ['type' => 'DelayChanged', 'target' => 'player:1', 'progress' => 80],
            ['type' => 'TargetSelected', 'actor' => 'player:1', 'target' => 'enemy:1', 'skill' => 1000],
            ['type' => 'CastStarted', 'actor' => 'player:1', 'skill' => 1000],
            ['type' => 'SkillUsed', 'actor' => 'player:1', 'skill' => 1000],
            ['type' => 'DamageApplied', 'target' => 'player:1', 'resource' => 'sp', 'amount' => 8, 'requested' => 8, 'before' => 51, 'after' => 43, 'reason' => 'skill_cost'],
            ['type' => 'DamageApplied', 'target' => 'enemy:1', 'resource' => 'hp', 'amount' => 37, 'requested' => 70, 'before' => 100, 'after' => 63, 'reason' => 'attack'],
            ['type' => 'ResourceRecovered', 'target' => 'player:1', 'resource' => 'hp', 'amount' => 12, 'before' => 88, 'after' => 100, 'reason' => 'healing'],
            ['type' => 'StatusChanged', 'target' => 'enemy:1', 'state' => 2],
            ['type' => 'ActorDied', 'actor' => 'enemy:1'],
        ]];
    }

    public function test_main_battle_log_is_named_narrative_without_internal_payloads(): void
    {
        view()->share('errors', new ViewErrorBag);
        $report = $this->report();
        $html = view('game.battle.show', ['report' => app(BattleService::class)->publicReport($report)])->render();
        $name = app(ContentCatalog::class)->get('skills', '1000')['name'];
        $this->assertSame(1, preg_match('/<div class="act-head">(.*?)<\/div>/s', $html, $heading));
        $this->assertSame('勇士'.$name, trim(strip_tags($heading[1])));
        $this->assertStringContainsString('勇士 开始蓄力', $html);
        $this->assertStringContainsString('哥布林 受到 37 点伤害', $html);
        $this->assertStringContainsString('勇士 恢复了 12 点生命', $html);
        $this->assertStringContainsString('哥布林 中毒', $html);
        $this->assertStringContainsString('哥布林 被打倒', $html);
        $this->assertStringContainsString('模拟战', $html);
        $this->assertStringContainsString('达到行动上限', $html);
        foreach (['progress', 'requested', 'before:', 'after:', 'skill_cost', 'TargetSelected', 'DelayChanged', '1000', 'private-content-hash', 'action_limit', 'simulation', 'player:1', 'enemy:1'] as $internal) {
            $this->assertStringNotContainsString($internal, $html);
        }
        $this->assertSame('ActorSelected', $report['events'][0]['type']);
    }

    public function test_boss_narrative_never_contains_hidden_resource_state_and_names_are_escaped(): void
    {
        view()->share('errors', new ViewErrorBag);
        $report = $this->report();
        $report['mode'] = 'boss';
        $report['teams'][1][0] = ['id' => 'enemy:1', 'boss' => true, 'name' => '<script>boss</script>', 'hp' => 987654321, 'maxhp' => 998877665, 'sp' => 665544332, 'maxsp' => 776655443];
        $report['events'][] = ['type' => 'ResourceRecovered', 'target' => 'enemy:1', 'resource' => 'hp', 'amount' => 5, 'before' => 987654316, 'after' => 987654321, 'reason' => 'heal'];
        $safe = app(BattleService::class)->publicReport($report);
        $html = view('game.battle.show', ['report' => $safe])->render();
        foreach (['987654321', '998877665', '665544332', '776655443', '987654316'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($safe));
            $this->assertStringNotContainsString(number_format((int) $hidden), $html);
        }
        $this->assertStringContainsString('&lt;script&gt;boss&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>boss</script>', $html);
        $this->assertStringContainsString('恢复了 5 点生命', $html);
    }
}
