<?php

declare(strict_types=1);

namespace App\Http\View;

use Illuminate\Http\Request;

final class Hud
{
    public static function forRequest(Request $request): array
    {
        $user = $request->user();
        $setup = $user && ($user->name === null || $user->name === '');
        $menu = [];
        foreach (config('hof_ui.menu') as $entry) {
            if ($setup || ($entry['visibility'] === 'guest' && $user) || ($entry['visibility'] !== 'guest' && ! $user) || ($entry['visibility'] === 'admin' && ! $user?->is_admin)) {
                continue;
            }
            $menu[] = ['label' => $entry['label'], 'href' => route($entry['route']), 'active' => $request->routeIs(...$entry['active'])];
        }

        return ['team' => $user?->name, 'money' => (int) ($user?->money ?? 0), 'menu' => $menu, 'isAdmin' => (bool) $user?->is_admin, 'authenticated' => (bool) $user, 'setup' => $setup];
    }
}
