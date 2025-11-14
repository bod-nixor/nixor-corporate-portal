<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Lib\Env;
use App\Services\SettingsService;
use App\Lib\Response;

$settings = new SettingsService();

Response::json([
    'googleClientId' => Env::get('GOOGLE_CLIENT_ID'),
    'wsUrl' => Env::get('WS_URL'),
    'visibilityMode' => $settings->getVisibilityMode(),
]);
