<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HomePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_uses_catalog_job_names_gender_sprites_and_stored_vitals(): void
    {
        $user = User::factory()->create();
        $characters = DB::transaction(function () use ($user) {
            return [app(CharacterFactory::class)->create($user, 1, 'Male warrior', 0), app(CharacterFactory::class)->create($user, 2, 'Female sorcerer', 1)];
        });
        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('战士')->assertSee('巫师')
            ->assertSee('image/char/mon_079.gif', false)
            ->assertSee('image/char/mon_018.gif', false)
            ->assertSee('href="'.url('/characters/'.$characters[0]->id).'"', false)
            ->assertSee('HP '.$characters[0]->stats['hp'].' / '.$characters[0]->stats['maxhp'])
            ->assertSee('SP '.$characters[0]->stats['sp'].' / '.$characters[0]->stats['maxsp'])
            ->assertDontSee('Job 100');
    }
}
