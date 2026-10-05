<?php

namespace App\Http\Controllers\Game;

use App\Application\Community\CommunityService;
use App\Http\View\UiColors;
use App\Models\Announcement;
use App\Models\BoardMessage;
use Illuminate\Http\Request;

final class CommunityController
{
    public function town()
    {
        $facilities = array_map(static fn (array $facility): array => [
            'label' => $facility['label'],
            'href' => isset($facility['route']) ? route($facility['route']) : null,
            'children' => array_map(static fn (array $child): array => [
                'label' => $child['label'], 'href' => route($child['route']),
            ], $facility['children'] ?? []),
        ], config('hof_ui.town', []));
        $messages = BoardMessage::orderByDesc('id')->limit(50)->get()->map(static fn (BoardMessage $message): array => [
            'who' => $message->author_name, 'text' => $message->body, 'at' => $message->created_at,
            'color' => UiColors::valid($message->author_color) ? $message->author_color : '',
        ])->all();

        return view('game.community.town', [
            'facilities' => $facilities, 'messages' => $messages,
            'announcements' => Announcement::where('published', true)->latest()->limit(5)->get(),
        ]);
    }

    public function post(Request $request, CommunityService $service)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'body' => 'required|string|max:200']);
        $service->post($request->user()->id, $data['operation_id'], $data['body']);

        return redirect()->route('town')->with('status', '留言已发布。');
    }
}
