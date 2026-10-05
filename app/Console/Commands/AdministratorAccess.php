<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AdministratorAccess extends Command
{
    protected $signature = 'admin:access {login : Existing account login ID} {--revoke : Remove administrator access} {--confirm : Explicitly approve this privilege change without an interactive prompt}';

    protected $description = 'Grant or revoke administrator access for an existing account from the operator console';

    public function handle(): int
    {
        $login = strtolower((string) $this->argument('login'));
        $user = User::where('login', $login)->first();
        if (! $user) {
            $this->error('No registered account has that login ID.');

            return self::FAILURE;
        }
        $grant = ! $this->option('revoke');
        if (! $this->option('confirm')) {
            if (! $this->input->isInteractive()) {
                $this->error('No change made. Pass --confirm to explicitly approve this operation.');

                return self::FAILURE;
            }
            $question = ($grant ? 'Grant' : 'Revoke').' administrator access for account '.$login.'?';
            if (! $this->confirm($question, false)) {
                $this->warn('No change made.');

                return self::FAILURE;
            }
        }
        $changed = DB::transaction(function () use ($user, $grant): bool {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_admin === $grant) {
                return false;
            }
            $previous = $locked->is_admin;
            $locked->is_admin = $grant;
            $locked->save();
            DB::table('admin_audits')->insert([
                'admin_id' => null,
                'action' => $grant ? 'console.admin.grant' : 'console.admin.revoke',
                'target' => (string) $locked->id,
                'details' => json_encode(['actor' => 'operator-console', 'login' => $locked->login, 'previous_is_admin' => $previous, 'is_admin' => $grant], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
        $this->info($changed ? 'Administrator access '.($grant ? 'granted.' : 'revoked.') : 'Account already has the requested access; no change made.');

        return self::SUCCESS;
    }
}
