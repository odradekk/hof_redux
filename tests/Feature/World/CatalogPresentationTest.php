<?php

namespace Tests\Feature\World;

use App\Application\Multiplayer\AuctionService;
use App\Application\Player\PlayerRules;
use App\Application\Player\Vitals;
use App\Application\World\GameData;
use App\Application\World\GameText;
use App\Domain\Content\ContentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_KEYS = ['itemtable', 'moneyhold', 'exphold', 'operations', 'multiply_round', 'when_type2', 'P_MAXHP', 'maxhp', 'name_male', 'img_male', 'UnionName', 'LevelLimit', '"target"', '"no"'];

    public function test_every_list_page_renders_named_values_without_engine_keys(): void
    {
        $this->get('/catalog')->assertOk()->assertSee('游戏数据(GameData)');
        foreach (array_keys(config('hof_ui.catalog_tabs')) as $kind) {
            $response = $this->get('/catalog/'.$kind)->assertOk();
            foreach (self::RAW_KEYS as $key) {
                $response->assertDontSee($key, false);
            }
        }
        $this->get('/catalog/nothing')->assertNotFound();
    }

    public function test_every_published_record_has_a_detail_page(): void
    {
        $catalog = app(ContentCatalog::class);
        $records = ['jobs' => $catalog->all('jobs'), 'items' => $catalog->all('items'), 'skills' => $catalog->playableSkills(), 'monsters' => $catalog->playableMonsters()];
        foreach ($records as $kind => $rows) {
            foreach (array_keys($rows) as $id) {
                $response = $this->get('/catalog/'.$kind.'/'.$id);
                $response->assertOk();
                foreach (self::RAW_KEYS as $key) {
                    $response->assertDontSee($key, false);
                }
            }
        }
    }

    public function test_jobs_show_sprites_equipment_growth_class_change_and_skill_tree(): void
    {
        $this->get('/catalog/jobs')->assertOk()->assertSee('战士')->assertSee('皇家卫士')->assertSee('image/char/mon_079.gif', false)
            ->assertSee('可装备')->assertSee('生命系数')->assertSee('技能树');
        $this->get('/catalog/jobs/101')->assertOk()->assertSee('转职条件')->assertSee('等级 20 以上')->assertSee('技能树');
        $this->get('/catalog/jobs/100')->assertOk()->assertSee('雇佣时的初始状态')->assertSee(GameText::money(PlayerRules::RECRUIT_PRICES[1]))
            ->assertSee('/catalog/skills/1003', false)->assertSee('已习得「痛击」');
    }

    public function test_item_pages_show_legacy_stats_recipe_drops_and_enchant_pool(): void
    {
        $this->get('/catalog/items')->assertOk()->assertSee('短剑')->assertSee('image/icon/we_sword026.png', false)->assertSee('物理攻击 10')->assertSee('重量 1');
        $this->get('/catalog/items/1000')->assertOk()->assertSee('制作配方')->assertSee('附魔候选')->assertSee('可装备职业');
        // Goblin 1000 drops item 6000 with weight 1000 of 10000.
        $this->get('/catalog/items/6000')->assertOk()->assertSee('掉落来源')->assertSee('持斧哥布林')->assertSee('10%');
        $this->get('/catalog/items/8000')->assertOk()->assertSee('持有时可以进入地图');
        $this->get('/catalog/items/9000')->assertOk()->assertSee(GameText::money(AuctionService::MEMBERSHIP_PRICE));
    }

    public function test_monster_pages_show_pattern_drop_rates_and_maps(): void
    {
        $this->get('/catalog/monsters/1000')->assertOk()->assertSee('持斧哥布林')->assertSee('行动模式')->assertSee('50%的概率')
            ->assertSee('10%')->assertSee('哥布林(最弱)')->assertSee('land_grass.gif', false);
        // Rare encounters are published but flagged as hidden on the hunt page.
        $this->get('/catalog/monsters/1012')->assertOk()->assertSee('隐藏（稀有）');
    }

    public function test_shared_boss_hp_sp_and_hp_derived_rewards_stay_hidden(): void
    {
        $data = app(GameData::class);
        foreach (['2000', '2004', '2007'] as $id) {
            $monster = $data->monster($id);
            $this->assertNull($monster['hp']);
            $this->assertNull($monster['sp']);
            $this->assertNull($monster['exp']);
            $this->assertNull($monster['money']);
        }
        $this->get('/catalog/monsters/2000')->assertOk()->assertSee('????')->assertSee('龙之军团')->assertSee('等级合计不超过 250')
            ->assertDontSee('30,000')->assertDontSee('30000')->assertDontSee('3,000');
        $this->get('/catalog/monsters')->assertOk()->assertSee('????');
    }

    public function test_reference_only_content_has_no_page_or_link(): void
    {
        foreach (['1079', '1900', '1010', '1011'] as $id) {
            $this->get('/catalog/monsters/'.$id)->assertNotFound();
            $this->get('/catalog/monsters')->assertDontSee('/catalog/monsters/'.$id.'"', false);
            $this->get('/catalog/areas')->assertDontSee('/catalog/monsters/'.$id.'"', false);
        }
        $this->get('/catalog/skills/3113')->assertNotFound();
        $this->get('/catalog/skills')->assertDontSee('/catalog/skills/3113"', false);
    }

    public function test_skill_pages_explain_special_handlers_learners_and_monster_users(): void
    {
        $this->get('/catalog/skills/1022')->assertOk()->assertSee('后卫时威力 4 倍');
        $this->get('/catalog/skills/1001')->assertOk()->assertSee('可以学习的职业')->assertSee('初始技能')->assertSee('学会后可以学习');
        $this->get('/catalog/skills/1017')->assertOk()->assertSee('使用该技能的怪物')->assertSee('持斧哥布林');
        $this->get('/catalog/skills/2400')->assertOk()->assertSee('召唤');
    }

    public function test_every_content_field_is_published_or_explicitly_ignored(): void
    {
        $catalog = app(ContentCatalog::class);
        $skillFields = [...GameText::skillFields(), ...GameText::IGNORED_SKILL];
        foreach ($catalog->playableSkills() as $id => $skill) {
            $this->assertSame([], array_values(array_diff(array_keys($skill), $skillFields)), "Skill $id has unpublished fields");
        }
        $itemFields = [...GameText::itemFields(), ...GameText::IGNORED_ITEM];
        foreach ($catalog->all('items') as $id => $item) {
            $this->assertSame([], array_values(array_diff(array_keys($item), $itemFields)), "Item $id has unpublished fields");
        }
        // Every skill the combat engine special-cases by ID carries a written explanation.
        preg_match_all('/case (\d+):/', file_get_contents(app_path('Domain/Combat/Effects.php')), $cases);
        $missing = array_diff(array_map('intval', $cases[1]), array_keys(GameText::SPECIAL_EFFECTS), [3113]);
        $this->assertSame([], array_values($missing));
    }

    public function test_rules_page_numbers_come_from_game_rules(): void
    {
        $this->get('/catalog/rules')->assertOk()
            ->assertSee(number_format(PlayerRules::experienceRequired(1)))->assertSee(number_format(PlayerRules::experienceRequired(49)))
            ->assertSee(PlayerRules::refineChance(4).'%')->assertSee('每天 '.Vitals::STAMINA_PER_DAY)
            ->assertSee(GameText::money(AuctionService::MEMBERSHIP_PRICE))->assertSee('第 2 阶 2、3 位');
    }

    public function test_search_finds_records_by_name_or_id_and_rejects_arrays(): void
    {
        $this->get('/catalog?q=短剑')->assertOk()->assertSee('/catalog/items/1000', false);
        $this->get('/catalog?q=1000')->assertOk()->assertSee('/catalog/skills/1000', false)->assertSee('/catalog/monsters/1000', false);
        $this->get('/catalog?q=1079')->assertOk()->assertDontSee('/catalog/monsters/1079', false);
        $this->getJson('/catalog?q[]=x')->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
