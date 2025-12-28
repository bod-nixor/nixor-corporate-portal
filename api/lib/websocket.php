<?php
function emit_ws_event(string $event, array $payload = []): void {
    $queueFile = env_value('WS_QUEUE_FILE', dirname(__DIR__, 2) . '/ws/events.queue');
    try {
        $line = json_encode([
            'event' => $event,
            'payload' => $payload,
            'ts' => time()
        ], JSON_THROW_ON_ERROR) . PHP_EOL;
        file_put_contents($queueFile, $line, FILE_APPEND | LOCK_EX);
    } catch (JsonException $e) {
        error_log('Failed to encode websocket event: ' . $e->getMessage());
    }
}
