<?php

namespace Tests\Feature\World;

use App\Application\Battle\BattleService;
use App\Application\Player\PlayerRules;
use App\Application\World\GameText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_keeps_every_legacy_section_anchor_and_current_numbers(): void
    {
        $response = $this->get('/manual')->assertOk();
        // Anchors from legacy data.manual0.php ("menu" became "menus": the layout already owns id="menu").
        foreach (['content', 'rule', 'menus', 'btl', 'char', 'charstat', 'statup', 'jdg', 'posi', 'equip', 'skill', 'elem', 'state', 'jobchange', 'sacrier', 'ranking', 'cr'] as $anchor) {
            $response->assertSee('id="'.$anchor.'"', false);
        }
        $response->assertSee('累计行动 '.BattleService::ACTION_LIMIT.' 次')->assertSee('image/manual/001.gif', false)->assertSee('image/manual/002.gif', false)
            ->assertSee('image/icon/crown01.png', false)->assertSee('必定保护')->assertSee('皇家卫士');
        foreach ([1000, 1700, 5000] as $item) {
            $response->assertSee('/catalog/items/'.$item, false);
        }
    }

    public function test_advanced_guide_explains_multiple_conditions_probability_and_defense(): void
    {
        $response = $this->get('/manual/advanced')->assertOk();
        foreach (['mj', 'twenty', 'def', 'res', 'order', 'damage', 'summon', 'circle', 'reward'] as $anchor) {
            $response->assertSee('id="'.$anchor.'"', false);
        }
        $response->assertSee('0.7 × 0.3 = 0.21 = 21%')->assertSee('前面是减伤的百分比，后面是直接扣去的值')->assertSee('不满足时，跳到 3');
    }

    public function test_tutorial_follows_legacy_steps_with_current_recruit_prices(): void
    {
        $response = $this->get('/manual/tutorial')->assertOk()->assertSee('image/manual/t001.gif', false)->assertSee('暂且这样试试吧！')
            ->assertSee('/dungeons/goblin_trail', false)->assertSee('初期能雇佣的人物');
        foreach (PlayerRules::RECRUIT_PRICES as $price) {
            $response->assertSee(GameText::money($price));
        }
        $this->get('/manual/unknown')->assertNotFound();
    }

    public function test_layout_footer_links_manual_tutorial_and_game_data(): void
    {
        $this->get('/manual')->assertSee('href="'.route('manual', 'tutorial').'"', false)->assertSee('href="'.route('catalog').'"', false);
    }
}
