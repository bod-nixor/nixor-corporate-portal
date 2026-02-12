<?php
function handle_updates(string $method, array $_segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $user = require_auth();
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;

    // Fetch events
    $stmt = db()->prepare('SELECT * FROM activity_log WHERE id > ? ORDER BY id ASC LIMIT ?');
    $stmt->execute([$since, $limit]);
    $rawEvents = $stmt->fetchAll();

    // Determine the last event ID scanned to prevent infinite loops if everything is filtered
    $lastScannedId = $since;
    if ($rawEvents) {
        $lastEvent = end($rawEvents);
        $lastScannedId = (int)$lastEvent['id'];
    }

    // Collect IDs by type
    $idsByType = [];
    foreach ($rawEvents as $event) {
        $type = $event['entity_type'];
        $idsByType[$type] = $idsByType[$type] ?? [];
        $idsByType[$type][] = (int)$event['entity_id'];
    }

    // Fetch related objects
    $relatedMap = []; // type => [id => object]
    $relatedList = []; // type => [object, object] (for response)

    foreach ($idsByType as $type => $ids) {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            continue;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $query = '';
        switch ($type) {
            case 'endeavour':
                $query = "SELECT e.*, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id WHERE e.id IN ({$in})";
                break;
            case 'entity':
                $query = "SELECT * FROM entities WHERE id IN ({$in})";
                break;
            case 'announcement':
                $query = "SELECT * FROM dashboard_announcements WHERE id IN ({$in})";
                break;
            case 'drive_item':
                $query = "SELECT * FROM file_drive_items WHERE id IN ({$in})";
                break;
            case 'social_post':
                $query = "SELECT * FROM social_posts WHERE id IN ({$in})";
                break;
            case 'calendar_event':
                $query = "SELECT * FROM calendar_events WHERE id IN ({$in})";
                break;
            case 'member':
                $query = "SELECT em.*, u.full_name, u.email, e.name AS entity_name FROM entity_memberships em JOIN users u ON em.user_id = u.id JOIN entities e ON em.entity_id = e.id WHERE em.id IN ({$in})";
                break;
        }

        if ($query) {
            $relStmt = db()->prepare($query);
            $relStmt->execute($ids);
            $rows = $relStmt->fetchAll();
            $relatedList[$type] = $rows;
            foreach ($rows as $row) {
                $relatedMap[$type][$row['id']] = $row;
            }
        }
    }

    // Filter events
    $filteredEvents = $rawEvents;
    if (!in_array($user['global_role'], ['admin', 'board'], true)) {
        $entityStmt = db()->prepare('SELECT entity_id FROM entity_memberships WHERE user_id = ?');
        $entityStmt->execute([$user['id']]);
        $userEntityIds = array_map(fn($row) => (int)$row['entity_id'], $entityStmt->fetchAll());

        $filteredEvents = array_values(array_filter($rawEvents, function($event) use ($userEntityIds, $relatedMap) {
            $type = $event['entity_type'];
            $id = (int)$event['entity_id'];

            // Map to parent entity ID
            $parentEntityId = null;

            if ($type === 'entity') {
                $parentEntityId = $id;
            } elseif (isset($relatedMap[$type][$id])) {
                $obj = $relatedMap[$type][$id];
                if (isset($obj['entity_id'])) {
                    $parentEntityId = (int)$obj['entity_id'];
                }
            } else {
                // Related object not found (deleted?) or type not handled (e.g. user)
                return false;
            }

            if ($parentEntityId === null) {
                return false;
            }

            return in_array($parentEntityId, $userEntityIds, true);
        }));
    }

    respond([
        'ok' => true,
        'data' => [
            'events' => $filteredEvents,
            'related' => $relatedList,
            'last_event_id' => $lastScannedId
        ]
    ]);
}
