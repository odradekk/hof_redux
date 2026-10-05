<?php

namespace App\Http\Controllers\Game;

use App\Application\Community\AccountDeletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AccountDeletionController
{
    public function delete(Request $request, AccountDeletion $service)
    {
        $request->validate(['current_password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password'], 'confirm' => 'required|in:DELETE']);
        $service->delete($request->user()->id);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account has been deleted.');
    }
}
