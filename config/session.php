<?php

return ['driver' => env('SESSION_DRIVER', 'database'), 'lifetime' => 180, 'expire_on_close' => false, 'encrypt' => false, 'files' => storage_path('framework/sessions'), 'connection' => env('SESSION_CONNECTION'), 'table' => 'sessions', 'store' => env('SESSION_STORE'), 'lottery' => [2, 100], 'cookie' => 'hof_session', 'path' => '/', 'domain' => env('SESSION_DOMAIN'), 'secure' => env('SESSION_SECURE_COOKIE', true), 'http_only' => true, 'same_site' => 'lax', 'partitioned' => false];
