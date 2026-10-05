<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Application\Battle\BattlePresenter;
use App\Application\Battle\BattleStage;
use App\Domain\Content\ContentCatalog;
use PHPUnit\Framework\TestCase;

final class BattleFrontendTest extends TestCase
{
    private function catalog(): ContentCatalog
    {
        return new ContentCatalog(dirname(__DIR__, 3).'/content');
    }

    private function unit(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'name' => $id, 'img' => 'mon_052.gif', 'position' => 'front', 'level' => 2, 'hp' => 1000, 'maxhp' => 1000, 'sp' => 100, 'maxsp' => 100, 'state' => 0];
    }

    private function report(array $events, array $extra = []): array
    {
        return $extra + ['initial_teams' => [[$this->unit('ally')], [$this->unit('foe')]], 'names' => ['我方', '对手'], 'events' => $events, 'winner' => 0, 'mode' => 'pve'];
    }

    public function test_legacy_coordinates_for_one_through_five_units_per_row(): void
    {
        $stage = new BattleStage($this->catalog());
        $coordinates = [
            1 => [[140, 76]],
            2 => [[153, 42], [126, 109]],
            3 => [[160, 26], [140, 76], [120, 126]],
            4 => [[164, 16], [148, 56], [132, 96], [116, 136]],
            5 => [[166, 9], [153, 42], [140, 76], [126, 109], [113, 142]],
        ];
        $rightCoordinates = [
            1 => [[300, 76]],
            2 => [[286, 42], [313, 109]],
            3 => [[280, 26], [300, 76], [320, 126]],
            4 => [[276, 16], [292, 56], [308, 96], [324, 136]],
            5 => [[273, 9], [286, 42], [300, 76], [313, 109], [326, 142]],
        ];
        foreach ($coordinates as $count => $expected) {
            $row = array_map(fn (int $i): array => $this->unit('foe:'.$i), range(1, $count));
            $sprites = $stage->present([[], $row])['sprites'];
            self::assertSame($expected, array_map(static fn (array $sprite): array => [$sprite['x'], $sprite['y']], $sprites), 'Count '.$count);
            foreach ($sprites as $sprite) {
                self::assertSame(40, $sprite['width']);
                self::assertSame(48, $sprite['height']);
            }
            $right = $stage->present([$row, []])['sprites'];
            self::assertSame($rightCoordinates[$count], array_map(static fn (array $sprite): array => [$sprite['x'], $sprite['y']], $right));
            $back = array_map(static fn (array $unit): array => array_replace($unit, ['position' => 'back']), $row);
            $leftBack = $stage->present([[], $back])['sprites'];
            self::assertSame(array_map(static fn (array $xy): array => [$xy[0] - 80, $xy[1]], $expected), array_map(static fn (array $sprite): array => [$sprite['x'], $sprite['y']], $leftBack));
            $rightBack = $stage->present([$back, []])['sprites'];
            self::assertSame(array_map(static fn (array $xy): array => [$xy[0] + 80, $xy[1]], $rightCoordinates[$count]), array_map(static fn (array $sprite): array => [$sprite['x'], $sprite['y']], $rightBack));
        }
        $scene = $stage->present([[$this->unit('ally')], [$this->unit('foe', ['position' => 'back'])]]);
        self::assertSame([60, 76], [$scene['sprites'][0]['x'], $scene['sprites'][0]['y']]);
        self::assertSame([300, 76], [$scene['sprites'][1]['x'], $scene['sprites'][1]['y']]);
        self::assertSame('image/char/mon_052.gif', $scene['sprites'][1]['src']);
        self::assertSame('translate(640 0) scale(-1 1)', $scene['sprites'][1]['transform']);
    }

    public function test_stage_hides_retired_summons_uses_tombstones_and_maps_circles(): void
    {
        $scene = (new BattleStage($this->catalog()))->present([[$this->unit('dead', ['state' => 1]), $this->unit('summon', ['state' => 1, 'summon' => true])], []], 'grass', [2, 3]);
        self::assertCount(1, $scene['sprites']);
        self::assertSame('image/char_rev/mon_145.gif', $scene['sprites'][0]['src']);
        self::assertSame('image/other/mc0_2.gif', $scene['circles'][0]['src']);
        self::assertSame(280, $scene['circles'][0]['x']);
        self::assertSame('image/other/mc1_3.gif', $scene['circles'][1]['src']);
        self::assertSame(0, $scene['circles'][1]['x']);
    }

    public function test_reverse_fallback_preserves_geometry_and_rejects_unregistered_paths(): void
    {
        $directory = sys_get_temp_dir().'/hof-stage-'.bin2hex(random_bytes(6));
        mkdir($directory.'/image/char', 0777, true);
        $public = dirname(__DIR__, 3).'/public';
        foreach (['mon_052.gif', 'NoImage.gif'] as $file) {
            copy($public.'/image/char/'.$file, $directory.'/image/char/'.$file);
        }
        try {
            $stage = new BattleStage($this->catalog(), $directory);
            $sprite = $stage->present([[$this->unit('ally')], []])['sprites'][0];
            self::assertSame('image/char/mon_052.gif', $sprite['src']);
            self::assertSame(300, $sprite['x']);
            self::assertSame('translate(640 0) scale(-1 1)', $sprite['transform']);
            $invalid = $stage->present([[$this->unit('ally', ['img' => '../char/mon_052.gif'])], []], '../grass');
            self::assertSame('image/char/NoImage.gif', $invalid['sprites'][0]['src']);
            self::assertSame('image/other/bg_grass.gif', $invalid['background']);
            $unregistered = $stage->present([[$this->unit('ally', ['img' => 'user-upload.gif'])], []]);
            self::assertSame('image/char/NoImage.gif', $unregistered['sprites'][0]['src']);
        } finally {
            foreach (['mon_052.gif', 'NoImage.gif'] as $file) {
                unlink($directory.'/image/char/'.$file);
            }
            rmdir($directory.'/image/char');
            rmdir($directory.'/image');
            rmdir($directory);
        }
    }

    public function test_segments_count_actor_selections_and_snapshot_after_the_previous_ten_actions(): void
    {
        $events = [];
        for ($i = 0; $i < 21; $i++) {
            $events[] = ['type' => 'ActorSelected', 'actor' => 'ally'];
            $events[] = ['type' => 'DamageApplied', 'target' => 'foe', 'resource' => 'hp', 'amount' => 1, 'before' => 1000 - $i, 'after' => 999 - $i];
        }
        $view = (new BattlePresenter($this->catalog()))->present($this->report($events));
        self::assertCount(3, $view['segments']);
        self::assertSame([0, 10, 20], array_column($view['segments'], 'action'));
        self::assertSame([10, 10, 1], array_map(static fn (array $segment): int => count($segment['actions']), $view['segments']));
        self::assertSame(1000, $view['segments'][0]['units']['foe']['front'][0]['hp']);
        self::assertSame(990, $view['segments'][1]['units']['foe']['front'][0]['hp']);
        self::assertSame(980, $view['segments'][2]['units']['foe']['front'][0]['hp']);
        self::assertSame('ally', $view['segments'][0]['actions'][0]['side']);
        self::assertCount(21, $view['lines']);
    }

    public function test_reconstruction_covers_casts_status_positions_appearance_summons_and_max_resource_clamps(): void
    {
        $events = [
            ['type' => 'ActorSelected', 'actor' => 'ally'],
            ['type' => 'CastStarted', 'actor' => 'ally', 'skill' => 1001],
            ['type' => 'StatChanged', 'target' => 'ally', 'stat' => 'MAXHP', 'before' => 1000, 'after' => 400],
            ['type' => 'StatChanged', 'target' => 'ally', 'stat' => 'MAXSP', 'before' => 100, 'after' => 30],
            ['type' => 'StatusChanged', 'target' => 'ally', 'state' => 2],
            ['type' => 'PositionChanged', 'target' => 'ally', 'position' => 'back'],
            ['type' => 'AppearanceChanged', 'target' => 'ally', 'image' => 'mon_110r.gif'],
            ['type' => 'MagicCirclesChanged', 'team' => 0, 'value' => 3],
            ['type' => 'Summoned', 'actor' => 'ally', 'target' => 'summon:1', 'unit' => $this->unit('summon:1', ['summon' => true, 'team' => 0])],
        ];
        for ($i = 0; $i < 10; $i++) {
            $events[] = ['type' => 'ActorSelected', 'actor' => 'foe'];
            $events[] = ['type' => 'ActionSkipped', 'actor' => 'foe'];
        }
        $events[] = ['type' => 'ActorDied', 'actor' => 'summon:1'];
        $events[] = ['type' => 'SkillUsed', 'actor' => 'ally', 'skill' => 1001];
        for ($i = 0; $i < 10; $i++) {
            $events[] = ['type' => 'ActorSelected', 'actor' => 'foe'];
            $events[] = ['type' => 'ActionSkipped', 'actor' => 'foe'];
        }
        $view = (new BattlePresenter($this->catalog()))->present($this->report($events));
        $snapshot = $view['segments'][1];
        $unit = $snapshot['units']['ally']['back'][0];
        self::assertSame([400, 400, 30, 30, 2, '蓄力'], [$unit['hp'], $unit['maxhp'], $unit['sp'], $unit['maxsp'], $unit['state'], $unit['casting']]);
        self::assertSame('summon:1', $snapshot['units']['ally']['front'][0]['id']);
        self::assertSame('image/other/mc0_3.gif', $snapshot['stage']['circles'][0]['src']);
        self::assertContains('image/char_rev/mon_110r.gif', array_column($snapshot['stage']['sprites'], 'src'));
        self::assertSame([], $view['segments'][2]['units']['ally']['front']);
        self::assertNull($view['segments'][2]['units']['ally']['back'][0]['casting']);
        self::assertStringContainsString('陷入沉思结果忘了行动。(无更多行动模式)', implode(' ', array_column($view['lines'], 'text')));
    }

    public function test_boss_resources_are_redacted_before_any_presentation_data_is_returned(): void
    {
        $boss = $this->unit('boss', ['boss' => true, 'hp' => 987654321, 'maxhp' => 987654322, 'sp' => 876543210, 'maxsp' => 876543211]);
        $events = [];
        for ($i = 0; $i < 11; $i++) {
            $events[] = ['type' => 'ActorSelected', 'actor' => 'ally'];
            $events[] = ['type' => 'DamageApplied', 'target' => 'boss', 'resource' => 'hp', 'amount' => 1, 'before' => 987654321 - $i, 'after' => 987654320 - $i];
        }
        $report = $this->report($events, ['mode' => 'boss', 'initial_teams' => [[$this->unit('ally')], [$boss]], 'teams' => [[$this->unit('ally')], [array_replace($boss, ['hp' => 987654310])]], 'damage' => [987654309, 11]]);
        $view = (new BattlePresenter($this->catalog()))->present($report);
        $json = json_encode($view, JSON_THROW_ON_ERROR);
        foreach ([987654321, 987654322, 876543210, 876543211, 987654310, 987654309] as $secret) {
            self::assertStringNotContainsString((string) $secret, $json);
            self::assertStringNotContainsString(number_format($secret), $json);
        }
        self::assertSame('????', $view['header']['foe']['hp']);
        self::assertSame('????', $view['segments'][1]['units']['foe']['front'][0]['sp']);
        self::assertSame('(??? > ???)', $view['lines'][0]['change']);
        self::assertSame('????', $view['result']['summary']['foe']['hp']);
    }

    public function test_reward_drops_stay_with_the_resolved_action_after_a_cast_phase(): void
    {
        $events = [
            ['type' => 'ActorSelected', 'actor' => 'ally'],
            ['type' => 'CastStarted', 'actor' => 'ally', 'skill' => 1001],
            ['type' => 'ActorSelected', 'actor' => 'ally'],
            ['type' => 'SkillUsed', 'actor' => 'ally', 'skill' => 1001],
            ['type' => 'ActorSelected', 'actor' => 'foe'],
            ['type' => 'ActionSkipped', 'actor' => 'foe'],
        ];
        $view = (new BattlePresenter($this->catalog()))->present($this->report($events, ['rewards' => [[['action' => 1, 'items' => ['1000' => 1]]], []]]));
        self::assertCount(1, $view['segments'][0]['actions'][0]['lines']);
        self::assertStringContainsString('获得战利品', $view['segments'][0]['actions'][1]['lines'][0]['text']);
        self::assertNotNull($view['segments'][0]['actions'][1]['lines'][0]['icon']);
        self::assertStringContainsString('陷入沉思', $view['segments'][0]['actions'][2]['lines'][0]['text']);
    }

    public function test_abandoned_area_uses_the_documented_building_background_alias(): void
    {
        $stage = new BattleStage($this->catalog());
        self::assertSame('image/other/bg_build01.gif', $stage->present([[], []], 'aband')['background']);
    }

    public function test_event_registry_covers_literal_and_dynamic_engine_event_emissions(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Domain/Combat/BattleRun.php').file_get_contents(dirname(__DIR__, 3).'/app/Domain/Combat/Effects.php');
        preg_match_all('/\$this->event\(([^,]+)/', $source, $calls);
        $emitted = [];
        foreach ($calls[1] as $argument) {
            preg_match_all('/\'([A-Z][A-Za-z]+)\'/', $argument, $names);
            array_push($emitted, ...$names[1]);
        }
        $emitted = array_unique($emitted);
        self::assertContains('DamageApplied', $emitted);
        self::assertContains('ResourceRecovered', $emitted);
        self::assertContains('ActionSkipped', $emitted);
        self::assertSame([], array_values(array_diff($emitted, array_keys(BattlePresenter::EVENTS), BattlePresenter::HIDDEN)));
    }

    public function test_unknown_events_fail_loudly_instead_of_disappearing(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new BattlePresenter($this->catalog()))->present($this->report([['type' => 'NewUnregisteredEvent']]));
    }
}
