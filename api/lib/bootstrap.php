<?php
session_start();

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/websocket.php';

header('Content-Type: application/json');
