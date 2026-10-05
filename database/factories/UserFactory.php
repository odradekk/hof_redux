<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class UserFactory extends Factory
{
    public function definition(): array
    {
        return ['login' => fake()->unique()->regexify('[a-z]{12}'), 'name' => fake()->unique()->lexify('Team????????'), 'password' => 'valid-password-123', 'stamina_updated_at' => now(), 'preferences' => ['party' => []]];
    }
}
