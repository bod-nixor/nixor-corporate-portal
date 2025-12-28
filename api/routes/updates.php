<?php
function handle_updates(string $method, array $_segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $user = require_auth();
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
    $stmt = db()->prepare('SELECT * FROM activity_log WHERE id > ? ORDER BY id ASC LIMIT ?');
    $stmt->execute([$since, $limit]);
    $events = $stmt->fetchAll();

    if (!in_array($user['global_role'], ['admin', 'board'], true)) {
        $entityStmt = db()->prepare('SELECT entity_id FROM entity_memberships WHERE user_id = ?');
        $entityStmt->execute([$user['id']]);
        $entityIds = array_map(fn($row) => (int)$row['entity_id'], $entityStmt->fetchAll());
        if (!$entityIds) {
            $events = [];
        } else {
            $allowedEventIds = filter_events_by_entity($events, $entityIds);
            $events = array_values(array_filter($events, fn($event) => in_array((int)$event['id'], $allowedEventIds, true)));
        }
    }

    $idsByType = [];
    foreach ($events as $event) {
        $type = $event['entity_type'];
        $idsByType[$type] = $idsByType[$type] ?? [];
        $idsByType[$type][] = (int)$event['entity_id'];
    }

    $related = [];
    foreach ($idsByType as $type => $ids) {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            continue;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
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
            default:
                $query = '';
        }
        if ($query) {
            $relStmt = db()->prepare($query);
            $relStmt->execute($ids);
            $related[$type] = $relStmt->fetchAll();
        }
    }

    $lastId = $since;
    if ($events) {
        $lastId = (int)end($events)['id'];
    }

    respond(['ok' => true, 'data' => ['events' => $events, 'related' => $related, 'last_event_id' => $lastId]]);
}

function filter_events_by_entity(array $events, array $entityIds): array {
    $idsByType = [];
    foreach ($events as $event) {
        $idsByType[$event['entity_type']][] = (int)$event['entity_id'];
    }
    $allowed = [];
    foreach ($idsByType as $type => $ids) {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            continue;
        }
        $entityPlaceholders = implode(',', array_fill(0, count($entityIds), '?'));
        $idsPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($entityIds, $ids);
        $query = '';
        switch ($type) {
            case 'endeavour':
                $query = "SELECT id FROM endeavours WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'entity':
                $query = "SELECT id FROM entities WHERE id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'announcement':
                $query = "SELECT id FROM dashboard_announcements WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'drive_item':
                $query = "SELECT id FROM file_drive_items WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'social_post':
                $query = "SELECT id FROM social_posts WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'calendar_event':
                $query = "SELECT id FROM calendar_events WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            case 'member':
                $query = "SELECT id FROM entity_memberships WHERE entity_id IN ({$entityPlaceholders}) AND id IN ({$idsPlaceholders})";
                break;
            default:
                $query = '';
        }
        if ($query) {
            $stmt = db()->prepare($query);
            $stmt->execute($params);
            $allowed = array_merge($allowed, array_map(fn($row) => (int)$row['id'], $stmt->fetchAll()));
        }
    }
    return array_values(array_unique($allowed));
}
