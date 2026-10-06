<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ShopFormCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_legally_purchased_backpack_has_complete_bounded_no_javascript_forms(): void
    {
        $user = $this->registerPlayer();
        for ($i = 0; $i < 334; $i++) {
            $this->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'items' => [['id' => 7500, 'quantity' => 1]]])
                ->assertRedirect('/shop')->assertSessionHasNoErrors();
        }
        $this->assertSame(334, $user->inventory()->where('location', 'backpack')->count());
        $ids = [];
        for ($page = 1; $page <= 4; $page++) {
            $response = $this->get('/shop/sell?page='.$page)->assertOk();
            $pairs = $this->formFields($response->getContent(), '/player/sell', true);
            $this->assertLessThan(1000, count($pairs));
            $parsed = $this->parseWithPhp($pairs);
            $this->assertSame('1', $parsed['form_complete'] ?? null);
            $this->assertCount($page === 4 ? 34 : 100, $parsed['items']);
            $ids = [...$ids, ...array_column($parsed['items'], 'id')];
            if ($page < 4) {
                $response->assertSee('page='.($page + 1), false);
            }
        }
        $this->assertSame($user->inventory()->where('location', 'backpack')->orderBy('item_id')->orderBy('id')->pluck('id')->map(strval(...))->all(), $ids);
        $this->post('/player/sell', $parsed)->assertRedirect('/shop/sell')->assertSessionHasNoErrors();
        $this->assertSame(300, $user->inventory()->where('location', 'backpack')->count());
        $this->assertSame(10000, $user->fresh()->money);
        $this->post('/player/sell', $parsed)->assertRedirect('/shop/sell')->assertSessionHasNoErrors();
        $this->assertSame(300, $user->inventory()->where('location', 'backpack')->count());
    }

    public function test_php_truncated_checked_form_cannot_commit_a_partial_sale(): void
    {
        $user = $this->registerPlayer();
        foreach ([1200, 1200, 8009] as $item) {
            $this->post('/player/buy', ['operation_id' => (string) Str::uuid(), 'items' => [['id' => $item, 'quantity' => 1]]])->assertSessionHasNoErrors();
        }
        $response = $this->get('/shop/sell')->assertOk();
        $fields = $this->formFields($response->getContent(), '/player/sell', true);
        // An incomplete HTTP body can end exactly between complete item rows.
        $lastItem = array_key_last(array_filter($fields, fn ($field) => preg_match('/^items\[2\]/', $field[0]) === 1));
        $firstItemOfLastRow = array_key_first(array_filter($fields, fn ($field) => preg_match('/^items\[2\]/', $field[0]) === 1));
        $this->assertNotNull($lastItem);
        $truncated = $this->parseWithPhp($fields, $firstItemOfLastRow);
        $this->assertCount(2, $truncated['items']);
        $this->post('/player/sell', $truncated)->assertSessionHasErrors('form_complete');
        $this->assertSame(3, $user->inventory()->where('location', 'backpack')->count());
        $this->assertSame(7500, $user->fresh()->money);
        $this->assertDatabaseMissing('operations', ['command' => 'player.sell']);

        $complete = $this->parseWithPhp($fields);
        $this->post('/player/sell', $complete)->assertRedirect('/shop/sell')->assertSessionHasNoErrors();
        $this->assertSame(0, $user->inventory()->where('location', 'backpack')->count());
        $this->assertSame(8000, $user->fresh()->money);
    }

    public function test_truncated_buy_form_and_unchecked_complete_form_do_not_change_assets(): void
    {
        $user = $this->registerPlayer();
        $response = $this->get('/shop')->assertOk();
        $fields = $this->formFields($response->getContent(), '/player/buy');
        $complete = $this->parseWithPhp($fields);
        $this->assertSame('1', $complete['form_complete'] ?? null);
        $this->post('/player/buy', $complete)->assertSessionHasErrors('items');
        $complete['items'][0]['on'] = '1';
        unset($complete['form_complete']);
        $this->post('/player/buy', $complete)->assertSessionHasErrors('form_complete');
        $this->assertSame(10000, $user->fresh()->money);
        $this->assertSame(0, $user->inventory()->where('location', 'backpack')->count());
    }

    private function registerPlayer(): User
    {
        $this->post('/register', ['login' => 'shopcomplete', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'])->assertRedirect('/setup');
        $this->post('/setup', ['name' => 'Complete forms', 'character_name' => 'Hero', 'base_type' => 1, 'gender' => 0])->assertRedirect('/');

        $user = User::where('login', 'shopcomplete')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function formFields(string $html, string $action, bool $checked = false): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $fields = [];
        foreach ($xpath->query('//form[contains(@action,"'.$action.'")]//input[@name and not(@disabled)]') as $input) {
            if ($input->getAttribute('type') === 'checkbox' && ! $checked) {
                continue;
            }
            $fields[] = [$input->getAttribute('name'), $input->getAttribute('value')];
        }

        return $fields;
    }

    private function parseWithPhp(array $fields, int $limit = 1000): array
    {
        $body = implode('&', array_map(fn ($field) => urlencode($field[0]).'='.urlencode($field[1]), $fields));
        $process = new Process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'max_input_vars='.$limit, '-r', 'parse_str(stream_get_contents(STDIN), $input); echo json_encode($input, JSON_THROW_ON_ERROR);']);
        $process->setInput($body)->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
