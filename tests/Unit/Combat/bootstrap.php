<?php

declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Domain\\Combat\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__, 3).'/app/Domain/Combat/'.substr($class, strlen($prefix)).'.php';
    }
});
