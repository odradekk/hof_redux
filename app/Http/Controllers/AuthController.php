<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function register(Request $request)
    {
        $data = $request->validate(['login' => ['required', 'string', 'regex:/\A[a-zA-Z0-9]{4,16}\z/', 'unique:users,login'], 'password' => $this->newPasswordRules()]);
        $data['login'] = strtolower($data['login']);
        if (User::where('login', $data['login'])->exists()) {
            throw ValidationException::withMessages(['login' => 'This account ID is already in use.']);
        }
        $user = DB::transaction(function () use ($data) {
            // A transaction-scoped advisory lock makes the account limit safe across concurrent registrations.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(48464601)');
            }
            if (User::where('login', $data['login'])->exists()) {
                throw ValidationException::withMessages(['login' => 'This account ID is already in use.']);
            }
            if (User::count() >= config('hof.max_users', 500)) {
                throw ValidationException::withMessages(['login' => 'Registration is currently full.']);
            }

            return User::create($data + ['stamina_updated_at' => now(), 'preferences' => ['record_battle_log' => true, 'inventory_javascript' => true, 'color' => 'bdc8d7', 'party' => []]]);
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('setup');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:16', 'password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/']]);
        $data['login'] = strtolower($data['login']);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['login' => 'The account ID or password is incorrect.']);
        }
        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password'], 'password' => $this->newPasswordRules()]);
        $request->user()->update(['password' => $data['password']]);
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();

        return back()->with('status', 'Password changed. Other sessions have been signed out.');
    }

    private function newPasswordRules(): array
    {
        return ['bail', 'required', 'string', 'confirmed', 'max:72', 'not_regex:/\x00/', function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Password must not exceed 72 UTF-8 bytes.');
            }
        }, Password::min(12)];
    }
}
