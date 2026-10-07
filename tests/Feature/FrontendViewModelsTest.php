<?php

namespace Tests\Feature;

use App\Application\Player\ItemDetails;
use App\Application\Player\Vitals;
use App\Domain\Content\ContentCatalog;
use App\Http\View\FormErrors;
use App\Http\View\ItemLines;
use App\Http\View\Times;
use App\Http\View\UiColors;
use App\Http\View\UnitCards;
use App\Models\RankingEntry;
use App\Models\User;
use App\Services\CharacterFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class FrontendViewModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stamina_read_projection_preserves_units_and_never_writes(): void
    {
        $at = CarbonImmutable::parse('2026-10-05T12:00:00Z');
        $character = app(CharacterFactory::class)->create(User::factory()->create(), 1, 'Hero', 0);
        $character->forceFill(['stamina_units' => 100, 'stamina_updated_at' => $at])->save();
        // A warrior has VIT 8: maximum 108 points, regenerating 5 × 108 units per second.
        $this->assertSame(640, Vitals::staminaUnits($character, $at->addSecond(), true));
        $this->assertSame(100, Vitals::staminaUnits($character, $at->subSecond(), true));
        $this->assertSame(108 * 86400, Vitals::staminaUnits($character, $at->addDay(), true));
        // A character inside a dungeon does not rest.
        $this->assertSame(100, Vitals::staminaUnits($character, $at->addDay(), false));
        $this->assertSame(100, $character->fresh()->stamina_units);
        $this->assertSame($at->getTimestamp(), $character->fresh()->stamina_updated_at->getTimestamp());
    }

    public function test_resting_health_recovers_twenty_percent_per_hour_and_caps(): void
    {
        $at = CarbonImmutable::parse('2026-10-05T12:00:00Z');
        $character = app(CharacterFactory::class)->create(User::factory()->create(), 1, 'Hero', 0);
        $character->forceFill(['stats' => ['maxhp' => 1000, 'hp' => 100, 'maxsp' => 37, 'sp' => 0] + $character->stats, 'health_updated_at' => $at])->save();
        $this->assertSame(['hp' => 300, 'sp' => 7], Vitals::health($character, $at->addHour(), true));
        // 179 seconds is one HP short of 10 for a 1000 HP maximum (1000 * 179 / 18000 = 9.94).
        $this->assertSame(['hp' => 109, 'sp' => 0], Vitals::health($character, $at->addSeconds(179), true));
        $this->assertSame(['hp' => 1000, 'sp' => 37], Vitals::health($character, $at->addHours(5), true));
        $this->assertSame(['hp' => 100, 'sp' => 0], Vitals::health($character, $at->addHours(5), false));
        $this->assertSame(['hp' => 100, 'sp' => 0], Vitals::health($character, $at->subHour(), true));
    }

    public function test_array_projections_map_gender_refinement_and_chinese_terms(): void
    {
        $catalog = app(ContentCatalog::class);
        $card = UnitCards::character(['id' => 1, 'name' => '角色', 'level' => 2, 'gender' => 1, 'stat_points' => 3, 'stats' => ['hp' => 10, 'maxhp' => 20, 'sp' => 2, 'maxsp' => 5]], $catalog->get('jobs', 100));
        $this->assertSame('image/char/mon_080r.gif', $card['img']);
        $this->assertTrue($card['star']);
        $this->assertSame('战士', $card['label']);
        $boss = UnitCards::boss(['id' => 1, 'name' => '首领', 'limit' => 10, 'hp' => 12345, 'maxhp' => 99999, 'img' => 'mon_053.gif']);
        $this->assertArrayNotHasKey('vitals', $boss);
        $this->assertStringNotContainsString('12345', json_encode($boss));
        $resolved = app(ItemDetails::class)->resolve(['item_id' => '1000', 'refinement' => 3]);
        $line = ItemLines::fromInventory(['quantity' => 2], $resolved);
        $this->assertSame('短剑', $line['name']);
        $this->assertSame(3, $line['refine']);
        $this->assertSame(2, $line['qty']);
        $this->assertStringContainsString('物理攻击', $line['stats'][0]['text']);
        $line = ItemLines::fromCatalog(['name' => '测试', 'type' => '道具', 'P_MAXHP' => 10, 'M_MAXHP' => 20, 'P_PIERCE' => [3, 4]]);
        $this->assertStringNotContainsString('P_MAXHP', json_encode($line, JSON_UNESCAPED_UNICODE));
        $this->assertSame('最大生命 +20%', $line['stats'][1]['text']);
    }

    public function test_time_format_and_exact_216_color_palette(): void
    {
        CarbonImmutable::setTestNow('2026-10-05T12:00:00Z');
        config(['hof_ui.display_timezone' => 'Asia/Shanghai']);
        $this->assertSame('10-05 20:00', Times::present('2026-10-05T12:00:00Z')['text']);
        $this->assertSame('5小时12分', Times::present('2026-10-05T17:12:00Z', 'relative')['text']);
        $this->assertSame('32秒', Times::present('2026-10-05T12:00:32Z', 'relative')['text']);
        $this->assertCount(216, UiColors::all());
        $this->assertTrue(UiColors::valid('99CC33'));
        $this->assertFalse(UiColors::valid('0369cf'));
        $this->assertFalse(UiColors::valid('"><script>'));
        CarbonImmutable::setTestNow();
    }

    public function test_field_errors_use_input_names_and_link_to_the_real_dom_id(): void
    {
        $errors = new MessageBag(['name' => ['名字不能为空。']]);
        View::share('errors', new ViewErrorBag()->put('default', $errors));
        $html = Blade::render('<x-field label="队伍名" for="team-name"><input class="input" name="name"></x-field>', ['errors' => new ViewErrorBag()->put('default', $errors)]);
        $this->assertStringContainsString('id="team-name"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="team-name-note"', $html);
        $this->assertSame(['name' => 'team-name'], FormErrors::anchors($html));
    }

    public function test_guest_ranking_uses_competition_places_instead_of_slots(): void
    {
        foreach ([1, 2, 3, 4] as $position) {
            $user = User::factory()->create();
            RankingEntry::create(['user_id' => $user->id, 'position' => $position, 'party' => [], 'party_set_at' => now(), 'wins' => 1, 'losses' => 0, 'draws' => 0, 'defenses' => 0]);
        }
        $this->get('/login')->assertOk()->assertViewHas('ranking', fn ($ranking) => array_column($ranking, 'position') === [1, 2, 2, 3]);
    }

    public function test_every_saved_message_color_has_a_readable_backing(): void
    {
        $this->assertSame('light', UiColors::backing('003366'));
        $this->assertSame('dark', UiColors::backing('ccffcc'));
        foreach (UiColors::all() as $color) {
            $channels = array_map(function ($pair) {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split($color, 2));
            $luminance = array_sum(array_map(fn ($value, $weight) => $value * $weight, $channels, [0.2126, 0.7152, 0.0722]));
            $ratio = UiColors::backing($color) === 'dark' ? ($luminance + 0.05) / 0.05 : 1.05 / ($luminance + 0.05);
            $this->assertGreaterThanOrEqual(4.5, $ratio, $color);
        }
    }
}
