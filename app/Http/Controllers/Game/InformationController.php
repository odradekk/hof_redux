<?php

namespace App\Http\Controllers\Game;

use App\Application\World\CatalogPresenter;
use App\Domain\Content\ContentCatalog;
use App\Http\View\Images;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class InformationController
{
    public function manual(string $section = 'basic')
    {
        abort_unless(in_array($section, ['basic', 'advanced', 'tutorial'], true), 404);

        $tabs = array_map(static fn (string $key, string $label): array => [
            'label' => $label, 'href' => route('manual', $key === 'basic' ? [] : ['section' => $key]), 'active' => $section === $key,
        ], ['basic', 'advanced', 'tutorial'], ['基本说明', '进阶说明', '新手教程']);

        return view('game.information.manual', compact('section', 'tabs'));
    }

    public function updates()
    {
        return view('game.information.updates', ['announcements' => Announcement::where('published', true)->latest()->paginate(20)]);
    }

    public function catalog(Request $request, ContentCatalog $catalog, CatalogPresenter $presenter, string $kind = 'jobs')
    {
        $registry = config('hof_ui.catalog_tabs', []);
        abort_unless(isset($registry[$kind]), 404);
        $tabs = [];
        foreach ($registry as $key => $tab) {
            $tabs[] = ['label' => $tab['label'], 'href' => route($tab['route'], $tab['parameters'] ?? ['kind' => $key]), 'active' => $key === $kind];
        }
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $query = trim($input['q'] ?? '');
        $records = match ($kind) {
            'skills' => $catalog->playableSkills(), 'monsters' => $catalog->playableMonsters(), default => $catalog->all($kind)
        };
        if ($query !== '') {
            $records = array_filter($records, static fn (array $row, $id): bool => str_contains((string) $id, $query) || mb_stripos(json_encode($row, JSON_UNESCAPED_UNICODE), $query) !== false, ARRAY_FILTER_USE_BOTH);
        }
        $page = (int) ($input['page'] ?? 1);
        $records = new LengthAwarePaginator(array_slice($records, ($page - 1) * 30, 30, true), count($records), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        $records->setCollection($records->getCollection()->map(static function (array $record, int|string $id) use ($presenter, $kind): array {
            $card = $presenter->card($kind, $id, $record);
            $card['images'] = array_map(static function (array $image): array {
                [$width, $height] = Images::size($image['path']);

                return $image + compact('width', 'height');
            }, $card['images']);

            return ['id' => (string) $id, ...$card];
        }));

        return view('game.information.catalog', [
            'kind' => $kind, 'label' => $registry[$kind]['label'], 'tabs' => $tabs,
            'records' => $records, 'query' => $query,
        ]);
    }
}
