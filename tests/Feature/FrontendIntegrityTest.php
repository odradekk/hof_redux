<?php

namespace Tests\Feature;

use App\Application\Player\PlayerRules;
use App\Domain\Content\ContentCatalog;
use App\Models\User;
use App\Services\CharacterFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FrontendIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_styles_use_tokens_layers_and_screen_scoped_layouts(): void
    {
        $css = file_get_contents(public_path('css/hof.css'));
        $withoutRoot = preg_replace('/:root\s*\{[^}]*\}/s', '', $css);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', preg_replace('~/\*.*?\*/~s', '', $withoutRoot));
        $this->assertStringContainsString('@layer reset, base, layout, components, pages, responsive;', $css);
        $this->assertLessThan(40960, strlen($css));
        preg_match_all('/[^\n]*!important[^\n]*/', $css, $important);
        foreach ($important[0] as $line) {
            $this->assertTrue(str_contains($line, '[hidden]') || str_contains($line, 'transition: none'), $line);
        }
        preg_match('/@layer pages \{(.*?)\/\* Breakpoint/s', $css, $pages);
        preg_match_all('/^\s*\.([a-z][a-z0-9-]*)[^{]*\{/m', $pages[1], $roots);
        $views = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))));
        foreach (array_unique($roots[1]) as $root) {
            $used = [];
            foreach ($views as $view) {
                if ($view->isFile() && preg_match('/class=["\'][^"\']*\\b'.preg_quote($root, '/').'\\b[^"\']*["\']/', file_get_contents($view->getPathname()))) {
                    $used[] = $view->getPathname();
                }
            }
            $this->assertLessThanOrEqual(1, count($used), $root.' belongs in the components layer when shared: '.implode(', ', $used));
        }
    }

    public function test_registry_routes_terms_categories_and_content_images_are_complete(): void
    {
        $walk = function (array $rows) use (&$walk): void {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['route'])) {
                    $this->assertTrue(Route::has($row['route']), $row['route']);
                }
                if (isset($row['children'])) {
                    $walk($row['children']);
                }
            }
        };
        foreach (['menu', 'town', 'catalog_tabs', 'manual_tabs', 'report_tabs'] as $registry) {
            $walk(config('hof_ui.'.$registry));
        }
        foreach (PlayerRules::GUARDS as $guard) {
            $this->assertNotSame('hof.guards.'.$guard, __('hof.guards.'.$guard));
        }
        foreach (['weapon', 'shield', 'armor', 'item'] as $slot) {
            $this->assertNotSame('hof.slots.'.$slot, __('hof.slots.'.$slot));
        }
        $catalog = app(ContentCatalog::class);
        $types = array_merge(...array_column(config('hof_ui.item_categories'), 'types'));
        foreach ($catalog->all('items') as $item) {
            $this->assertContains($item['type'], $types);
            foreach (array_keys($item) as $field) {
                $this->assertNotSame('hof.item_fields.'.$field, __('hof.item_fields.'.$field), $field);
            }
        }
        foreach (['jobs', 'items', 'skills', 'monsters'] as $kind) {
            foreach ($catalog->all($kind) as $entry) {
                foreach (['img', 'img_male', 'img_female'] as $field) {
                    if (! isset($entry[$field])) {
                        continue;
                    }
                    $this->assertFileExists(public_path('image/'.(in_array($kind, ['items', 'skills']) ? 'icon/' : 'char/').basename($entry[$field])));
                }
            }
        }
        foreach ($catalog->all('areas') as $area) {
            // The archive explicitly records both abandoned-town images as absent.
            $land = $area['land'] === 'aband' ? 'build01' : $area['land'];
            $this->assertFileExists(public_path('image/other/land_'.$land.'.gif'));
            $this->assertFileExists(public_path('image/other/bg_'.$land.'.gif'));
        }
    }

    public function test_rendered_pages_keep_csp_accessibility_and_responsive_table_contracts(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $character = DB::transaction(fn () => app(CharacterFactory::class)->create($user, 1, '完整检查角色', 0));
        $this->actingAs($user);
        foreach (['/', '/characters', '/characters/'.$character->id, '/inventory', '/shop', '/shop/sell', '/shop/work', '/smithy/refine', '/smithy/create', '/dungeons', '/dungeons/goblin_trail', '/simulation', '/bosses', '/auction', '/ranking', '/town', '/account', '/manual', '/manual/advanced', '/manual/tutorial', '/updates', '/catalog', '/catalog?q=1000', '/catalog/jobs', '/catalog/items', '/catalog/skills', '/catalog/monsters', '/catalog/areas', '/catalog/conditions', '/catalog/enchants', '/catalog/rules', '/catalog/jobs/100', '/catalog/items/1000', '/catalog/skills/1022', '/catalog/monsters/1012', '/catalog/monsters/2004', '/reports', '/admin', '/dev/ui'] as $path) {
            $response = $this->get($path)->assertOk();
            $html = $response->getContent();
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style\b|<script(?![^>]*\bsrc=)/i', $html, $path);
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//main//h1')->length, $path.' must have one page h1');
            foreach ($xpath->query('//img') as $image) {
                $this->assertTrue($image->hasAttribute('alt'), $path);
                $this->assertTrue($image->hasAttribute('width') && $image->hasAttribute('height'), $path.' image dimensions: '.$image->getAttribute('src'));
            }
            foreach ($xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," tbl-stack ")]//td') as $cell) {
                $this->assertTrue($cell->hasAttribute('data-label') || in_array('primary', explode(' ', $cell->getAttribute('class'))), $path.' unlabeled responsive cell');
            }
            foreach ($xpath->query('//nav[@aria-label="分页"]//*[local-name()="svg"]') as $arrow) {
                $this->fail($path.' uses the default giant pagination SVG');
            }
        }
    }

    public function test_styleguide_is_not_registered_for_production(): void
    {
        $this->app->instance('env', 'production');
        Route::setRoutes(new RouteCollection);
        require base_path('routes/web.php');
        Route::getRoutes()->refreshNameLookups();
        $this->assertFalse(Route::has('dev.ui'));
        $this->assertTrue(Route::has('home'));
    }

    public function test_production_build_keeps_translations(): void
    {
        $this->assertStringContainsString('COPY lang lang', file_get_contents(base_path('Dockerfile')));
        $this->assertContains('!lang/**', file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES));
    }
}
