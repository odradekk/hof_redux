<?php

namespace App\Http\Controllers\Game;

use App\Application\Community\AccountDeletion;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Player\ItemDetails;
use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Http\View\ItemLines;
use App\Http\View\UnitCards;
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

        $totals = [
            ['label' => '账号数', 'value' => number_format(User::count())],
            ['label' => '总资金', 'value' => '$ '.number_format(User::sum('money'))],
            ['label' => '角色数', 'value' => number_format(Character::count())],
            ['label' => '道具数量', 'value' => number_format(InventoryItem::sum('quantity'))],
        ];
        $actions = ['account.balance' => '资金修正', 'account.delete' => '删除账号', 'announcement.publish' => '发布公告', 'moderation.delete' => '内容审核', 'reports.prune' => '战报清理', 'maintenance.run' => '运行维护'];
        $audits = AdminAudit::latest()->limit(30)->get()->map(static fn (AdminAudit $audit): array => [
            'at' => $audit->created_at, 'admin' => $audit->admin_id ?? '用户本人',
            'action' => $actions[$audit->action] ?? $audit->action, 'target' => $audit->target ?? '—',
            'details' => json_encode($audit->details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ])->all();

        return view('game.admin.index', [
            'users' => User::orderBy('id')->paginate(30), 'totals' => $totals, 'audits' => $audits,
            'announcements' => Announcement::latest()->get(), 'messages' => BoardMessage::latest()->get(),
        ]);
    }

    public function user(Request $request, User $user, ContentCatalog $catalog, ItemDetails $details)
    {
        abort_unless($request->user()->is_admin, 403);

        $user->load('characters', 'inventory');
        $units = $user->characters->map(static fn (Character $character): array => UnitCards::character($character->toArray(), $catalog->get('jobs', $character->job_id)))->all();
        $locations = ['backpack' => '背包', 'equipped' => '已装备', 'auction' => '拍卖托管'];
        $items = $user->inventory->map(static function (InventoryItem $item) use ($details, $locations): array {
            $row = $item->toArray();

            return ['id' => $item->id, 'line' => ItemLines::fromInventory($row, $details->resolve($row)), 'location' => $locations[$item->location] ?? '其他'];
        })->all();

        return view('game.admin.user', ['player' => $user, 'units' => $units, 'items' => $items]);
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

        return back()->with('status', '资金已修正，并记录审计。');
    }

    public function deleteUser(Request $request, User $user, AccountDeletion $deletion)
    {
        abort_unless($request->user()->is_admin, 403);
        $request->validate(['confirm' => 'required|in:DELETE', 'current_password' => ['bail', 'required', 'string', 'max:72', 'not_regex:/\x00/', 'current_password']]);
        $deletion->delete($user->id, $request->user()->id);

        return redirect()->route('admin.index')->with('status', '账号已删除。');
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

        return back()->with('status', '公告已发布。');
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

        return back()->with('status', '内容已删除。');
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

        return back()->with('status', '战报已清理，挑战记录、冷却和统计已保留。');
    }

    public function maintenance(Request $request, BossService $bosses, AuctionService $auctions, GameAction $actions)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['confirm' => 'required|in:RUN']);
        $created = $bosses->bootstrap();
        $respawned = $bosses->respawnDue();
        $settled = $auctions->settleDue();
        $this->audit($request->user(), 'maintenance.run', null, compact('created', 'respawned', 'settled'));

        return back()->with('status', "维护完成：初始化$created个首领，复活$respawned个首领，结算$settled场拍卖。");
    }

    private function audit(User $admin, string $action, ?string $target, array $details): void
    {
        AdminAudit::create(['admin_id' => $admin->id, 'action' => $action, 'target' => $target, 'details' => $details]);
    }
}
