<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\ContentCatalog;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentCatalogTest extends TestCase
{
    private function catalog(): ContentCatalog
    {
        return new ContentCatalog(dirname(__DIR__, 3).'/content');
    }

    public function test_entire_catalog_has_expected_counts_and_verified_fingerprint(): void
    {
        $catalog = $this->catalog();
        $catalog->verifyIntegrity();
        // 181 extracted items plus 9 authored consumables; dungeons are authored only.
        foreach (['items' => 190, 'dungeons' => 3, 'skills' => 268, 'jobs' => 15, 'monsters' => 147, 'base_characters' => 4, 'areas' => 24, 'conditions' => 117, 'class_changes' => 11, 'recipes' => 90, 'enchants' => 134, 'enchant_pools' => 12, 'economy_rules' => 3, 'skill_tree' => 163] as $kind => $count) {
            self::assertCount($count, $catalog->all($kind));
        }
    }

    public function test_authored_layer_is_versioned_and_cannot_shadow_extracted_ids(): void
    {
        $root = dirname(__DIR__, 3).'/content';
        $copy = sys_get_temp_dir().'/hof-content-'.bin2hex(random_bytes(4));
        mkdir($copy.'/redux', 0777, true);
        try {
            foreach (glob($root.'/*.json') as $file) {
                copy($file, $copy.'/'.basename($file));
            }
            foreach (glob($root.'/redux/*.json') as $file) {
                copy($file, $copy.'/redux/'.basename($file));
            }
            $catalog = new ContentCatalog($copy);
            $catalog->verifyIntegrity();
            self::assertNotSame(json_decode(file_get_contents($root.'/manifest.json'), true)['content_version'], $catalog->version());

            // Editing authored content without rebuilding its manifest is detected.
            $items = json_decode(file_get_contents($copy.'/redux/items.json'), true);
            $items['4000']['data']['buy'] = 1;
            file_put_contents($copy.'/redux/items.json', json_encode($items));
            try {
                (new ContentCatalog($copy))->verifyIntegrity();
                self::fail('Tampered authored content passed verification.');
            } catch (\RuntimeException $e) {
                self::assertSame('Content version mismatch', $e->getMessage());
            }

            // An authored record may not reuse an extracted ID.
            $items['1000'] = ['id' => '1000', 'data' => ['name' => 'Shadow'], 'source' => ['authored' => true]];
            file_put_contents($copy.'/redux/items.json', json_encode($items));
            $this->expectExceptionMessage('Authored content reuses an extracted ID: items/1000');
            (new ContentCatalog($copy))->get('items', 1000);
        } finally {
            array_map('unlink', [...glob($copy.'/redux/*'), ...glob($copy.'/*.json')]);
            rmdir($copy.'/redux');
            rmdir($copy);
        }
    }

    public function test_shop_stock_appends_authored_items_marked_for_sale(): void
    {
        $stock = $this->catalog()->shopStock();
        self::assertSame(['1002', '1003'], array_slice($stock, 0, 2));
        self::assertSame(['4000', '4001', '4002', '4100', '4101', '4102', '4200', '4201', '4202'], array_slice($stock, -9));
        self::assertNotContains('1000', $stock);
    }

    public function test_unicode_and_source_provenance_survive_extraction(): void
    {
        $row = $this->catalog()->record('monsters', 1000);
        self::assertSame('持斧哥布林', $row['data']['name']);
        self::assertSame('data/data.monster.php', $row['source']['path']);
        self::assertSame(50, $row['source']['line']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['source']['sha256']);
    }

    public function test_legacy_effects_are_preserved_as_data_and_passive_repairs_are_explicit(): void
    {
        $catalog = $this->catalog();
        self::assertSame(['enemy', 'individual', 1], $catalog->get('skills', 1000)['target']);
        self::assertSame('100', $catalog->get('skills', 1000)['pow']);
        self::assertSame('30', $catalog->get('skills', 7000)['P_MAXHP']);
        self::assertSame('80', $catalog->get('skills', 7001)['P_MAXHP']);
        self::assertNotEmpty($catalog->record('skills', 7000)['adjustments']);
    }

    public function test_all_crafting_and_enchantment_references_resolve(): void
    {
        $catalog = $this->catalog();
        foreach ($catalog->all('recipes') as $recipe) {
            self::assertTrue($catalog->has('items', $recipe['item']));
            foreach ($recipe['ingredients'] as $id => $quantity) {
                self::assertTrue($catalog->has('items', $id));
                self::assertGreaterThan(0, (int) $quantity);
            }
        }
        foreach ($catalog->all('enchant_pools') as $pool) {
            foreach (array_merge($pool['low'], $pool['high']) as $id) {
                self::assertTrue($catalog->has('enchants', $id));
            }
        }
    }

    public function test_all_combat_references_resolve(): void
    {
        $catalog = $this->catalog();
        foreach ($catalog->all('monsters') as $monster) {
            foreach ($monster['action'] ?? [] as $id) {
                self::assertTrue($catalog->has('skills', $id));
            }
            foreach ($monster['judge'] as $id) {
                self::assertTrue($catalog->has('conditions', $id));
            }
        }
        foreach ($catalog->all('skills') as $skill) {
            foreach ((array) ($skill['summon'] ?? []) as $id) {
                self::assertTrue($catalog->has('monsters', $id));
            }
        }
    }

    public function test_skill_tree_and_class_change_boundaries(): void
    {
        $catalog = $this->catalog();
        self::assertSame(['1003', '1013', '3110', '3120'], $catalog->availableSkills(100, 1, [1000, 1001]));
        self::assertNotContains('1003', $catalog->availableSkills(100, 1, [1000]));
        self::assertNotContains('1003', $catalog->availableSkills(100, 1, [1000, 1001, 1003]));
        self::assertFalse($catalog->canChangeJob(100, 101, 19));
        self::assertTrue($catalog->canChangeJob(100, 101, 20));
        self::assertFalse($catalog->canChangeJob(200, 101, 50));
    }

    public function test_boss_scaling_and_hour_sensitive_cycle(): void
    {
        $catalog = $this->catalog();
        $boss = $catalog->monster(2000, 3, true);
        self::assertSame(31200, $boss['maxhp']);
        self::assertSame(15600, $boss['exphold']);
        self::assertSame(10400, $boss['moneyhold']);
        self::assertSame(7200, $catalog->bossCycle(2004, new DateTimeImmutable('2026-10-05 07:59:59 UTC')));
        self::assertSame(259200, $catalog->bossCycle(2004, new DateTimeImmutable('2026-10-05 08:00:00 UTC')));
        self::assertSame(7200, $catalog->bossCycle(2007, new DateTimeImmutable('2026-10-05 19:00:00 UTC')));
        self::assertSame(259200, $catalog->bossCycle(2000, new DateTimeImmutable('2026-10-05 19:00:00 UTC')));
    }

    public function test_timed_map_is_available_only_inside_utc_window(): void
    {
        $catalog = $this->catalog();
        self::assertArrayNotHasKey('horh', $catalog->availableAreas([], new DateTimeImmutable('2026-10-05 02:49:59 UTC')));
        self::assertArrayHasKey('horh', $catalog->availableAreas([], new DateTimeImmutable('2026-10-05 02:50:00 UTC')));
        self::assertArrayNotHasKey('horh', $catalog->availableAreas([], new DateTimeImmutable('2026-10-05 03:00:00 UTC')));
        self::assertArrayHasKey('ac0', $catalog->availableAreas(['8000' => 1], new DateTimeImmutable('2026-10-05 UTC')));
        self::assertArrayNotHasKey('blow01', $catalog->availableAreas([], new DateTimeImmutable('2026-10-05 UTC')));
    }

    public function test_unknown_content_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->get('../legacy', 1000);
    }

    public function test_preview_only_monster_cannot_be_instantiated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->monster(1010, 0, true);
    }

    public function test_incomplete_unreferenced_monsters_are_not_playable(): void
    {
        $catalog = $this->catalog();
        self::assertCount(143, $catalog->playableMonsters());
        foreach ([1010, 1011, 1079, 1900] as $id) {
            self::assertArrayNotHasKey($id, $catalog->playableMonsters());
        }
        self::assertSame('', $catalog->get('monsters', 1079)['maxhp']);
        self::assertSame('', $catalog->get('monsters', 1900)['maxhp']);
        $this->expectException(InvalidArgumentException::class);
        $catalog->monster(1079, 0, true);
    }

    public function test_guard_policy_typo_is_corrected_with_provenance(): void
    {
        $catalog = $this->catalog();
        $record = $catalog->record('monsters', 1055);
        self::assertSame('prob50', $record['data']['guard']);
        self::assertSame('pro50', $record['adjustments'][0]['before']);
        foreach ($catalog->playableMonsters() as $monster) {
            self::assertContains($monster['guard'], ['always', 'never', 'life25', 'life50', 'life75', 'prob25', 'prob50', 'prob75']);
        }
    }

    public function test_shop_contains_only_real_items_and_approved_reset_access(): void
    {
        $catalog = $this->catalog();
        $stock = $catalog->get('economy_rules', 'shop')['values'];
        foreach ($stock as $id) {
            self::assertTrue($catalog->has('items', $id));
        }
        self::assertNotContains(8012, $stock);
        foreach ([7510, 7511, 7512, 7513, 7520] as $id) {
            self::assertContains($id, $stock);
        }
    }
}
