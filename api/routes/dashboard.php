<?php
function handle_dashboard(string $method, array $_segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $entityId = (int)($_GET['entity_id'] ?? 0);
    if ($entityId <= 0) {
        respond(['ok' => false, 'error' => 'entity_id required'], 400);
    }
    $user = require_permission('entity.view', $entityId);
    $endeavourStmt = db()->prepare('SELECT COUNT(*) as total FROM endeavours WHERE entity_id = ?');
    $endeavourStmt->execute([$entityId]);
    $totalEndeavours = (int)$endeavourStmt->fetch()['total'];

    $approvalMetricStmt = db()->prepare('SELECT eda.endeavour_id, eda.doc_type, eda.status FROM endeavour_doc_approvals eda JOIN endeavours e ON eda.endeavour_id = e.id WHERE e.entity_id = ?');
    $approvalMetricStmt->execute([$entityId]);
    $docGroups = [];
    foreach ($approvalMetricStmt->fetchAll() as $approvalRow) {
        $key = $approvalRow['endeavour_id'] . ':' . $approvalRow['doc_type'];
        if (!isset($docGroups[$key])) {
            $docGroups[$key] = ['total' => 0, 'approved' => 0];
        }
        $docGroups[$key]['total'] += 1;
        if ($approvalRow['status'] === 'approved') {
            $docGroups[$key]['approved'] += 1;
        }
    }
    $totalDocs = count($docGroups);
    $approvedDocs = 0;
    foreach ($docGroups as $docGroup) {
        if ($docGroup['total'] > 0 && $docGroup['approved'] === $docGroup['total']) {
            $approvedDocs += 1;
        }
    }
    $docProgress = $totalDocs > 0 ? min(100, (int)round(($approvedDocs / $totalDocs) * 100)) : 0;

    $isExecutive = can_permission($user, 'endeavour.submit_docs', $entityId);
    $isMobApprover = can_permission($user, 'endeavour.approve_mob', $entityId);
    $isSaApprover = can_permission($user, 'endeavour.approve_sa', $entityId);

    $pendingDocs = [];
    
    $edaStmt = db()->prepare('SELECT e.id as endeavour_id, e.name as endeavour_name, e.pre_financial_deadline, e.post_financial_deadline, eda.doc_type, eda.approver_group, eda.status FROM endeavour_doc_approvals eda JOIN endeavours e ON eda.endeavour_id = e.id WHERE e.entity_id = ? AND eda.status IN ("pending", "rejected") ORDER BY eda.created_at DESC');
    $edaStmt->execute([$entityId]);
    $seenDocKeys = [];
    foreach ($edaStmt->fetchAll() as $row) {
        $category = null;
        if ($row['status'] === 'rejected' && $isExecutive) {
            $category = 'rejected';
        } elseif ($row['status'] === 'pending') {
            if (($row['approver_group'] === 'bod' && $isMobApprover) || ($row['approver_group'] === 'student_affairs' && $isSaApprover)) {
                $category = 'pending_approval';
            } elseif ($isExecutive) {
                $category = 'pending_approval_waiting';
            }
        }
        if ($category) {
            $docKey = $row['endeavour_id'] . '_' . $row['doc_type'];
            $seenDocKeys[$docKey] = true;
            $pendingDocs[] = [
                'endeavour_id' => $row['endeavour_id'],
                'endeavour_name' => $row['endeavour_name'],
                'doc_type' => $row['doc_type'],
                'doc_label' => dashboard_doc_label((string)$row['doc_type']),
                'approver_group' => $row['approver_group'],
                'category' => $category,
                'action_label' => dashboard_doc_action_label($category, $row['approver_group']),
                'due_at' => dashboard_doc_due_at((string)$row['doc_type'], $row),
                'is_actionable' => ($category === 'rejected' || $category === 'pending_approval')
            ];
        }
    }

    if ($isExecutive) {
        $missingStmt = db()->prepare('SELECT id, name, status, pre_financial_deadline, post_financial_deadline FROM endeavours WHERE entity_id = ? AND status IN ("board_approved_ops_plan_required", "mou_approved_pre_financial_required", "closed_ops_epilogue_required") ORDER BY created_at DESC');
        $missingStmt->execute([$entityId]);
        $now = time();
        foreach ($missingStmt->fetchAll() as $row) {
            $docType = '';
            $isOverdue = false;
            if ($row['status'] === 'board_approved_ops_plan_required') {
                $docType = 'operational_plan';
            } elseif ($row['status'] === 'mou_approved_pre_financial_required') {
                $docType = 'pre_financial';
                if ($row['pre_financial_deadline'] && strtotime($row['pre_financial_deadline']) < $now) {
                    $isOverdue = true;
                }
            } elseif ($row['status'] === 'closed_ops_epilogue_required') {
                $docType = 'epilogue';
            }
            if ($docType) {
                $docKey = $row['id'] . '_' . $docType;
                if (isset($seenDocKeys[$docKey])) {
                    continue;
                }
                $pendingDocs[] = [
                    'endeavour_id' => $row['id'],
                    'endeavour_name' => $row['name'],
                    'doc_type' => $docType,
                    'doc_label' => dashboard_doc_label($docType),
                    'approver_group' => null,
                    'category' => $isOverdue ? 'overdue' : 'pending_submission',
                    'action_label' => $isOverdue ? 'Overdue' : 'To submit',
                    'due_at' => dashboard_doc_due_at($docType, $row),
                    'is_actionable' => true
                ];
            }
        }
    }

    $calendarStmt = db()->prepare('
        SELECT DISTINCT c.*, u.full_name 
        FROM calendar_events c 
        JOIN users u ON c.created_by = u.id 
        LEFT JOIN calendar_event_entities cee ON cee.event_id = c.id
        WHERE (c.entity_id = ? OR cee.entity_id = ?) 
          AND c.event_date >= ? 
        ORDER BY c.event_date ASC 
        LIMIT 5
    ');
    $calendarStmt->execute([$entityId, $entityId, date('Y-m-d H:i:s')]);

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
        $now = time();
        foreach ($candidates as $label => $date) {
            if (!$date) {
                continue;
            }
            $ts = strtotime($date);
            if ($ts === false || $ts < $now) {
                continue;
            }
            if ($nextTs === null || $ts < $nextTs) {
                $nextTs = $ts;
                $nextLabel = $label;
            }
        }
        if ($nextTs === null) {
            foreach ($candidates as $label => $date) {
                if (!$date) {
                    continue;
                }
                $ts = strtotime($date);
                if ($ts === false || $ts >= $now) {
                    continue;
                }
                if ($nextTs === null || $ts > $nextTs) {
                    $nextTs = $ts;
                    $nextLabel = $label;
                }
            }
        }
        if ($nextTs === null) {
            continue;
        }
        $days = (int)round(($nextTs - time()) / 86400);
        $deadlines[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'phase' => $row['phase'],
            'deadline_label' => $nextLabel,
            'deadline_at' => date('Y-m-d H:i:s', $nextTs),
            'days_until' => $days
        ];
    }
    usort($deadlines, static fn($a, $b) => $a['days_until'] <=> $b['days_until']);
    $deadlines = array_slice($deadlines, 0, 5);

    $announcementStmt = db()->prepare('SELECT a.*, u.full_name, u.full_name AS creator_name FROM dashboard_announcements a JOIN users u ON a.created_by = u.id WHERE a.entity_id = ? ORDER BY a.created_at DESC LIMIT 5');
    $announcementStmt->execute([$entityId]);

    $canPost = can_permission($user, 'entity.announce', $entityId);
    $announcements = array_map(
        fn($row) => dashboard_announcement_row($row, $canPost),
        $announcementStmt->fetchAll()
    );

    respond([
        'ok' => true,
        'data' => [
            'doc_progress' => $docProgress,
            'doc_progress_approved' => $approvedDocs,
            'doc_progress_total' => $totalDocs,
            'total_endeavours' => $totalEndeavours,
            'pending_docs' => array_values($pendingDocs),
            'calendar' => $calendarStmt->fetchAll(),
            'deadlines' => $deadlines,
            'announcements' => $announcements,
            'can_post_announcements' => $canPost,
            'can_manage_announcements' => $canPost
        ]
    ]);
}

function dashboard_doc_label(string $docType): string {
    $labels = [
        'operational_plan' => 'Operational plan',
        'ops_plan' => 'Operational plan',
        'budget_plan' => 'Budget plan',
        'pre_financial' => 'Pre-financial report',
        'post_financial' => 'Post-financial report',
        'epilogue' => 'Epilogue',
        'mou' => 'MOU',
    ];
    return $labels[$docType] ?? ucwords(str_replace('_', ' ', $docType));
}

function dashboard_doc_action_label(?string $category, ?string $approverGroup): string {
    $group = $approverGroup === 'bod' ? 'MoB' : ($approverGroup === 'student_affairs' ? 'Student Affairs' : '');
    if ($category === 'rejected') {
        return 'Rejected';
    }
    if ($category === 'pending_approval') {
        return $group ? "To approve - {$group}" : 'To approve';
    }
    if ($category === 'pending_approval_waiting') {
        return $group ? "Awaiting {$group}" : 'Awaiting approval';
    }
    if ($category === 'pending_submission') {
        return 'To submit';
    }
    if ($category === 'overdue') {
        return 'Overdue';
    }
    return 'Pending';
}

function dashboard_doc_due_at(string $docType, array $row): ?string {
    if ($docType === 'pre_financial') {
        return $row['pre_financial_deadline'] ?? null;
    }
    if ($docType === 'post_financial') {
        return $row['post_financial_deadline'] ?? null;
    }
    return null;
}

function dashboard_announcement_row(array $row, bool $canManage): array {
    $row['can_edit'] = $canManage;
    $row['can_delete'] = $canManage;
    return $row;
}
