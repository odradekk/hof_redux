<?php

namespace App\Http\Controllers;

use App\Domain\Content\ContentCatalog;
use App\Http\View\UnitCards;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetupController
{
    public function show(Request $request, ContentCatalog $catalog)
    {
        if ($request->user()->name !== null && $request->user()->name !== '' && $request->user()->characters()->exists()) {
            return redirect()->route('home');
        }

        $choices = [];
        foreach ([1, 2] as $type) {
            $job = $catalog->get('jobs', $catalog->get('base_characters', $type)['job']);
            $choices[] = ['id' => $type, 'male' => UnitCards::job($job, $type), 'female' => UnitCards::job($job, $type, 1)];
        }

        return view('account.setup', ['choices' => $choices]);
    }

    public function store(Request $request, CharacterFactory $characters)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:16', 'regex:/\A[^\p{C}<>]+\z/u', 'unique:users,name,'.$request->user()->id], 'character_name' => ['required', 'string', 'max:16', 'regex:/\A[^\p{C}<>]+\z/u'], 'base_type' => 'required|integer|in:1,2', 'gender' => 'required|integer|in:0,1']);
        try {
            DB::transaction(function () use ($request, $data, $characters) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if ($user->characters()->exists()) {
                    throw ValidationException::withMessages(['name' => '第一个角色已经创建。']);
                }
                $characters->create($user, (int) $data['base_type'], $data['character_name'], (int) $data['gender']);
                $user->update(['name' => $data['name']]);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['name' => '此队伍名称已被使用。']);
        }

        return redirect()->route('home')->with('status', '冒险开始了！');
    }
}
