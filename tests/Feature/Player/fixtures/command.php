<?php

use App\Application\Player\PlayerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
try {
    $result = app(PlayerService::class)->execute(...$input);
    echo json_encode(['pid' => getmypid(), 'status' => 'ok', 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ValidationException|ModelNotFoundException $exception) {
    echo json_encode(['pid' => getmypid(), 'status' => 'rejected', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
