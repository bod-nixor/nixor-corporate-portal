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

    $pendingStmt = db()->prepare('SELECT e.id, e.name, eda.doc_type, eda.approver_group FROM endeavour_doc_approvals eda JOIN endeavours e ON eda.endeavour_id = e.id WHERE e.entity_id = ? AND eda.status = "pending" ORDER BY e.created_at DESC');
    $pendingStmt->execute([$entityId]);
    $pendingRows = $pendingStmt->fetchAll();
    $pendingDocs = [];
    foreach ($pendingRows as $row) {
        $key = (int)$row['id'];
        if (!isset($pendingDocs[$key])) {
            $pendingDocs[$key] = [
                'endeavour_id' => $key,
                'endeavour_name' => $row['name'],
                'pending' => []
            ];
        }
        $pendingDocs[$key]['pending'][] = [
            'doc_type' => $row['doc_type'],
            'approver_group' => $row['approver_group']
        ];
    }

    $calendarStmt = db()->prepare('SELECT c.*, u.full_name FROM calendar_events c JOIN users u ON c.created_by = u.id WHERE c.entity_id = ? AND c.event_date >= NOW() ORDER BY c.event_date ASC LIMIT 5');
    $calendarStmt->execute([$entityId]);

    $deadlineStmt = db()->prepare('SELECT id, name, status, phase, volunteer_registration_deadline, pre_financial_deadline, post_financial_deadline, event_start_at, event_end_at, start_date, end_date FROM endeavours WHERE entity_id = ? AND phase NOT IN ("COMPLETED") ORDER BY created_at DESC LIMIT 20');
    $deadlineStmt->execute([$entityId]);
    $deadlines = [];
    while ($row = $deadlineStmt->fetch()) {
        $candidates = [
            'Volunteer registration deadline' => $row['volunteer_registration_deadline'],
            'Pre-financial deadline' => $row['pre_financial_deadline'],
            'Post-financial deadline' => $row['post_financial_deadline'],
            'Event start' => $row['event_start_at'],
            'Event end' => $row['event_end_at'],
            'Start date' => $row['start_date'],
            'End date' => $row['end_date']
        ];
        $nextLabel = null;
        $nextTs = null;
        foreach ($candidates as $label => $date) {
            if (!$date) {
                continue;
            }
            $ts = strtotime($date);
            if ($ts === false) {
                continue;
            }
            if ($nextTs === null || $ts < $nextTs) {
                $nextTs = $ts;
                $nextLabel = $label;
            }
        }
        $days = $nextTs ? (int)round(($nextTs - time()) / 86400) : null;
        $deadlines[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'phase' => $row['phase'],
            'deadline_label' => $nextLabel,
            'days_until' => $days
        ];
    }
    usort($deadlines, static function ($a, $b) {
        if ($a['days_until'] === null) {
            return 1;
        }
        if ($b['days_until'] === null) {
            return -1;
        }
        return $a['days_until'] <=> $b['days_until'];
    });
    $deadlines = array_slice($deadlines, 0, 5);

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
            'pending_docs' => array_values($pendingDocs),
            'calendar' => $calendarStmt->fetchAll(),
            'deadlines' => $deadlines,
            'announcements' => $announcementStmt->fetchAll(),
            'can_post_announcements' => $canPost
        ]
    ]);
}
