<?php

namespace App\Services;

use App\Application\Player\Vitals;
use App\Domain\Character\Attributes;
use App\Domain\Content\ContentCatalog;
use App\Models\Character;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CharacterFactory
{
    public function __construct(private ContentCatalog $catalog) {}

    /** Caller owns the transaction and the user row lock. Recruitment charges are caller-owned. */
    public function create(User $lockedUser, int $baseType, string $name, int $gender): Character
    {
        if (! in_array($baseType, [1, 2, 3, 4], true) || ! in_array($gender, [0, 1], true)) {
            throw ValidationException::withMessages(['character' => 'Invalid base character.']);
        }
        if ($lockedUser->characters()->count() >= 5) {
            throw ValidationException::withMessages(['character' => 'A team can contain at most five characters.']);
        }
        $base = $this->catalog->get('base_characters', $baseType);
        $job = $this->catalog->get('jobs', $base['job']);
        $stats = Attributes::starting($base, $baseType);
        $stats['maxhp'] = $stats['hp'] = Attributes::maxHp((float) $job['coe'][0], 1, $stats['vit']);
        $stats['maxsp'] = $stats['sp'] = Attributes::maxSp((float) $job['coe'][1], 1, $stats['int']);
        $parts = array_map(fn ($part) => explode('<>', $part), explode('|', $base['Pattern']));
        $tactics = [];
        foreach ($parts[0] as $i => $judge) {
            $tactics[] = ['judge' => (int) $judge, 'quantity' => (int) $parts[1][$i], 'action' => (int) $parts[2][$i]];
        }
        $character = $lockedUser->characters()->create(['name' => $name, 'level' => 1, 'gender' => $gender, 'base_type' => $baseType, 'job_id' => (string) $base['job'], 'stats' => $stats, 'skills' => array_map('intval', $base['skill']), 'tactics' => $tactics, 'position' => $base['position'], 'guard_policy' => ['mode' => $base['guard']], 'stamina_units' => Attributes::staminaMax($stats['vit']) * Vitals::STAMINA_UNIT, 'stamina_updated_at' => now(), 'health_updated_at' => now()]);
        foreach (['weapon', 'shield', 'armor', 'item'] as $slot) {
            if (! empty($base[$slot])) {
                $lockedUser->inventory()->create(['item_id' => (string) $base[$slot], 'quantity' => 1, 'location' => 'equipped', 'character_id' => $character->id, 'slot' => $slot]);
            }
        }

        return $character;
    }
}
