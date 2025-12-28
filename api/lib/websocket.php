<?php
function emit_ws_event(string $event, array $payload = []): void {
    $queueFile = env_value('WS_QUEUE_FILE', dirname(__DIR__, 2) . '/ws/events.queue');
    $line = json_encode([
        'event' => $event,
        'payload' => $payload,
        'ts' => time()
    ]) . PHP_EOL;
    file_put_contents($queueFile, $line, FILE_APPEND | LOCK_EX);
}
