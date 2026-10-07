<?php

use App\Application\Dungeon\DungeonService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$method, $arguments] = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
try {
    $result = app(DungeonService::class)->{$method}(...$arguments);
    echo json_encode(['pid' => getmypid(), 'status' => 'ok', 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['pid' => getmypid(), 'status' => 'rejected', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
