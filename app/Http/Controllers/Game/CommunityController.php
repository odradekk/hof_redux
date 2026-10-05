<?php

namespace App\Http\Controllers\Game;

use App\Application\Community\CommunityService;
use App\Models\Announcement;
use App\Models\BoardMessage;
use Illuminate\Http\Request;

final class CommunityController
{
    public function town()
    {
        return view('game.community.town', ['messages' => BoardMessage::orderByDesc('id')->limit(CommunityService::KEEP_MESSAGES)->get(), 'announcements' => Announcement::where('published', true)->latest()->limit(5)->get()]);
    }

    public function post(Request $request, CommunityService $service)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'body' => 'required|string|max:200']);
        $service->post($request->user()->id, $data['operation_id'], $data['body']);

        return redirect()->route('town')->with('status', 'Message posted.');
    }
}
