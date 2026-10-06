<?php

namespace Tests\Feature;

use App\Models\BattleReport;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Tests\Feature\Player\PlayerTestCase;

final class PlayerFrontendTest extends PlayerTestCase
{
    public function test_legacy_display_routes_redirect_to_the_split_pages(): void
    {
        $user = $this->player();
        $this->character($user);
        $this->actingAs($user)->get('/crafting')->assertStatus(302)->assertRedirect('/smithy/refine');
        $this->get('/preferences')->assertStatus(302)->assertRedirect('/account');
        foreach (['/shop', '/shop/sell', '/shop/work', '/smithy/refine', '/smithy/create', '/account'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_checked_shop_rows_are_the_only_rows_bought_and_retries_are_safe(): void
    {
        $user = $this->player();
        $this->character($user);
        $before = $user->inventory()->sum('quantity');
        $input = ['operation_id' => (string) Str::uuid(), 'selection_mode' => 'checked', 'form_complete' => '1', 'items' => [
            ['id' => 1700, 'quantity' => 1, 'on' => 0], ['id' => 3000, 'quantity' => 2, 'on' => 1],
        ]];
        $this->actingAs($user)->post('/player/buy', $input)->assertRedirect('/shop')->assertSessionHasNoErrors();
        $this->post('/player/buy', $input)->assertRedirect('/shop')->assertSessionHasNoErrors();
        $this->assertSame($before + 2, $user->inventory()->sum('quantity'));
        $this->assertSame(8000, $user->fresh()->money);
    }

    public function test_removing_shop_marker_cannot_purchase_unchecked_rows(): void
    {
        $user = $this->player();
        $this->character($user);
        $before = $user->inventory()->sum('quantity');
        $this->actingAs($user)->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'form_complete' => '1', 'items' => [
            ['id' => 1700, 'quantity' => 1, 'on' => 0], ['id' => 3000, 'quantity' => 1, 'on' => 0],
        ]])->assertSessionHasErrors('items');
        $this->assertSame($before, $user->inventory()->sum('quantity'));
        $this->assertSame(10000, $user->fresh()->money);
    }

    public function test_checked_form_rejects_missing_or_malformed_selection_and_negative_quantity(): void
    {
        $user = $this->player();
        $this->character($user);
        $this->actingAs($user);
        foreach ([['id' => 1700, 'quantity' => 1], ['id' => 1700, 'quantity' => -1, 'on' => 1], ['id' => 1700, 'quantity' => 1, 'on' => 'yes']] as $row) {
            $this->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'selection_mode' => 'checked', 'form_complete' => '1', 'items' => [$row]])->assertSessionHasErrors();
        }
        $this->assertSame(10000, $user->fresh()->money);
    }

    public function test_sell_uses_only_selected_owned_row_and_server_price(): void
    {
        $user = $this->player();
        $this->character($user);
        $first = $this->item($user, '1000', 3);
        $second = $this->item($user, '3000', 4);
        $this->actingAs($user)->post('/player/sell', ['operation_id' => (string) Str::uuid(), 'selection_mode' => 'checked', 'form_complete' => '1', 'items' => [
            ['id' => $first->id, 'quantity' => 1, 'on' => 0], ['id' => $second->id, 'quantity' => 2, 'on' => 1, 'price' => 900000],
        ]])->assertRedirect('/shop/sell')->assertSessionHasNoErrors();
        $this->assertSame(3, $first->fresh()->quantity);
        $this->assertSame(2, $second->fresh()->quantity);
        $this->assertSame(10400, $user->fresh()->money);
    }

    public function test_sell_limits_selected_rows_instead_of_the_number_of_backpack_stacks(): void
    {
        $user = $this->player();
        $this->character($user);
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $item = $this->item($user, '1000', 2);
            $rows[] = ['id' => $item->id, 'quantity' => 1, 'on' => 1];
        }
        $this->actingAs($user)->get('/shop/sell')->assertOk();
        $this->post('/player/sell', ['operation_id' => (string) Str::uuid(), 'selection_mode' => 'checked', 'form_complete' => '1', 'items' => $rows])->assertSessionHasErrors('items');
        $this->assertSame(202, $user->inventory()->where('location', 'backpack')->sum('quantity'));
        $this->assertSame(10000, $user->fresh()->money);

        foreach ($rows as $index => &$row) {
            $row['on'] = $index === 100 ? 1 : 0;
        }
        unset($row);
        $this->post('/player/sell', ['operation_id' => (string) Str::uuid(), 'selection_mode' => 'checked', 'form_complete' => '1', 'items' => $rows])->assertRedirect('/shop/sell')->assertSessionHasNoErrors();
        $this->assertSame(201, $user->inventory()->where('location', 'backpack')->sum('quantity'));
        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame(10100, $user->fresh()->money);
    }

    public function test_tactics_buttons_share_one_form_and_ignore_unsaved_extra_fields_for_row_commands(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $response = $this->actingAs($user)->get('/characters/'.$character->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//form[@id="c-ai"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="c-ai"]//button[contains(@formaction, "tactics-insert")]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="c-ai"]//button[contains(@formaction, "tactics-delete")]')->length);
        $this->assertSame(2, $xpath->query('//form[@id="c-ai"]//input[@name="row" and @type="radio"]')->length);
        $response->assertSeeInOrder(['id="c-status"', 'id="c-ai"', 'id="c-pos"', 'id="c-equip"', 'id="c-skill"', 'id="c-other"'], false);
        $before = $character->tactics;
        $this->post('/player/tactics-insert', ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'row' => 0, 'tactics' => [['judge' => 'invalid', 'quantity' => -1, 'action' => 'invalid']]])->assertSessionHasNoErrors();
        $this->assertSame(['judge' => 1000, 'quantity' => 0, 'action' => 1000], $character->fresh()->tactics[0]);
        $this->assertSame($before[0], $character->fresh()->tactics[1]);
    }

    public function test_save_and_test_saves_submitted_tactics_before_creating_one_retry_safe_report(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $tactics = [['judge' => 1000, 'quantity' => 0, 'action' => 1000]];
        $input = ['operation_id' => (string) Str::uuid(), 'character_id' => $character->id, 'submit' => 'test', 'tactics' => $tactics];
        $this->actingAs($user)->post('/player/tactics', $input)->assertRedirect('/reports/1')->assertSessionHasNoErrors();
        $this->post('/player/tactics', $input)->assertRedirect('/reports/1')->assertSessionHasNoErrors();
        $this->assertSame($tactics, $character->fresh()->tactics);
        $this->assertSame(1, BattleReport::count());
        $this->assertSame('simulation', BattleReport::first()->mode);
        $this->assertFalse(BattleReport::first()->public);
        $this->assertSame(10000, $user->fresh()->money);
    }

    public function test_inventory_uses_registered_categories_and_default_expansion(): void
    {
        $user = $this->player();
        $this->character($user);
        $weapon = $this->item($user, '1000', 1, ['enchantments' => ['100']]);
        $armor = $this->item($user, '5000');
        $this->actingAs($user)->get('/inventory?category=weapon')->assertOk()
            ->assertSee('data-item-id="'.$weapon->id.'"', false)->assertDontSee('data-item-id="'.$armor->id.'"', false);
        $this->get('/inventory')->assertOk()->assertSeeInOrder(['id="inventory-weapon"', 'id="inventory-armor"', 'id="inventory-item"', 'id="inventory-other"'], false);
        $response = $this->get('/inventory');
        $this->assertSame(0, $this->xpath($response->getContent())->query('//details[@open]')->length);
        $user->forceFill(['preferences' => ['no_js_inventory' => true]])->save();
        $response = $this->get('/inventory');
        $this->assertSame(1, $this->xpath($response->getContent())->query('//details[@open]')->length);
        $this->get('/inventory?category=unknown')->assertSessionHasErrors('category');
    }

    public function test_settings_are_unified_with_palette_and_invalid_historical_color_falls_back(): void
    {
        $user = $this->player();
        $this->character($user);
        $user->forceFill(['preferences' => ['color' => 'bdc8d7', 'inventory_javascript' => false]])->save();
        $response = $this->actingAs($user)->get('/account')->assertOk();
        $response->assertSeeInOrder(['显示设置', '队伍改名', '修改密码', '删除账号']);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(217, $xpath->query('//input[@name="color"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="color" and @value="" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="no_js_inventory" and @type="checkbox" and @checked]')->length);
        foreach (['abcdef', '03c9f0'] as $invalidColor) {
            $this->post('/player/preferences', ['operation_id' => (string) Str::uuid(), 'record_battle_log' => 1, 'no_js_inventory' => 0, 'color' => $invalidColor])->assertSessionHasErrors('color');
        }
        $this->post('/player/preferences', ['operation_id' => (string) Str::uuid(), 'record_battle_log' => 1, 'no_js_inventory' => 1, 'color' => ''])->assertRedirect('/account')->assertSessionHasNoErrors();
        $this->assertSame('', $user->fresh()->preferences['color']);
        $this->assertTrue($user->fresh()->preferences['no_js_inventory']);
    }

    public function test_work_button_respects_regeneration_without_mutating_on_get(): void
    {
        $this->freezeTime();
        $user = $this->player();
        $this->character($user);
        $user->forceFill(['stamina_units' => 0])->save();
        $this->actingAs($user);
        $this->travel(17279)->seconds();
        $response = $this->get('/shop/work')->assertOk();
        $this->assertSame(1, $this->xpath($response->getContent())->query('//form[contains(@action,"/player/work")]//button[@disabled]')->length);
        $this->assertSame(0, $user->fresh()->stamina_units);
        $this->travel(1)->seconds();
        $response = $this->get('/shop/work')->assertOk();
        $this->assertSame(0, $this->xpath($response->getContent())->query('//form[contains(@action,"/player/work")]//button[@disabled]')->length);
        $this->assertSame(0, $user->fresh()->stamina_units);
    }

    public function test_player_pages_have_no_inline_code_and_stack_tables_have_mobile_labels(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $this->item($user, '1000');
        $this->actingAs($user);
        foreach (['/characters', '/characters/'.$character->id, '/inventory', '/shop', '/shop/sell', '/shop/work', '/smithy/refine', '/smithy/create', '/account'] as $path) {
            $response = $this->get($path)->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(0, $xpath->query('//*[@style] | //style | //script[not(@src)]')->length, $path);
            $this->assertSame(0, $xpath->query('//img[not(@alt)]')->length, $path);
            $this->assertSame(0, $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," tbl-stack ")]//td[not(@data-label) and not(contains(concat(" ",normalize-space(@class)," ")," primary "))]')->length, $path);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
