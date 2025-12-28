<?php
/**
 * Log an audit event. Pass null actorId for system-initiated actions (cron or automation).
 */
function log_activity(?int $actorId, string $entityType, int $entityId, string $action, string $notes = '', array $metadata = []): void {
    try {
        $encoded = json_encode($metadata);
        if ($encoded === false) {
            error_log('Activity log metadata encoding failed');
            $encoded = '{}';
        }
        $stmt = db()->prepare('INSERT INTO activity_log (actor_id, entity_type, entity_id, action, notes, metadata) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $actorId,
            $entityType,
            $entityId,
            $action,
            $notes,
            $encoded
        ]);
    } catch (PDOException $e) {
        error_log(sprintf(
            'Activity log insert failed (actorId=%s entityType=%s entityId=%s): %s',
            $actorId ?? 'null',
            $entityType,
            $entityId,
            $e->getMessage()
        ));
    }
}
