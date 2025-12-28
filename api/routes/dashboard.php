<?php
function handle_dashboard(string $method, array $_segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $entityId = (int)($_GET['entity_id'] ?? 0);
    if ($entityId <= 0) {
        respond(['ok' => false, 'error' => 'entity_id required'], 400);
    }
    $user = ensure_entity_access($entityId, []);
    $endeavourStmt = db()->prepare('SELECT COUNT(*) as total FROM endeavours WHERE entity_id = ?');
    $endeavourStmt->execute([$entityId]);
    $totalEndeavours = (int)$endeavourStmt->fetch()['total'];

    $docStmt = db()->prepare('SELECT COUNT(*) as total FROM endeavour_documents ed JOIN endeavours e ON ed.endeavour_id = e.id WHERE e.entity_id = ?');
    $docStmt->execute([$entityId]);
    $totalDocs = (int)$docStmt->fetch()['total'];

    $docTarget = max(1, $totalEndeavours * 3);
    $docProgress = min(100, (int)round(($totalDocs / $docTarget) * 100));

    $calendarStmt = db()->prepare('SELECT c.*, u.full_name FROM calendar_events c JOIN users u ON c.created_by = u.id WHERE c.entity_id = ? AND c.event_date >= NOW() ORDER BY c.event_date ASC LIMIT 5');
    $calendarStmt->execute([$entityId]);

    $deadlineStmt = db()->prepare('SELECT id, name, status, start_date, end_date FROM endeavours WHERE entity_id = ? AND status NOT IN ("completed", "rejected") ORDER BY start_date ASC LIMIT 5');
    $deadlineStmt->execute([$entityId]);
    $deadlines = [];
    while ($row = $deadlineStmt->fetch()) {
        $target = $row['start_date'] ?: $row['end_date'];
        if (!$target) {
            $days = null;
        } else {
            $days = (int)round((strtotime($target) - time()) / 86400);
        }
        $deadlines[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'days_until' => $days
        ];
    }

    $announcementStmt = db()->prepare('SELECT a.*, u.full_name FROM dashboard_announcements a JOIN users u ON a.created_by = u.id WHERE a.entity_id = ? ORDER BY a.created_at DESC LIMIT 5');
    $announcementStmt->execute([$entityId]);

    $canPost = $user['global_role'] === 'admin';
    if (!$canPost) {
        $membership = db()->prepare('SELECT department FROM entity_memberships WHERE entity_id = ? AND user_id = ?');
        $membership->execute([$entityId, $user['id']]);
        $dept = $membership->fetch();
        if (!$dept) {
            $canPost = false;
        } else {
            $canPost = in_array($dept['department'], ['communications', 'management'], true);
        }
    }

    respond([
        'ok' => true,
        'data' => [
            'doc_progress' => $docProgress,
            'total_endeavours' => $totalEndeavours,
            'calendar' => $calendarStmt->fetchAll(),
            'deadlines' => $deadlines,
            'announcements' => $announcementStmt->fetchAll(),
            'can_post_announcements' => $canPost
        ]
    ]);
}
