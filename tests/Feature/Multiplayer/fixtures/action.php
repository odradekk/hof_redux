<?php

use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Separate PHP process means a genuinely independent PostgreSQL connection.
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $payload = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    $result = match ($argv[2]) {
        'bid' => app(AuctionService::class)->bid(...$payload),
        'settle' => ['settled' => app(AuctionService::class)->settleDue()],
        'boss' => app(BossService::class)->challenge(...$payload),
        'rank' => app(RankingService::class)->challenge(...$payload),
        default => throw new InvalidArgumentException('Unknown fixture command'),
    };
    echo json_encode(['status' => 'ok', 'pid' => DB::selectOne('select pg_backend_pid() as pid')->pid, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ValidationException $e) {
    echo json_encode(['status' => 'rejected', 'errors' => $e->errors()], JSON_THROW_ON_ERROR);
}
