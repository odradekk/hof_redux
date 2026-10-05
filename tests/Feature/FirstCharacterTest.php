<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirstCharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_character_is_free_atomic_and_cannot_be_repeated(): void
    {
        $user = User::factory()->create(['name' => null]);
        $data = ['name' => 'New Team', 'character_name' => 'Hero', 'base_type' => 1, 'gender' => 0];
        $this->actingAs($user)->post('/setup', $data)->assertRedirect('/');
        $this->assertDatabaseCount('characters', 1);
        $this->assertDatabaseCount('inventory_items', 3);
        $this->assertSame(10000, $user->fresh()->money);
        $this->post('/setup', $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('characters', 1);
        $this->assertDatabaseCount('inventory_items', 3);
        $this->actingAs($user->fresh())->get('/')->assertOk()->assertSee('Hero');
    }

    public function test_invalid_first_class_does_not_create_team_or_character(): void
    {
        $user = User::factory()->create(['name' => null]);
        $this->actingAs($user)->post('/setup', ['name' => 'Team', 'character_name' => 'Hero', 'base_type' => 4, 'gender' => 0])->assertSessionHasErrors('base_type');
        $this->assertNull($user->fresh()->name);
        $this->assertDatabaseCount('characters', 0);
    }

    public function test_zero_team_name_completes_setup_without_redirect_loop(): void
    {
        $user = User::factory()->create(['name' => null]);
        $this->actingAs($user)->post('/setup', ['name' => '0', 'character_name' => 'Hero', 'base_type' => 1, 'gender' => 0])->assertRedirect('/');
        $this->actingAs($user->fresh())->get('/')->assertOk();
        $this->get('/setup')->assertRedirect('/');
    }
}
