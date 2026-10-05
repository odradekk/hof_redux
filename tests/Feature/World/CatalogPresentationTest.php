<?php

namespace Tests\Feature\World;

use App\Application\World\CatalogPresenter;
use App\Domain\Content\ContentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_are_readable_cards_with_character_sprites(): void
    {
        $response = $this->get('/catalog/jobs');
        $response->assertOk()->assertSee('战士')->assertSee('皇家卫士')->assertSee('可用装备')->assertSee('生命成长')->assertSee('进阶职业')->assertSee('image/char/mon_079.gif', false)->assertSee('男性 · 战士');
        foreach (['name_male', 'img_male', 'name_female', 'coe', 'equip', 'Content version', '"change"', '"no"'] as $key) {
            $response->assertDontSee($key);
        }
        $this->assertStringNotContainsString('mon_079.gif', strip_tags($response->getContent()));
    }

    public function test_all_catalog_kinds_render_named_values_without_engine_configuration(): void
    {
        foreach (['items' => '物理攻击', 'conditions' => '必定', 'skills' => '消耗', 'monsters' => '使用技能', 'enchants' => '物理攻击'] as $kind => $label) {
            $response = $this->get('/catalog/'.$kind)->assertOk()->assertSee($label);
            foreach (['itemtable', 'moneyhold', 'exphold', 'operations', 'multiply_round', 'when_type2', 'P_MAXHP', 'maxhp', '"target"', '"no"'] as $key) {
                $response->assertDontSee($key);
            }
        }
        $this->get('/catalog/items')->assertSee('短剑')->assertSee('image/icon/we_sword026.png', false)->assertSee('制作材料');
        $this->get('/catalog/skills')->assertSee('攻击')->assertSee('image/icon/skill_042.png', false);
    }

    public function test_incomplete_reference_only_content_is_not_a_playable_catalog_card(): void
    {
        $catalog = app(ContentCatalog::class);
        foreach (['1079', '1900', '1010', '1011'] as $id) {
            $name = $catalog->get('monsters', $id)['name'];
            $this->get('/catalog/monsters?q='.$id)->assertOk()->assertDontSee('<h3>'.$name.'</h3>', false);
        }
        $name = $catalog->get('skills', '3113')['name'];
        $this->get('/catalog/skills?q=3113')->assertOk()->assertDontSee('<h3>'.$name.'</h3>', false);
    }

    public function test_every_catalog_card_can_be_presented_without_raw_arrays(): void
    {
        $catalog = app(ContentCatalog::class);
        $presenter = app(CatalogPresenter::class);
        foreach (['jobs', 'items', 'conditions', 'monsters', 'skills', 'enchants'] as $kind) {
            $records = match ($kind) {
                'monsters' => $catalog->playableMonsters(),'skills' => $catalog->playableSkills(),default => $catalog->all($kind)
            };
            foreach ($records as $id => $record) {
                $card = $presenter->card($kind, $id, $record);
                $this->assertIsString($card['title']);
                $this->assertIsString($card['description']);
                foreach ($card['facts'] as $value) {
                    $this->assertIsString($value);
                }
            }
        }
    }
}
