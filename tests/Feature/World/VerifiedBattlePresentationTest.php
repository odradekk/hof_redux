<?php

declare(strict_types=1);

namespace Tests\Feature\World;

use App\Application\Battle\BattleService;
use App\Application\Support\GameAction;
use App\Application\World\WorldService;
use App\Domain\Content\ContentCatalog;
use App\Models\BattleReport;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedBattlePresentationTest extends TestCase
{
    use RefreshDatabase;

    private function player(): array
    {
        $this->post('/register', ['login' => 'battleproof', 'password' => 'synthetic-password', 'password_confirmation' => 'synthetic-password'])->assertRedirect('/setup')->assertSessionHasNoErrors();
        $this->post('/setup', ['name' => 'Battle proof', 'character_name' => 'Hero', 'base_type' => 1, 'gender' => 0])->assertRedirect('/')->assertSessionHasNoErrors();
        $user = User::where('login', 'battleproof')->sole();
        $this->actingAs($user);

        return [$user, $user->characters()->sole()];
    }

    private function retry(BattleReport $report): BattleReport
    {
        $html = $this->get('/reports/'.$report->id)->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $form = (new DOMXPath($document))->query('//form[button[contains(., "再战一次")]]')->item(0);
        $this->assertNotNull($form);
        $fields = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $fields[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $fields), $data);
        $this->post($form->getAttribute('action'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $next = BattleReport::latest('id')->firstOrFail();
        $this->assertNotSame($report->id, $next->id);

        return $next;
    }

    public function test_tactics_test_and_rendered_retry_keep_ten_actions_without_changing_economy_or_preferences(): void
    {
        [$user, $character] = $this->player();
        $before = [$user->money, $user->stamina_units, $user->preferences, $character->stats];
        $this->get('/characters/'.$character->id)->assertOk()->assertSee('设置 &amp; 测试', false);
        // This selectable condition skips the attack, making the action bound observable.
        $this->post('/player/tactics', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'submit' => 'test', 'tactics' => [['judge' => 1001, 'quantity' => 0, 'action' => 1000]]])->assertRedirect()->assertSessionHasNoErrors();
        $first = BattleReport::latest('id')->firstOrFail();
        $this->assertSame(10, $first->report['actions']);
        $second = $this->retry($first);
        $this->assertSame(10, $second->report['actions']);
        $third = $this->retry($second);
        foreach ([$first, $second, $third] as $report) {
            $this->assertSame('simulation', $report->mode);
            $this->assertFalse($report->public);
            $this->assertSame('action_limit', $report->report['reason']);
            $this->assertSame(10, $report->report['actions']);
            $this->assertSame(10, ($report->report['action_limit'] ?? null));
        }
        $this->post('/characters/'.$character->id.'/simulation', ['operation_id' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(10, BattleReport::latest('id')->firstOrFail()->report['actions']);
        $fresh = $user->fresh();
        $this->assertSame($before, [$fresh->money, $fresh->stamina_units, $fresh->preferences, $character->fresh()->stats]);
        $this->assertDatabaseCount('asset_entries', 0);
    }

    public function test_party_simulation_and_its_rendered_retry_keep_fifty_actions(): void
    {
        [$user, $character] = $this->player();
        $this->post('/player/tactics', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'tactics' => [['judge' => 1001, 'quantity' => 0, 'action' => 1000]]])->assertRedirect()->assertSessionHasNoErrors();
        $before = [$user->money, $user->stamina_units, $user->preferences, $character->fresh()->getAttributes()];
        $this->get('/simulation')->assertOk();
        $this->post('/simulation', ['operation_id' => (string) Str::uuid(), 'party' => [$character->id]])->assertRedirect()->assertSessionHasNoErrors();
        $first = BattleReport::latest('id')->firstOrFail();
        $second = $this->retry($first);
        foreach ([$first, $second] as $report) {
            $this->assertSame('action_limit', $report->report['reason']);
            $this->assertSame(50, $report->report['actions']);
            $this->assertSame(50, ($report->report['action_limit'] ?? null));
        }
        $fresh = $user->fresh();
        $this->assertSame($before, [$fresh->money, $fresh->stamina_units, $fresh->preferences, $character->fresh()->getAttributes()]);
    }

    public function test_simulation_limit_is_saved_even_when_combat_ends_before_the_limit(): void
    {
        [$user, $character] = $this->player();
        $report = app(BattleService::class)->simulateParties($user, [$character->id], $user, [$character->id], 'simulation', 77, 50);
        $this->assertSame('elimination', $report['reason']);
        $this->assertLessThan(50, $report['actions']);
        $this->assertSame(50, ($report['action_limit'] ?? null));
        $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'simulation', 'public' => false, 'report' => $report]);
        $this->assertSame(50, $this->retry($record)->report['action_limit']);
    }

    public function test_older_capped_reports_retain_their_limit_and_ambiguous_reports_do_not_guess(): void
    {
        [$user, $character] = $this->player();
        $this->post('/player/tactics', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'tactics' => [['judge' => 1001, 'quantity' => 0, 'action' => 1000]]])->assertRedirect()->assertSessionHasNoErrors();
        foreach ([10, 50] as $limit) {
            $result = app(WorldService::class)->simulate($user->id, (string) Str::uuid(), [$character->id], false, $limit);
            $record = BattleReport::findOrFail($result['report_id']);
            $report = $record->report;
            unset($report['action_limit']);
            $record->update(['report' => $report]);
            $this->assertSame($limit, $this->retry($record)->report['actions']);
        }
        $this->post('/player/tactics', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'tactics' => [['judge' => 1000, 'quantity' => 0, 'action' => 1000]]])->assertRedirect()->assertSessionHasNoErrors();
        $report = app(BattleService::class)->simulateParties($user, [$character->id], $user, [$character->id], 'simulation', 77, 50);
        $this->assertSame('elimination', $report['reason']);
        unset($report['action_limit']);
        $record = BattleReport::create(['user_id' => $user->id, 'mode' => 'simulation', 'public' => false, 'report' => $report]);
        $this->get('/reports/'.$record->id)->assertOk()->assertDontSee('再战一次');
    }

    public function test_character_test_retry_keeps_ownership_checks_and_disappears_after_dismissal(): void
    {
        [$user] = $this->player();
        $this->post('/player/recruit', ['operation_id' => (string) Str::uuid(), 'base_type' => 1, 'gender' => 0, 'name' => 'Second'])->assertRedirect()->assertSessionHasNoErrors();
        $character = $user->characters()->where('name', 'Second')->sole();
        $this->post('/characters/'.$character->id.'/simulation', ['operation_id' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $record = BattleReport::latest('id')->firstOrFail();
        $this->get('/reports/'.$record->id)->assertOk()->assertSee('action="'.route('character.simulation', $character->id).'"', false);
        $this->post('/player/dismiss', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/reports/'.$record->id)->assertOk()->assertDontSee('再战一次');
        $this->post('/characters/'.$character->id.'/simulation', ['operation_id' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertDatabaseCount('battle_reports', 1);
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/reports/'.$record->id)->assertNotFound();
    }

    public function test_hunted_and_naturally_summoned_bats_save_and_render_a_stable_variant(): void
    {
        [$user, $character] = $this->player();
        $this->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'items' => [['id' => 8000, 'quantity' => 1]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5000, $user->fresh()->money);
        $this->get('/dungeons/ancient_cave')->assertOk();
        $catalog = app(ContentCatalog::class);
        $variants = $catalog->monster('1012', 1, true)['image_variants'];
        foreach ([1192054935 => false, 2040709929 => true] as $seed => $summoned) {
            // Fix only the server seed at the transaction boundary. The registered starter,
            // encounter weights of the cave's bat room, monster actions and summon rules are unmodified.
            $result = app(GameAction::class)->execute($user->id, 'test.seeded_dungeon_battle', (string) Str::uuid(), ['seed' => $seed], function (User $actor, int $operation) use ($seed, $character): array {
                $area = app(ContentCatalog::class)->get('areas', 'ac0');
                $report = app(BattleService::class)->fightDungeon($actor, [$character->id], $area['encounters'], 1, [], $area['land'], $area['name'], $operation, $seed);
                $record = BattleReport::create(['user_id' => $actor->id, 'mode' => 'pve', 'public' => true, 'report' => $report]);

                return ['report_id' => $record->id];
            });
            $record = BattleReport::findOrFail($result['report_id']);
            $report = $record->report;
            $units = $summoned
                ? array_column(array_filter($report['events'], static fn (array $event): bool => $event['type'] === 'Summoned' && $event['prototype'] === 1012), 'unit')
                : array_filter($report['initial_teams'][1], static fn (array $unit): bool => $unit['no'] === '1012');
            $this->assertNotEmpty($units);
            $first = app(BattleService::class)->publicReport($record->fresh()->report)['presentation'];
            $this->assertSame($first, app(BattleService::class)->publicReport($record->fresh()->report)['presentation']);
            $page = $this->get('/reports/'.$record->id)->assertOk();
            $page->assertDontSee('/NoImage.gif', false);
            foreach ($units as $unit) {
                $this->assertContains($unit['img'] ?? null, $variants);
                $this->assertFileExists(public_path('image/char/'.$unit['img']));
                $page->assertSee('/'.$unit['img'], false);
            }
            $this->assertSame($report, $record->fresh()->report);
        }
    }

    public function test_barrier_help_explains_base_damage_and_the_added_piercing_damage(): void
    {
        foreach (['/manual/advanced', '/catalog/rules', '/catalog/skills/3060', '/catalog/skills/5067'] as $url) {
            $this->get($url)->assertOk()->assertSee('基础伤害变为 0')->assertSee('追加的无视防御伤害仍按公式计算')
                ->assertDontSee('下一次受到的攻击伤害变为 0')->assertDontSee('下一次受到的攻击伤害为 0')->assertDontSee('这次攻击伤害为 0');
        }
    }
}
