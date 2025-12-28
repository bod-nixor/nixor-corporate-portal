<?php
function emit_ws_event(string $event, array $payload = []): void {
    $queueFile = env_value('WS_QUEUE_FILE', dirname(__DIR__, 2) . '/ws/events.queue');
    $queueDir = dirname($queueFile);
    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0775, true);
    }
    try {
        $line = json_encode([
            'event' => $event,
            'payload' => $payload,
            'ts' => time()
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        $result = file_put_contents($queueFile, $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            $error = error_get_last();
            error_log('Failed to write websocket event to queue: ' . ($error['message'] ?? 'unknown'));
        }
    } catch (JsonException $e) {
        error_log('Failed to encode websocket event: ' . $e->getMessage());
    }
}
