<?php
/**
 * Log an audit event. Pass null actorId for system-initiated actions (cron or automation).
 */
function log_activity(?int $actorId, string $entityType, int $entityId, string $action, string $notes = '', array $metadata = []): void {
    $stmt = db()->prepare('INSERT INTO activity_log (actor_id, entity_type, entity_id, action, notes, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $actorId,
        $entityType,
        $entityId,
        $action,
        $notes,
        json_encode($metadata)
    ]);
}
