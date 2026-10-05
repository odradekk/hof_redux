<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Application\Support\GameAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class Hud
{
    public static function forRequest(Request $request): array
    {
        $user = $request->user();
        $setup = $user && ! $user->name;
        $menu = [];
        foreach (config('hof_ui.menu') as $entry) {
            if ($setup || ($entry['visibility'] === 'guest' && $user) || ($entry['visibility'] !== 'guest' && ! $user) || ($entry['visibility'] === 'admin' && ! $user?->is_admin)) {
                continue;
            }
            $menu[] = ['label' => $entry['label'], 'href' => route($entry['route']), 'active' => $request->routeIs(...$entry['active'])];
        }

        return ['team' => $user?->name, 'money' => (int) ($user?->money ?? 0),
            'stamina' => $user ? intdiv(GameAction::availableStamina($user, CarbonImmutable::now()), GameAction::STAMINA_UNIT) : 0,
            'staminaMax' => GameAction::STAMINA_MAX, 'menu' => $menu, 'isAdmin' => (bool) $user?->is_admin, 'authenticated' => (bool) $user, 'setup' => $setup];
    }
}
