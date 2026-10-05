<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetupController
{
    public function show(Request $request)
    {
        if ($request->user()->name !== null && $request->user()->name !== '' && $request->user()->characters()->exists()) {
            return redirect()->route('home');
        }

        return view('account.setup');
    }

    public function store(Request $request, CharacterFactory $characters)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:16', 'regex:/\A[^\p{C}<>]+\z/u', 'unique:users,name,'.$request->user()->id], 'character_name' => ['required', 'string', 'max:16', 'regex:/\A[^\p{C}<>]+\z/u'], 'base_type' => 'required|integer|in:1,2', 'gender' => 'required|integer|in:0,1']);
        try {
            DB::transaction(function () use ($request, $data, $characters) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if ($user->characters()->exists()) {
                    throw ValidationException::withMessages(['name' => 'Your first character has already been created.']);
                }
                $characters->create($user, (int) $data['base_type'], $data['character_name'], (int) $data['gender']);
                $user->update(['name' => $data['name']]);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['name' => 'This team name is already in use.']);
        }

        return redirect()->route('home')->with('status', 'Your adventure begins.');
    }
}
