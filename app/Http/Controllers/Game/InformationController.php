<?php

namespace App\Http\Controllers\Game;

use App\Domain\Content\ContentCatalog;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class InformationController
{
    public function manual(string $section = 'basic')
    {
        abort_unless(in_array($section, ['basic', 'advanced', 'tutorial'], true), 404);

        return view('game.information.manual', compact('section'));
    }

    public function updates()
    {
        return view('game.information.updates', ['announcements' => Announcement::where('published', true)->latest()->paginate(20)]);
    }

    public function catalog(Request $request, ContentCatalog $catalog, string $kind = 'jobs')
    {
        abort_unless(in_array($kind, ['jobs', 'items', 'conditions', 'monsters', 'skills', 'enchants'], true), 404);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $query = trim($input['q'] ?? '');
        $records = $catalog->all($kind);
        if ($query !== '') {
            $records = array_filter($records, static fn (array $row, $id): bool => str_contains((string) $id, $query) || mb_stripos(json_encode($row, JSON_UNESCAPED_UNICODE), $query) !== false, ARRAY_FILTER_USE_BOTH);
        }
        $page = (int) ($input['page'] ?? 1);
        $records = new LengthAwarePaginator(array_slice($records, ($page - 1) * 30, 30, true), count($records), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('game.information.catalog', ['kind' => $kind, 'records' => $records, 'query' => $query, 'version' => $catalog->version()]);
    }
}
