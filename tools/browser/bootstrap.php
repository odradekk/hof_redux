<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

// These tools are mounted into disposable CI containers, never copied into application images.
if (PHP_SAPI !== 'cli' || getenv('HOF_BROWSER_TESTS') !== '1') {
    throw new RuntimeException('Browser fixtures require CLI and HOF_BROWSER_TESTS=1.');
}

$root = getenv('HOF_APP_ROOT') ?: dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's console renderer is not the owner of these standalone scripts' exit status.
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});

if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Browser fixture tools refuse production and every non-test environment.');
}

const BROWSER_LOGIN = 'hof_browser_qa';
const BROWSER_PASSWORD = 'Synthetic-browser-only-2026!';
