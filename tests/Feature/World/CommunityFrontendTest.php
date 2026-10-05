<?php

namespace Tests\Feature\World;

use App\Application\Community\CommunityService;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Models\AdminAudit;
use App\Models\Announcement;
use App\Models\AuctionListing;
use App\Models\BoardMessage;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommunityFrontendTest extends TestCase
{
    use RefreshDatabase;

    private function player(array $attributes = []): array
    {
        $user = User::factory()->create($attributes);

        return [$user, app(CharacterFactory::class)->create($user, 1, '测试角色', 0)];
    }

    public function test_town_facilities_are_registry_driven_and_posts_snapshot_valid_colors(): void
    {
        [$user] = $this->player(['name' => '彩色队伍', 'preferences' => ['color' => 'CC6633']]);
        $service = app(CommunityService::class);
        $key = (string) Str::uuid();
        $first = $service->post($user->id, $key, '<script>彩色留言</script>');
        $this->assertSame($first, $service->post($user->id, $key, '<script>彩色留言</script>'));
        $this->assertDatabaseHas('board_messages', ['id' => $first['message_id'], 'author_color' => 'cc6633']);
        $user->update(['preferences' => ['color' => '0066ff']]);
        $this->assertSame('cc6633', BoardMessage::findOrFail($first['message_id'])->author_color);
        config(['hof_ui.town' => [
            ['label' => '新增设施', 'route' => 'auction', 'children' => [['label' => '新增子入口', 'route' => 'ranking']]],
        ]]);
        $this->actingAs($user)->get('/town')->assertOk()->assertSee('新增设施')->assertSee('新增子入口')
            ->assertSee('uc-cc6633', false)->assertSee('&lt;script&gt;彩色留言&lt;/script&gt;', false)
            ->assertDontSee('<script>彩色留言', false)->assertDontSee('town.gif');
        $this->assertDatabaseCount('board_messages', 1);
    }

    public function test_invalid_or_missing_legacy_board_color_is_not_rendered_as_css(): void
    {
        [$user] = $this->player(['preferences' => ['color' => 'bdc8d7']]);
        $service = app(CommunityService::class);
        $invalid = $service->post($user->id, (string) Str::uuid(), '旧颜色');
        $this->assertSame('', BoardMessage::findOrFail($invalid['message_id'])->author_color);
        $user->update(['preferences' => []]);
        $empty = $service->post($user->id, (string) Str::uuid(), '默认颜色');
        $this->assertSame('', BoardMessage::findOrFail($empty['message_id'])->author_color);
        BoardMessage::create(['user_id' => $user->id, 'author_name' => '旧留言', 'body' => '兼容记录']);
        $this->actingAs($user)->get('/town')->assertOk()->assertSee('兼容记录')->assertDontSee('uc-bdc8d7', false);
    }

    public function test_boss_summaries_are_a_read_only_resource_free_projection_and_detail_is_available(): void
    {
        [$user] = $this->player();
        $service = app(BossService::class);
        $service->bootstrap();
        $boss = BossInstance::firstOrFail();
        $definition = $boss->definition;
        $definition['maxhp'] = 987654321;
        $definition['maxsp'] = 876543219;
        $boss->update(['hp' => 765432198, 'sp' => 654321987, 'definition' => $definition]);
        $before = $boss->fresh()->getAttributes();
        $summaries = $service->summaries();
        $this->assertCount(12, $summaries);
        $this->assertSame(['id', 'name', 'limit', 'alive', 'respawns_at', 'img'], array_keys($summaries[0]));
        $this->assertSame($before, $boss->fresh()->getAttributes());
        foreach (['/bosses', '/bosses/'.$boss->id] as $path) {
            $response = $this->actingAs($user)->get($path)->assertOk()->assertSee('共享首领')->assertSee('image/other/land_sea.gif', false);
            foreach ([987654321, 876543219, 765432198, 654321987] as $hidden) {
                $response->assertDontSee((string) $hidden);
            }
        }
        $this->get('/bosses/'.$boss->id)->assertSee(route('bosses.challenge', $boss->id), false)->assertSee('name="party[]"', false);
        $this->get('/bosses/999999')->assertNotFound();
    }

    public function test_boss_cooldown_disables_submit_without_disclosing_the_report(): void
    {
        $this->freezeTime();
        [$user] = $this->player();
        app(BossService::class)->bootstrap();
        $boss = BossInstance::firstOrFail();
        BossChallenge::create([
            'boss_instance_id' => $boss->id, 'user_id' => $user->id, 'generation' => 1,
            'damage' => 2222222, 'killed' => false, 'report' => ['boss_hp' => 3333333, 'boss_sp' => 4444444],
        ]);
        $this->actingAs($user)->get('/bosses/'.$boss->id)->assertOk()->assertSee('下次可挑战')
            ->assertSee('disabled', false)->assertDontSee('2222222')->assertDontSee('3333333')->assertDontSee('4444444');
    }

    public function test_auction_uses_resolved_item_lines_sort_links_and_server_minimum_bid(): void
    {
        [$seller] = $this->player(['name' => '卖家']);
        [$buyer] = $this->player(['name' => '买家']);
        $buyer->inventory()->create(['item_id' => '9000', 'quantity' => 1]);
        $listing = AuctionListing::create([
            'seller_id' => $seller->id, 'item_snapshot' => ['item_id' => '1000', 'quantity' => 2, 'refinement' => 3, 'enchantments' => []],
            'price' => 1200, 'ends_at' => now()->addMinutes(20), 'comment' => '<b>交易说明</b>',
        ]);
        $this->actingAs($buyer)->get('/auction?sort=price')->assertOk()->assertSee('image/char/ori_003.gif', false)
            ->assertSee('短剑')->assertSee('物理攻击')->assertSee('min="'.AuctionService::minimumBid(1200).'"', false)
            ->assertSee('value="1320"', false)->assertSee('&lt;b&gt;交易说明&lt;/b&gt;', false)
            ->assertSee('拍卖纪录(AuctionLog)')->assertDontSee('Listing fee')->assertSee('价格 ▼');
        $this->actingAs($seller)->get('/auction?mine=1&sort=time')->assertOk()
            ->assertSee('mine=1', false)->assertSee('data-id="auction-'.$listing->id.'"', false)
            ->assertDontSee('action="'.route('auction.bid', $listing->id).'"', false);
        $this->get('/auction?sort[]=invalid')->assertOk();
        $this->assertSame(1200, $listing->fresh()->price);
    }

    public function test_ranking_has_two_rank_groups_crowns_records_and_nearby_own_team(): void
    {
        $this->freezeTime();
        $viewer = null;
        for ($position = 1; $position <= 22; $position++) {
            [$user, $character] = $this->player(['name' => '队伍'.$position]);
            RankingEntry::create([
                'user_id' => $user->id, 'position' => $position, 'party' => [$character->id],
                'party_set_at' => now()->subHours(49), 'wins' => 3, 'losses' => 1, 'draws' => 1, 'defenses' => 2,
            ]);
            $viewer = $user;
        }
        $response = $this->actingAs($viewer)->get('/ranking')->assertOk()->assertSee('排行榜(RANKING)')->assertSee('附近排名(Nearly)')
            ->assertSee('crown01.png')->assertSee('crown02.png')->assertSee('crown03.png')
            ->assertSee('(5战 3胜1败 1引 2防 胜率60%)')->assertSee('队伍22')->assertSee('class="bold u"', false)
            ->assertSee('保存队伍')->assertSee('name="party[]"', false)->assertSee('底');
        $response->assertViewHas('rankings', function (array $rankings): bool {
            $top = array_column($rankings[0]['rows'], 'place');
            $nearby = array_column($rankings[1]['rows'], 'place');

            return $top === [1, 2, 3, 4, 5] && $nearby === [5, 6, 7, 8, 9];
        });
    }

    public function test_ranking_history_links_only_reports_that_can_be_opened(): void
    {
        [$challenger] = $this->player(['name' => '挑战方']);
        [$defender] = $this->player(['name' => '防守方']);
        $base = ['challenger_id' => $challenger->id, 'defender_id' => $defender->id];
        $walkover = RankingChallenge::create($base + ['result' => 'defender_no_party', 'report' => null]);
        $pruned = RankingChallenge::create($base + ['result' => 'challenger_win', 'report' => null]);
        $empty = RankingChallenge::create($base + ['result' => 'draw', 'report' => []]);
        $available = RankingChallenge::create($base + [
            'result' => 'defender_win',
            'report' => ['mode' => 'pvp', 'names' => ['挑战方', '防守方'], 'winner' => 1, 'events' => [], 'teams' => [[], []]],
        ]);
        foreach ([false, true] as $authenticated) {
            if ($authenticated) {
                $this->actingAs($challenger);
            }
            $response = $this->get('/ranking')->assertOk()->assertSee('对手无可用队伍')->assertSee('挑战方获胜')->assertSee('平局');
            foreach ([$walkover, $pruned, $empty] as $unavailable) {
                $response->assertDontSee('href="'.route('reports.ranking', $unavailable->id).'"', false);
            }
            $response->assertSee('href="'.route('reports.ranking', $available->id).'"', false);
            $this->get(route('reports.ranking', $available->id))->assertOk();
        }
    }

    public function test_information_tabs_pagination_and_catalog_have_stable_identities(): void
    {
        foreach (['jobs', 'items', 'conditions', 'monsters', 'skills', 'enchants'] as $kind) {
            $this->get('/catalog/'.$kind)->assertOk()->assertSee('data-id="'.$kind.'-', false)
                ->assertSee('aria-current="page"', false)->assertDontSee('<svg', false);
        }
        $this->get('/catalog/unknown')->assertNotFound();
        $this->get('/catalog?q=不存在的资料')->assertOk()->assertSee('没有符合条件的资料');
        foreach (['basic', 'advanced', 'tutorial'] as $section) {
            $this->get('/manual/'.$section)->assertOk()->assertSee('aria-current="page"', false);
        }
        for ($i = 0; $i < 21; $i++) {
            Announcement::create(['title' => '公告'.$i, 'body' => '内容'.$i]);
        }
        $this->get('/updates')->assertOk()->assertSee('datetime=', false)->assertDontSee('<svg', false);
    }

    public function test_admin_forms_keep_confirmation_and_audits_are_readable_and_escaped(): void
    {
        [$admin] = $this->player();
        $admin->forceFill(['is_admin' => true])->save();
        BoardMessage::create(['user_id' => $admin->id, 'author_name' => '作者', 'body' => '待审核留言']);
        Announcement::create(['title' => '待审核公告', 'body' => '内容']);
        AdminAudit::create(['admin_id' => $admin->id, 'action' => 'account.balance', 'target' => (string) $admin->id, 'details' => ['reason' => '<script>unsafe</script>', 'delta' => 5]]);
        $response = $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('总资金')->assertSee('tbl-stack', false)
            ->assertSee('name="confirm"', false)->assertSee('pattern="DELETE"', false)->assertSee('pattern="RUN"', false)
            ->assertSee('<details>', false)->assertSee('<pre>', false)->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)
            ->assertDontSee('<script>unsafe', false);
        $response->assertSee('查看详情')->assertSee('资金修正');
        $this->get('/admin/users/'.$admin->id)->assertOk()->assertSee('测试角色')->assertSee('name="money_delta"', false)
            ->assertSee('name="current_password"', false)->assertSee('name="confirm"', false);
        $this->post('/admin/maintenance', ['confirm' => 'NO'])->assertSessionHasErrors('confirm');
    }

    public function test_scoped_pages_have_semantic_safe_responsive_markup_and_command_tokens(): void
    {
        [$admin] = $this->player();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->inventory()->create(['item_id' => '9000', 'quantity' => 1]);
        app(BossService::class)->bootstrap();
        $boss = BossInstance::firstOrFail();
        foreach (['/town', '/auction', '/ranking', '/bosses', '/bosses/'.$boss->id, '/manual', '/updates', '/catalog/items', '/admin', '/admin/users/'.$admin->id] as $path) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//h1')->length, $path.' must have one page title');
            $this->assertSame(0, $xpath->query('//*[@style] | //style | //script[not(@src)] | //body//link')->length, $path.' must be CSP-safe');
            $this->assertSame(0, $xpath->query('//img[not(@alt)]')->length, $path.' images need alternatives');
            $this->assertSame(0, $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " tbl-stack ")]//td[not(@data-label) and not(contains(concat(" ", normalize-space(@class), " "), " primary "))]')->length, $path.' needs mobile cell labels');
            foreach ($xpath->query('//main//form[@method="post"]') as $form) {
                $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length, $path.' requires CSRF protection');
                $this->assertSame(1, $xpath->query('.//input[@name="operation_id"]', $form)->length, $path.' requires a command token');
            }
        }
    }
}
