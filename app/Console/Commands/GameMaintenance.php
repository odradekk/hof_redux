<?php

namespace App\Console\Commands;

use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use Illuminate\Console\Command;

final class GameMaintenance extends Command
{
    protected $signature = 'game:maintain {--bootstrap : Create missing fresh-world boss instances}';

    protected $description = 'Settle expired auctions and respawn shared bosses safely';

    public function handle(AuctionService $auction, BossService $boss): int
    {
        if ($this->option('bootstrap')) {
            $this->info('Bosses initialized: '.$boss->bootstrap());
        }
        $this->info('Auctions settled: '.$auction->settleDue());
        $this->info('Bosses respawned: '.$boss->respawnDue());

        return self::SUCCESS;
    }
}
