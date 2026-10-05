<?php

namespace App\Http\Controllers\Game;

use App\Application\Community\AccountDeletion;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Support\GameAction;
use App\Models\AdminAudit;
use App\Models\Announcement;
use App\Models\BattleReport;
use App\Models\BoardMessage;
use App\Models\BossChallenge;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\RankingChallenge;
use App\Models\User;
use Illuminate\Http\Request;

final class AdminController
{
    public function index(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);

        return view('game.admin.index', ['users' => User::orderBy('id')->paginate(30), 'totals' => ['accounts' => User::count(), 'money' => User::sum('money'), 'characters' => Character::count(), 'inventory' => InventoryItem::sum('quantity')], 'audits' => AdminAudit::latest()->limit(30)->get(), 'announcements' => Announcement::latest()->get(), 'messages' => BoardMessage::latest()->get()]);
    }

    public function user(Request $request, User $user)
    {
        abort_unless($request->user()->is_admin, 403);

        return view('game.admin.user', ['player' => $user->load('characters', 'inventory')]);
    }

    public function updateUser(Request $request, User $user, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['operation_id' => 'required|uuid', 'money_delta' => 'required|integer|between:-1000000000,1000000000', 'reason' => 'required|string|max:200']);
        $target = $user->id;
        $actions->execute($request->user()->id, 'admin.balance', $data['operation_id'], ['target' => $target, ...$data], function (User $admin, int $op) use ($actions, $target, $data) {
            abort_unless($admin->is_admin, 403);
            $player = User::lockForUpdate()->findOrFail($target);
            $actions->money($player, (int) $data['money_delta'], $op, 'administrator correction');
            $this->audit($admin, 'account.balance', (string) $target, ['delta' => (int) $data['money_delta'], 'reason' => $data['reason']]);

            return ['ok' => true];
        });

        return back()->with('status', 'Balance corrected and audited.');
    }

    public function deleteUser(Request $request, User $user, AccountDeletion $deletion)
    {
        abort_unless($request->user()->is_admin, 403);
        $request->validate(['confirm' => 'required|in:DELETE', 'current_password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password']]);
        $deletion->delete($user->id, $request->user()->id);

        return redirect()->route('admin.index')->with('status', 'Account deleted.');
    }

    public function announcement(Request $request, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['operation_id' => 'required|uuid', 'title' => 'required|string|max:120', 'body' => 'required|string|max:20000']);
        $actions->execute($request->user()->id, 'admin.announcement', $data['operation_id'], $data, function (User $admin) use ($data) {
            abort_unless($admin->is_admin, 403);
            $notice = Announcement::create(['title' => $data['title'], 'body' => $data['body'], 'published' => true]);
            $this->audit($admin, 'announcement.publish', (string) $notice->id, []);

            return ['id' => $notice->id];
        });

        return back()->with('status', 'Announcement published.');
    }

    public function moderate(Request $request, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['operation_id' => 'required|uuid', 'type' => 'required|in:message,announcement', 'id' => 'required|integer|min:1', 'confirm' => 'required|in:DELETE']);
        $actions->execute($request->user()->id, 'admin.moderate', $data['operation_id'], $data, function (User $admin) use ($data) {
            abort_unless($admin->is_admin, 403);
            $model = $data['type'] === 'message' ? BoardMessage::class : Announcement::class;
            $model::findOrFail($data['id'])->delete();
            $this->audit($admin, 'moderation.delete', $data['type'].':'.$data['id'], []);

            return ['ok' => true];
        });

        return back()->with('status', 'Content removed.');
    }

    public function reports(Request $request, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['operation_id' => 'required|uuid', 'type' => 'required|in:pve,boss,pvp', 'before' => 'required|date|before_or_equal:today', 'confirm' => 'required|in:DELETE']);
        $actions->execute($request->user()->id, 'admin.reports', $data['operation_id'], $data, function (User $admin) use ($data) {
            abort_unless($admin->is_admin, 403);
            // Challenge rows are gameplay history: redact reports without resetting cooldowns/statistics.
            if ($data['type'] === 'pve') {
                $count = BattleReport::where('created_at', '<', $data['before'])->delete();
            } elseif ($data['type'] === 'pvp') {
                $count = RankingChallenge::where('created_at', '<', $data['before'])->update(['report' => null]);
            } else {
                $count = BossChallenge::where('created_at', '<', $data['before'])->update(['report' => json_encode(['mode' => 'boss', 'names' => ['Archived', 'Archived'], 'events' => [], 'teams' => [[], []], 'winner' => null, 'reason' => 'Report removed by administrator'])]);
            }
            $this->audit($admin, 'reports.prune', $data['type'], ['count' => $count, 'before' => $data['before']]);

            return ['count' => $count];
        });

        return back()->with('status', 'Reports removed; challenge history preserved.');
    }

    public function maintenance(Request $request, BossService $bosses, AuctionService $auctions, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['confirm' => 'required|in:RUN']);
        $created = $bosses->bootstrap();
        $respawned = $bosses->respawnDue();
        $settled = $auctions->settleDue();
        $this->audit($request->user(), 'maintenance.run', null, compact('created', 'respawned', 'settled'));

        return back()->with('status', "Maintenance completed: $created bosses initialized, $respawned respawned, $settled auctions settled.");
    }

    private function audit(User $admin, string $action, ?string $target, array $details): void
    {
        AdminAudit::create(['admin_id' => $admin->id, 'action' => $action, 'target' => $target, 'details' => $details]);
    }
}
