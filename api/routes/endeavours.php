<?php
function handle_endeavours(string $method, array $segments): void {
    $rawId = $segments[1] ?? null;
    $id = $rawId !== null ? resolve_public_or_internal_id('endeavours', $rawId) : null;
    $action = $segments[2] ?? null;
    if (!$id && $rawId && !$action) {
        $action = $rawId;
    }

    if ($method === 'GET' && $action === 'volunteering') {
        $user = require_auth();
        require_permission('volunteering.view', null, $user);
        $params = [$user['id']];
        $filters = [
            'e.volunteering_enabled = 1',
            'e.phase = "VOLUNTEER_REGISTRATION"',
            '(e.volunteer_registration_deadline IS NULL OR e.volunteer_registration_deadline >= NOW())'
        ];
        if (!empty($_GET['entity_id'])) {
            $entityId = (int)$_GET['entity_id'];
            if ($entityId <= 0) {
                respond(['ok' => false, 'error' => 'Invalid entity_id'], 400);
            }
            $filters[] = 'e.entity_id = ?';
            $params[] = $entityId;
        }
        if (!empty($_GET['q'])) {
            $filters[] = '(e.name LIKE ? ESCAPE "\\\\" OR en.name LIKE ? ESCAPE "\\\\")';
            $term = trim($_GET['q']);
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
            $search = '%' . $escaped . '%';
            $params[] = $search;
            $params[] = $search;
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $stmt = db()->prepare(
            "SELECT e.*, en.name AS entity_name, vr.id AS registration_id\n"
            . "FROM endeavours e\n"
            . "JOIN entities en ON e.entity_id = en.id\n"
            . "LEFT JOIN volunteer_registrations vr ON vr.endeavour_id = e.id AND vr.user_id = ?\n"
            . "{$where}\n"
            . "ORDER BY e.volunteer_registration_deadline ASC, e.created_at DESC"
        );
        $stmt->execute($params);
        $rows = array_map(static function ($row) {
            $row['registered'] = !empty($row['registration_id']);
            return $row;
        }, $stmt->fetchAll());
        respond(['ok' => true, 'data' => $rows]);
    }

    if ($action === 'volunteering_ops') {
        $user = require_permission('volunteering.ops');
        if ($method === 'GET') {
            $params = [];
            $filters = ['vr.status = "shortlisted"'];
            if (!empty($_GET['student_id'])) {
                $filters[] = 's.student_id LIKE ? ESCAPE "\\\\"';
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], trim((string)$_GET['student_id']));
                $params[] = '%' . $escaped . '%';
            }
            if (!empty($_GET['entity_id'])) {
                $filters[] = 'e.entity_id = ?';
                $params[] = (int)$_GET['entity_id'];
            }
            if (!empty($_GET['q'])) {
                $filters[] = '(e.name LIKE ? ESCAPE "\\\\" OR en.name LIKE ? ESCAPE "\\\\")';
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], trim((string)$_GET['q']));
                $params[] = '%' . $escaped . '%';
                $params[] = '%' . $escaped . '%';
            }
            $where = 'WHERE ' . implode(' AND ', $filters);
            $stmt = db()->prepare(
                "SELECT vr.*, u.full_name, u.email, s.student_id, e.name AS endeavour_name, e.event_start_at, e.event_end_at, e.transport_fee_required, e.transport_fee_amount, en.name AS entity_name
                 FROM volunteer_registrations vr
                 JOIN users u ON u.id = vr.user_id
                 LEFT JOIN students s ON s.user_id = u.id
                 JOIN endeavours e ON e.id = vr.endeavour_id
                 JOIN entities en ON en.id = e.entity_id
                 {$where}
                 ORDER BY e.event_start_at DESC, en.name, u.full_name"
            );
            $stmt->execute($params);
            respond(['ok' => true, 'data' => $stmt->fetchAll()]);
        }

        if ($method === 'POST') {
            $data = read_json();
            $registrationId = (int)($data['registration_id'] ?? 0);
            if ($registrationId <= 0) {
                respond(['ok' => false, 'error' => 'registration_id required'], 400);
            }
            $check = db()->prepare('SELECT vr.*, vr.status AS registration_status, e.entity_id, e.transport_fee_required, e.phase AS endeavour_phase, e.status AS endeavour_status FROM volunteer_registrations vr JOIN endeavours e ON e.id = vr.endeavour_id WHERE vr.id = ?');
            $check->execute([$registrationId]);
            $registration = $check->fetch();
            if (!$registration) {
                respond(['ok' => false, 'error' => 'Registration not found'], 404);
            }
            if (($registration['registration_status'] ?? '') === 'rejected' || ($registration['endeavour_status'] ?? '') === 'rejected' || (($registration['registration_status'] ?? '') !== 'shortlisted' && ($registration['endeavour_phase'] ?? '') !== 'ON_DAY')) {
                respond(['ok' => false, 'error' => 'Registration is not ready for volunteering operations'], 403);
            }
            $fields = [];
            $values = [];
            if (array_key_exists('attendance_status', $data)) {
                $status = (string)$data['attendance_status'];
                if (!in_array($status, ['', 'present', 'absent'], true)) {
                    respond(['ok' => false, 'error' => 'Invalid attendance_status'], 400);
                }
                $fields[] = 'attendance_status = ?';
                $values[] = $status === '' ? null : $status;
            }
            if (array_key_exists('transport_fee_paid', $data)) {
                if (!(int)$registration['transport_fee_required']) {
                    respond(['ok' => false, 'error' => 'Transport fee not required'], 400);
                }
                $fields[] = 'transport_fee_paid = ?';
                $values[] = !empty($data['transport_fee_paid']) ? 1 : 0;
                $fields[] = 'paid_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END';
                $values[] = !empty($data['transport_fee_paid']) ? 1 : 0;
            }
            if (!$fields) {
                respond(['ok' => false, 'error' => 'No operation supplied'], 400);
            }
            $values[] = $registrationId;
            $stmt = db()->prepare('UPDATE volunteer_registrations SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($values);
            log_activity($user['id'], 'endeavour', (int)$registration['endeavour_id'], 'volunteering_ops_updated', 'Volunteering operation updated', ['registration_id' => $registrationId]);
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }

    if ($method === 'GET' && !$id && !$action) {
        $user = require_auth();
        $params = [];
        $filters = [];
        if (!empty($_GET['entity_id'])) {
            $entityId = (int)$_GET['entity_id'];
            require_permission('endeavour.view', $entityId, $user);
            $filters[] = 'e.entity_id = ?';
            $params[] = $entityId;
        } elseif (!rbac_has_global_permission($user, 'endeavour.view')) {
            $entityIds = rbac_entity_ids_for_permission($user, 'endeavour.view');
            if (!$entityIds) {
                respond(['ok' => true, 'data' => [], 'meta' => ['user' => $user]]);
            }
            $filters[] = 'e.entity_id IN (' . implode(',', array_fill(0, count($entityIds), '?')) . ')';
            array_push($params, ...$entityIds);
        }
        if (empty($_GET['include_completed'])) {
            $filters[] = 'e.phase <> "COMPLETED" AND e.status <> "completed"';
        }
        if (!empty($_GET['status'])) {
            $allowedStatuses = [
                'draft',
                'pending_board_approval',
                'board_approved_ops_plan_required',
                'ops_plan_pending_board_approval',
                'ops_plan_approved_mou_optional',
                'mou_pending_board_approval',
                'mou_approved_pre_financial_required',
                'pre_financial_pending_board_approval',
                'finance_approved_hr_posting_optional',
                'volunteer_posting_pending_board_approval',
                'volunteer_posting_approved_hr_publish',
                'live_volunteer_posting',
                'post_financial_pending_board_approval',
                'closed_ops_epilogue_required',
                'completed',
                'rejected'
            ];
            if (!in_array($_GET['status'], $allowedStatuses, true)) {
                respond(['ok' => false, 'error' => 'Invalid status filter'], 400);
            }
            $filters[] = 'e.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['phase'])) {
            $allowedPhases = [
                'PRE_EVENT',
                'PRE_FINANCIAL',
                'VOLUNTEER_REGISTRATION',
                'VOLUNTEER_SHORTLISTING',
                'ON_DAY',
                'POST_EVENT',
                'COMPLETED'
            ];
            if (!in_array($_GET['phase'], $allowedPhases, true)) {
                respond(['ok' => false, 'error' => 'Invalid phase filter'], 400);
            }
            $filters[] = 'e.phase = ?';
            $params[] = $_GET['phase'];
        }
        $where = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $stmt = db()->prepare("SELECT e.*, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id {$where} ORDER BY e.created_at DESC");
        $stmt->execute($params);
        $rows = array_map(fn($row) => decorate_endeavour_row($row, $user), $stmt->fetchAll());
        respond(['ok' => true, 'data' => $rows, 'meta' => ['user' => $user]]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        if (empty($data['entity_id'])) {
            respond(['ok' => false, 'error' => 'entity_id is required'], 400);
        }
        $entityId = (int)$data['entity_id'];
        $user = require_permission('endeavour.create', $entityId, null, 'Entity access denied');
        $name = require_non_empty($data['title'] ?? ($data['name'] ?? ''), 'title', 190);
        $description = require_non_empty($data['description'] ?? '', 'description', 5000);
        $venue = require_non_empty($data['venue'] ?? '', 'venue', 190);
        $eventStart = validate_datetime($data['event_start_at'] ?? ($data['start_at'] ?? null), 'event_start_at');
        $eventEnd = validate_datetime($data['event_end_at'] ?? ($data['end_at'] ?? null), 'event_end_at');
        if (!$eventStart || !$eventEnd) {
            respond(['ok' => false, 'error' => 'event_start_at and event_end_at are required'], 400);
        }
        if ($eventStart && $eventEnd && strtotime($eventEnd) < strtotime($eventStart)) {
            respond(['ok' => false, 'error' => 'event_end_at must not be before event_start_at'], 400);
        }
        validate_endeavour_date_business_rules($eventStart, $eventEnd, $data, can_permission($user, 'endeavour.manage_periods'));
        $startDate = substr($eventStart, 0, 10);
        $endDate = substr($eventEnd, 0, 10);
        $transportEnabled = !empty($data['transport_fee_required']);
        $transportAmount = $transportEnabled ? normalize_money($data['transport_fee_amount'] ?? ($data['transport_payment_required'] ?? null), 'transport_fee_amount') : null;
        $stmt = db()->prepare('INSERT INTO endeavours (public_id, entity_id, created_by, name, type_id, description, long_description, venue, schedule, start_date, end_date, transport_payment_required, phase, volunteering_enabled, transport_fee_required, transport_fee_amount, volunteer_registration_deadline, pre_financial_deadline, post_financial_deadline, event_start_at, event_end_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            generate_public_id('end'),
            $entityId,
            $user['id'],
            $name,
            $data['type_id'] ?? null,
            sanitize_text($description, 5000),
            sanitize_text($data['long_description'] ?? $description, 10000),
            sanitize_text($venue, 190),
            sanitize_text($data['schedule'] ?? '', 500),
            $startDate,
            $endDate,
            $transportAmount ?? 0,
            'PRE_EVENT',
            !empty($data['volunteering_enabled']) ? 1 : 0,
            $transportEnabled ? 1 : 0,
            $transportAmount,
            validate_datetime($data['volunteer_registration_deadline'] ?? null, 'volunteer_registration_deadline'),
            date('Y-m-d H:i:s', strtotime($eventStart . ' -48 hours')),
            date('Y-m-d H:i:s', strtotime($eventEnd . ' +72 hours')),
            $eventStart,
            $eventEnd,
            'draft'
        ]);
        $endeavourId = (int)db()->lastInsertId();
        log_activity($user['id'], 'endeavour', $endeavourId, 'created', 'Executive created endeavour', ['phase' => 'PRE_EVENT']);
        connect_enqueue_entitlement_change_safely((int)$user['id'], 'project_created', ['endeavour_id' => $endeavourId]);
        emit_ws_event('endeavour.created', ['id' => $endeavourId]);
        respond(['ok' => true, 'data' => ['id' => $endeavourId, 'public_id' => public_id_for_row('endeavours', $endeavourId)]]);
    }

    if ($method === 'GET' && $id && !$action) {
        $user = require_auth();
        $stmt = db()->prepare('SELECT e.*, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id WHERE e.id = ?');
        $stmt->execute([$id]);
        $endeavour = $stmt->fetch();
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        require_permission('endeavour.view', (int)$endeavour['entity_id'], $user);
        $endeavour = decorate_endeavour_row($endeavour, $user);
        $docsStmt = db()->prepare('SELECT ed.*, u.full_name FROM endeavour_documents ed JOIN users u ON ed.uploaded_by = u.id WHERE ed.endeavour_id = ? ORDER BY ed.uploaded_at DESC');
        $docsStmt->execute([$id]);
        $postsStmt = db()->prepare('SELECT vp.* FROM volunteer_posts vp WHERE vp.endeavour_id = ? ORDER BY vp.created_at DESC');
        $postsStmt->execute([$id]);
        $appsStmt = db()->prepare('SELECT va.*, u.full_name, s.student_id FROM volunteer_applications va JOIN students s ON va.student_id = s.id JOIN users u ON s.user_id = u.id WHERE va.volunteer_post_id IN (SELECT id FROM volunteer_posts WHERE endeavour_id = ?) ORDER BY va.created_at DESC');
        $appsStmt->execute([$id]);
        $appIdStmt = db()->prepare('SELECT va.id FROM volunteer_applications va JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE vp.endeavour_id = ?');
        $appIdStmt->execute([$id]);
        $applicationIds = array_map(fn($row) => (int)$row['id'], $appIdStmt->fetchAll());
        $payments = [];
        $attendance = [];
        $consents = [];
        if ($applicationIds) {
            $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
            $paymentsStmt = db()->prepare("SELECT * FROM payments WHERE volunteer_application_id IN ({$placeholders})");
            $paymentsStmt->execute($applicationIds);
            $payments = $paymentsStmt->fetchAll();
            $attendanceStmt = db()->prepare("SELECT * FROM attendance WHERE volunteer_application_id IN ({$placeholders})");
            $attendanceStmt->execute($applicationIds);
            $attendance = $attendanceStmt->fetchAll();
            $consentStmt = db()->prepare("SELECT * FROM consents WHERE volunteer_application_id IN ({$placeholders})");
            $consentStmt->execute($applicationIds);
            $consents = $consentStmt->fetchAll();
        }
        $docApprovalStmt = db()->prepare('SELECT * FROM endeavour_doc_approvals WHERE endeavour_id = ?');
        $docApprovalStmt->execute([$id]);
        $registrationsStmt = db()->prepare('SELECT vr.*, u.full_name FROM volunteer_registrations vr JOIN users u ON vr.user_id = u.id WHERE vr.endeavour_id = ? ORDER BY vr.registered_at DESC');
        $registrationsStmt->execute([$id]);
        $activity = db()->prepare('SELECT a.*, u.full_name FROM activity_log a LEFT JOIN users u ON a.actor_id = u.id WHERE entity_type = "endeavour" AND entity_id = ? ORDER BY a.created_at DESC');
        $activity->execute([$id]);
        respond(['ok' => true, 'data' => ['endeavour' => $endeavour, 'documents' => $docsStmt->fetchAll(), 'submissions' => endeavour_submissions_for_response($id, $user), 'posts' => $postsStmt->fetchAll(), 'applications' => $appsStmt->fetchAll(), 'payments' => $payments, 'attendance' => $attendance, 'consents' => $consents, 'doc_approvals' => $docApprovalStmt->fetchAll(), 'registrations' => $registrationsStmt->fetchAll(), 'activity' => $activity->fetchAll()], 'meta' => ['user' => $user]]);
    }

    if ($method === 'PUT' && $id && !$action) {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.edit', (int)$endeavour['entity_id']);
        $data = read_json();
        $payload = normalize_endeavour_update_payload($endeavour, $data);
        if (!$payload['event_start_at'] || !$payload['event_end_at']) {
            respond(['ok' => false, 'error' => 'event_start_at and event_end_at are required'], 400);
        }
        validate_endeavour_date_business_rules(
            $payload['event_start_at'],
            $payload['event_end_at'],
            $payload,
            can_permission($user, 'endeavour.manage_periods', (int)$endeavour['entity_id'])
        );
        if (endeavour_edit_requires_approval($endeavour, $payload) && !can_permission($user, 'endeavour.approve_edit')) {
            $stmt = db()->prepare('INSERT INTO endeavour_edit_approvals (endeavour_id, requested_by, requested_payload, status) VALUES (?, ?, ?, "pending")');
            $stmt->execute([$id, $user['id'], json_encode($payload)]);
            $approvalId = (int)db()->lastInsertId();
            db()->prepare('UPDATE endeavours SET edit_approval_status = "pending", edit_pending_payload = ?, edit_requested_by = ?, edit_requested_at = NOW() WHERE id = ?')
                ->execute([json_encode($payload), $user['id'], $id]);
            log_activity($user['id'], 'endeavour', $id, 'edit_requested', 'Endeavour edit requires approval', ['approval_id' => $approvalId]);
            respond(['ok' => true, 'data' => ['id' => $id, 'edit_approval_required' => true, 'approval_id' => $approvalId]]);
        }
        apply_endeavour_update_payload($id, $payload);
        log_activity($user['id'], 'endeavour', $id, 'updated', 'Endeavour updated');
        respond(['ok' => true, 'data' => ['id' => $id]]);
    }

    if ($id && $action === 'register' && $method === 'POST') {
        $user = require_auth();
        require_permission('volunteering.register', null, $user);
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        if ($endeavour['phase'] !== 'VOLUNTEER_REGISTRATION' || !(int)$endeavour['volunteering_enabled']) {
            respond(['ok' => false, 'error' => 'Volunteer registration is closed'], 400);
        }
        if (!empty($endeavour['volunteer_registration_deadline']) && strtotime($endeavour['volunteer_registration_deadline']) < time()) {
            respond(['ok' => false, 'error' => 'Volunteer registration deadline has passed'], 400);
        }
        $stmt = db()->prepare('INSERT INTO volunteer_registrations (endeavour_id, entity_id, user_id) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$id, (int)$endeavour['entity_id'], $user['id']]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respond(['ok' => true, 'data' => ['registered' => true]]);
            }
            throw $e;
        }
        log_activity($user['id'], 'endeavour', $id, 'registered', 'Volunteer registered');
        connect_enqueue_entitlement_change_safely((int)$user['id'], 'project_registration_created', ['endeavour_id' => $id]);
        respond(['ok' => true, 'data' => ['registered' => true]]);
    }

    if ($id && $action === 'attach_plans' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.submit_docs', (int)$endeavour['entity_id']);
        $data = read_json();
        $opsId = (int)($data['operational_plan_file_id'] ?? 0);
        $budgetId = (int)($data['budget_plan_file_id'] ?? 0);
        if ($opsId <= 0 || $budgetId <= 0) {
            respond(['ok' => false, 'error' => 'Operational and budget plan files are required'], 400);
        }
        ensure_drive_file((int)$endeavour['entity_id'], $opsId);
        ensure_drive_file((int)$endeavour['entity_id'], $budgetId);
        $stmt = db()->prepare('UPDATE endeavours SET operational_plan_file_id = ?, budget_plan_file_id = ? WHERE id = ?');
        $stmt->execute([$opsId, $budgetId, $id]);
        create_endeavour_submission($endeavour, 'operational_plan', $opsId, $user);
        create_endeavour_submission($endeavour, 'budget_plan', $budgetId, $user);
        seed_doc_approvals($id, ['operational_plan', 'budget_plan']);
        notify_submission_approvers($id, (int)$endeavour['entity_id'], 'operational_plan');
        notify_submission_approvers($id, (int)$endeavour['entity_id'], 'budget_plan');
        log_activity($user['id'], 'endeavour', $id, 'plans_attached', 'Operational and budget plans attached');
        respond(['ok' => true]);
    }

    if ($id && $action === 'attach_pre_financial' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.submit_docs', (int)$endeavour['entity_id']);
        $data = read_json();
        $fileId = (int)($data['pre_financial_file_id'] ?? 0);
        if ($fileId <= 0) {
            respond(['ok' => false, 'error' => 'pre_financial_file_id required'], 400);
        }
        ensure_drive_file((int)$endeavour['entity_id'], $fileId);
        $phase = $endeavour['phase'] ?? 'PRE_EVENT';
        if (phase_precedes($phase, 'PRE_FINANCIAL')) {
            $stmt = db()->prepare('UPDATE endeavours SET pre_financial_file_id = ?, phase = "PRE_FINANCIAL" WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        } else {
            $stmt = db()->prepare('UPDATE endeavours SET pre_financial_file_id = ? WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        }
        create_endeavour_submission($endeavour, 'pre_financial', $fileId, $user);
        seed_doc_approvals($id, ['pre_financial']);
        notify_submission_approvers($id, (int)$endeavour['entity_id'], 'pre_financial');
        log_activity($user['id'], 'endeavour', $id, 'pre_financial_attached', 'Pre-financial document attached');
        respond(['ok' => true]);
    }

    if ($id && $action === 'attach_post_financial' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.submit_docs', (int)$endeavour['entity_id']);
        $data = read_json();
        $fileId = (int)($data['post_financial_file_id'] ?? 0);
        if ($fileId <= 0) {
            respond(['ok' => false, 'error' => 'post_financial_file_id required'], 400);
        }
        ensure_drive_file((int)$endeavour['entity_id'], $fileId);
        $phase = $endeavour['phase'] ?? 'PRE_EVENT';
        if (phase_precedes($phase, 'POST_EVENT')) {
            $stmt = db()->prepare('UPDATE endeavours SET post_financial_file_id = ?, phase = "POST_EVENT" WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        } else {
            $stmt = db()->prepare('UPDATE endeavours SET post_financial_file_id = ? WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        }
        create_endeavour_submission($endeavour, 'post_financial', $fileId, $user);
        seed_doc_approvals($id, ['post_financial']);
        notify_submission_approvers($id, (int)$endeavour['entity_id'], 'post_financial');
        log_activity($user['id'], 'endeavour', $id, 'post_financial_attached', 'Post-financial document attached');
        respond(['ok' => true]);
    }

    if ($id && $action === 'attach_epilogue' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.submit_docs', (int)$endeavour['entity_id']);
        $data = read_json();
        $fileId = (int)($data['epilogue_file_id'] ?? 0);
        if ($fileId <= 0) {
            respond(['ok' => false, 'error' => 'epilogue_file_id required'], 400);
        }
        ensure_drive_file((int)$endeavour['entity_id'], $fileId);
        $phase = $endeavour['phase'] ?? 'PRE_EVENT';
        if (phase_precedes($phase, 'POST_EVENT')) {
            $stmt = db()->prepare('UPDATE endeavours SET epilogue_file_id = ?, phase = "POST_EVENT" WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        } else {
            $stmt = db()->prepare('UPDATE endeavours SET epilogue_file_id = ? WHERE id = ?');
            $stmt->execute([$fileId, $id]);
        }
        create_endeavour_submission($endeavour, 'epilogue', $fileId, $user);
        log_activity($user['id'], 'endeavour', $id, 'epilogue_attached', 'Epilogue document attached');
        respond(['ok' => true]);
    }

    if ($id && $action === 'submissions') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $submissionId = isset($segments[3]) && is_numeric($segments[3]) ? (int)$segments[3] : null;
        $submissionAction = $segments[4] ?? null;

        if ($method === 'GET' && !$submissionId) {
            $user = require_permission('endeavour.view', (int)$endeavour['entity_id']);
            respond(['ok' => true, 'data' => endeavour_submissions_for_response($id, $user)]);
        }

        if ($method === 'POST' && !$submissionId) {
            $user = require_permission('endeavour.submit_docs', (int)$endeavour['entity_id']);
            $data = read_json();
            $docType = normalize_submission_doc_type($data['doc_type'] ?? '');
            $fileId = (int)($data['file_drive_item_id'] ?? ($data['file_id'] ?? 0));
            if ($fileId <= 0) {
                respond(['ok' => false, 'error' => 'file_drive_item_id required'], 400);
            }
            ensure_drive_file((int)$endeavour['entity_id'], $fileId);
            $submission = create_endeavour_submission($endeavour, $docType, $fileId, $user);
            update_endeavour_latest_file((int)$endeavour['id'], $docType, $fileId);
            if ($docType !== 'epilogue') {
                seed_doc_approvals($id, [$docType]);
                notify_submission_approvers($id, (int)$endeavour['entity_id'], $docType);
            }
            log_activity($user['id'], 'endeavour', $id, 'submission_created', 'Endeavour submission created', ['doc_type' => $docType, 'submission_id' => $submission['id']]);
            respond(['ok' => true, 'data' => $submission]);
        }

        if ($method === 'POST' && $submissionId && $submissionAction === 'approve') {
            handle_endeavour_submission_approval($endeavour, $submissionId);
        }

        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }

    if ($id && $action === 'edit_approvals' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('endeavour.approve_edit', (int)$endeavour['entity_id']);
        $approvalId = isset($segments[3]) ? (int)$segments[3] : 0;
        if ($approvalId <= 0) {
            respond(['ok' => false, 'error' => 'approval id required'], 400);
        }
        $data = read_json();
        $decision = $data['decision'] ?? '';
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            respond(['ok' => false, 'error' => 'Valid decision required'], 400);
        }
        $stmt = db()->prepare('SELECT * FROM endeavour_edit_approvals WHERE id = ? AND endeavour_id = ? AND status = "pending"');
        $stmt->execute([$approvalId, $id]);
        $approval = $stmt->fetch();
        if (!$approval) {
            respond(['ok' => false, 'error' => 'Edit approval not found'], 404);
        }
        $comment = sanitize_text($data['comment'] ?? '', 1000);
        if ($decision === 'rejected' && $comment === '') {
            respond(['ok' => false, 'error' => 'Rejection comments are required'], 400);
        }
        if ($decision === 'approved') {
            $payload = json_decode((string)$approval['requested_payload'], true);
            if (!is_array($payload)) {
                respond(['ok' => false, 'error' => 'Invalid edit payload'], 500);
            }
            apply_endeavour_update_payload($id, $payload);
        } else {
            db()->prepare('UPDATE endeavours SET edit_approval_status = "rejected" WHERE id = ?')->execute([$id]);
        }
        db()->prepare('UPDATE endeavour_edit_approvals SET status = ?, comment = ?, decided_by = ?, decided_at = NOW() WHERE id = ?')
            ->execute([$decision, $comment, $user['id'], $approvalId]);
        log_activity($user['id'], 'endeavour', $id, $decision === 'approved' ? 'edit_approved' : 'edit_rejected', 'Endeavour edit decision recorded', ['approval_id' => $approvalId]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'doc_approvals' && $method === 'POST') {
        $user = require_auth();
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $data = read_json();
        $docType = $data['doc_type'] ?? '';
        $decision = $data['decision'] ?? '';
        $approverGroup = resolve_approver_group($user, $data['approver_group'] ?? null);
        $allowedDocs = ['operational_plan', 'budget_plan', 'pre_financial', 'post_financial', 'epilogue'];
        if (!in_array($docType, $allowedDocs, true)) {
            respond(['ok' => false, 'error' => 'Invalid doc_type'], 400);
        }
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            respond(['ok' => false, 'error' => 'Invalid decision'], 400);
        }
        if (!$approverGroup) {
            respond(['ok' => false, 'error' => 'Approver role required'], 403);
        }
        $comment = sanitize_text($data['comment'] ?? '', 1000);
        if ($decision === 'rejected' && $comment === '') {
            respond(['ok' => false, 'error' => 'Rejection comments are required'], 400);
        }
        if ($approverGroup === 'bod') {
            require_permission('endeavour.approve_mob', (int)$endeavour['entity_id'], $user);
        } elseif ($approverGroup === 'student_affairs') {
            require_permission('endeavour.approve_sa', (int)$endeavour['entity_id'], $user);
        }
        $latestSubmission = latest_endeavour_submission($id, $docType);
        if ($latestSubmission) {
            record_endeavour_submission_approval($latestSubmission, $approverGroup === 'bod' ? 'mob' : 'student_affairs', $decision, $comment, $user);
        }
        $stmt = db()->prepare('INSERT INTO endeavour_doc_approvals (endeavour_id, doc_type, approver_group, status, approver_user_id, comment) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), approver_user_id = VALUES(approver_user_id), comment = VALUES(comment)');
        $stmt->execute([$id, $docType, $approverGroup, $decision, $user['id'], $comment]);
        $logAction = $decision === 'rejected' ? 'doc_rejected' : 'doc_approved';
        log_activity($user['id'], 'endeavour', $id, $logAction, 'Document approval updated', ['doc_type' => $docType, 'decision' => $decision, 'group' => $approverGroup]);
        evaluate_phase_transition($id);
        respond(['ok' => true]);
    }

    if ($id && $action === 'start_shortlisting' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('volunteering.shortlist', (int)$endeavour['entity_id']);
        if ($endeavour['phase'] !== 'VOLUNTEER_REGISTRATION') {
            respond(['ok' => false, 'error' => 'Volunteer registration not active'], 400);
        }
        update_phase($id, 'VOLUNTEER_SHORTLISTING');
        log_activity($user['id'], 'endeavour', $id, 'shortlisting_started', 'Volunteer shortlisting started');
        respond(['ok' => true]);
    }

    if ($id && $action === 'close_shortlisting' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_permission('volunteering.shortlist', (int)$endeavour['entity_id']);
        if ($endeavour['phase'] !== 'VOLUNTEER_SHORTLISTING') {
            respond(['ok' => false, 'error' => 'Shortlisting not active'], 400);
        }
        update_phase($id, 'ON_DAY');
        notify_shortlisted($id, (int)$endeavour['entity_id']);
        log_activity($user['id'], 'endeavour', $id, 'shortlisting_closed', 'Volunteer shortlisting closed');
        respond(['ok' => true]);
    }

    if ($id && $action === 'registrations' && $method === 'GET') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        require_permission('volunteering.shortlist', (int)$endeavour['entity_id']);
        $stmt = db()->prepare(
            'SELECT vr.*, u.full_name, u.email
             FROM volunteer_registrations vr
             JOIN users u ON vr.user_id = u.id
             WHERE vr.endeavour_id = ?
             ORDER BY vr.registered_at DESC'
        );
        $stmt->execute([$id]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($id && $action === 'registrations' && $method === 'POST') {
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $user = require_auth();
        $subAction = $segments[3] ?? '';
        $data = read_json();
        $registrationId = (int)($data['registration_id'] ?? 0);
        if ($registrationId <= 0) {
            respond(['ok' => false, 'error' => 'registration_id required'], 400);
        }
        $check = db()->prepare('SELECT id, user_id FROM volunteer_registrations WHERE id = ? AND endeavour_id = ?');
        $check->execute([$registrationId, $id]);
        $registration = $check->fetch();
        if (!$registration) {
            respond(['ok' => false, 'error' => 'Registration not found'], 404);
        }
        if ($subAction === 'shortlist') {
            if ($endeavour['phase'] !== 'VOLUNTEER_SHORTLISTING') {
                respond(['ok' => false, 'error' => 'Shortlisting not active'], 400);
            }
            require_permission('volunteering.shortlist', (int)$endeavour['entity_id'], $user);
            $stmt = db()->prepare('UPDATE volunteer_registrations SET status = "shortlisted" WHERE id = ?');
            $stmt->execute([$registrationId]);
            connect_enqueue_entitlement_change_safely((int)$registration['user_id'], 'project_registration_shortlisted', ['endeavour_id' => $id, 'registration_id' => $registrationId, 'actor_id' => (int)$user['id']]);
            respond(['ok' => true]);
        }
        if ($subAction === 'reject') {
            if ($endeavour['phase'] !== 'VOLUNTEER_SHORTLISTING') {
                respond(['ok' => false, 'error' => 'Shortlisting not active'], 400);
            }
            require_permission('volunteering.shortlist', (int)$endeavour['entity_id'], $user);
            $stmt = db()->prepare('UPDATE volunteer_registrations SET status = "rejected" WHERE id = ?');
            $stmt->execute([$registrationId]);
            connect_enqueue_entitlement_change_safely((int)$registration['user_id'], 'project_registration_rejected', ['endeavour_id' => $id, 'registration_id' => $registrationId, 'actor_id' => (int)$user['id']]);
            respond(['ok' => true]);
        }
        if ($subAction === 'attendance') {
            require_permission('volunteering.ops', null, $user);
            if ($endeavour['phase'] !== 'ON_DAY') {
                respond(['ok' => false, 'error' => 'On-day attendance not open'], 400);
            }
            $status = $data['attendance_status'] ?? '';
            if (!in_array($status, ['present', 'absent'], true)) {
                respond(['ok' => false, 'error' => 'Invalid attendance_status'], 400);
            }
            $stmt = db()->prepare('UPDATE volunteer_registrations SET attendance_status = ? WHERE id = ?');
            $stmt->execute([$status, $registrationId]);
            respond(['ok' => true]);
        }
        if ($subAction === 'transport_fee') {
            require_permission('volunteering.ops', null, $user);
            if ($endeavour['phase'] !== 'ON_DAY') {
                respond(['ok' => false, 'error' => 'Transport fee marking not open'], 400);
            }
            if (!(int)$endeavour['transport_fee_required']) {
                respond(['ok' => false, 'error' => 'Transport fee not required'], 400);
            }
            $stmt = db()->prepare('UPDATE volunteer_registrations SET transport_fee_paid = 1, paid_at = NOW() WHERE id = ?');
            $stmt->execute([$registrationId]);
            respond(['ok' => true]);
        }
        respond(['ok' => false, 'error' => 'Invalid registration action'], 400);
    }

    if ($id && in_array($action, ['submit_ops_plan', 'submit_operational_plan'], true) && $method === 'POST') {
        $user = require_permission('endeavour.submit_docs', fetch_entity_id($id));
        handle_doc_upload($id, 'ops_plan', 'ops_plan_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_mou' && $method === 'POST') {
        $user = require_permission('endeavour.submit_docs', fetch_entity_id($id));
        handle_doc_upload($id, 'mou', 'mou_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_pre_financial' && $method === 'POST') {
        $user = require_permission('endeavour.submit_docs', fetch_entity_id($id));
        handle_doc_upload($id, 'pre_financial', 'pre_financial_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_post_financial' && $method === 'POST') {
        $user = require_permission('endeavour.submit_docs', fetch_entity_id($id));
        handle_doc_upload($id, 'post_financial', 'post_financial_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_epilogue' && $method === 'POST') {
        $user = require_permission('endeavour.submit_docs', fetch_entity_id($id));
        handle_doc_upload($id, 'epilogue', 'completed', $user['id'], false);
    }

    if ($id && $action === 'approve' && $method === 'POST') {
        handle_approval($id);
    }

    if ($id && $action === 'request_post_to_feed' && $method === 'POST') {
        $user = require_permission('volunteering.shortlist', fetch_entity_id($id));
        $data = read_json();
        $description = require_non_empty($data['description'] ?? '', 'description', 2000);
        $eligibility = sanitize_text($data['eligibility_notes'] ?? '', 1000);
        $venue = sanitize_text($data['venue'] ?? '', 190);
        $schedule = sanitize_text($data['schedule'] ?? '', 500);
        $stmt = db()->prepare('INSERT INTO volunteer_posts (endeavour_id, description, eligibility_notes, venue, schedule, transport_payment, questionnaire_mode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $id,
            $description,
            $eligibility,
            $venue,
            $schedule,
            $data['transport_payment'] ?? 0,
            $data['questionnaire_mode'] ?? 0,
            $user['id']
        ]);
        update_status($id, 'volunteer_posting_pending_board_approval');
        log_activity($user['id'], 'endeavour', $id, 'post_requested', 'Volunteer posting requested');
        emit_ws_event('endeavour.post_requested', ['id' => $id]);
        respond(['ok' => true, 'data' => ['post_id' => (int)db()->lastInsertId()]]);
    }

    if ($id && $action === 'publish_post' && $method === 'POST') {
        $user = require_permission('volunteering.shortlist', fetch_entity_id($id));
        $data = read_json();
        if (empty($data['post_id']) || !is_numeric($data['post_id']) || (int)$data['post_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Valid post_id required'], 400);
        }
        $postId = (int)$data['post_id'];
        $postCheck = db()->prepare('SELECT id FROM volunteer_posts WHERE id = ? AND endeavour_id = ?');
        $postCheck->execute([$postId, $id]);
        if (!$postCheck->fetch()) {
            respond(['ok' => false, 'error' => 'Volunteer post not found'], 404);
        }
        $stmt = db()->prepare('UPDATE volunteer_posts SET published = 1, published_at = NOW() WHERE id = ?');
        $stmt->execute([$postId]);
        update_status($id, 'live_volunteer_posting');
        log_activity($user['id'], 'endeavour', $id, 'post_published', 'Volunteer posting published');
        emit_ws_event('endeavour.post_published', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'applications' && $method === 'GET') {
        $entityId = fetch_entity_id($id);
        $user = require_permission('volunteering.shortlist', $entityId);
        $stmt = db()->prepare('SELECT va.*, s.student_id, u.full_name FROM volunteer_applications va JOIN students s ON va.student_id = s.id JOIN users u ON s.user_id = u.id WHERE va.volunteer_post_id IN (SELECT id FROM volunteer_posts WHERE endeavour_id = ?)');
        $stmt->execute([$id]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($id && $action === 'applications' && $method === 'POST') {
        $user = require_permission('volunteering.register');
        $data = read_json();
        if (empty($data['post_id']) || !is_numeric($data['post_id'])) {
            respond(['ok' => false, 'error' => 'post_id required'], 400);
        }
        $postId = (int)$data['post_id'];
        if ($postId <= 0) {
            respond(['ok' => false, 'error' => 'Invalid post_id'], 400);
        }
        $postCheck = db()->prepare('SELECT id FROM volunteer_posts WHERE id = ? AND endeavour_id = ?');
        $postCheck->execute([$postId, $id]);
        if (!$postCheck->fetch()) {
            respond(['ok' => false, 'error' => 'Volunteer post not found'], 404);
        }
        $stmt = db()->prepare('SELECT id FROM students WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $student = $stmt->fetch();
        if (!$student) {
            respond(['ok' => false, 'error' => 'Student profile required'], 400);
        }
        $attendanceDate = $data['attendance_date'] ?? null;
        if ($attendanceDate !== null) {
            $dt = DateTime::createFromFormat('Y-m-d', $attendanceDate);
            if (!$dt || $dt->format('Y-m-d') !== $attendanceDate) {
                respond(['ok' => false, 'error' => 'Invalid attendance_date format (expected Y-m-d)'], 400);
            }
        }
        $stmt = db()->prepare('INSERT INTO volunteer_applications (volunteer_post_id, student_id, answers_json) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $student['id'], json_encode($data['answers'] ?? [])]);
        $applicationId = (int)db()->lastInsertId();
        $payment = db()->prepare('INSERT INTO payments (volunteer_application_id, transport_payment_due) VALUES (?, ?)');
        $payment->execute([$applicationId, $data['transport_payment_due'] ?? 0]);
        $attendance = db()->prepare('INSERT INTO attendance (volunteer_application_id, attendance_date) VALUES (?, ?)');
        $attendance->execute([$applicationId, $attendanceDate]);
        emit_ws_event('endeavour.application_submitted', ['id' => $id]);
        respond(['ok' => true, 'data' => ['id' => $applicationId]]);
    }

    if ($id && $action === 'shortlist' && $method === 'POST') {
        $user = require_permission('volunteering.shortlist', fetch_entity_id($id));
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id']) || (int)$data['application_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Invalid application_id'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $check = db()->prepare('SELECT va.id FROM volunteer_applications va JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE va.id = ? AND vp.endeavour_id = ?');
        $check->execute([$applicationId, $id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Application not found for endeavour'], 404);
        }
        $stmt = db()->prepare('INSERT INTO shortlists (volunteer_application_id, shortlisted_by) VALUES (?, ?)');
        $stmt->execute([$applicationId, $user['id']]);
        $update = db()->prepare('UPDATE volunteer_applications SET status = "shortlisted" WHERE id = ?');
        $update->execute([$applicationId]);
        log_activity($user['id'], 'endeavour', $id, 'shortlisted', 'Application shortlisted', ['application_id' => $applicationId]);
        emit_ws_event('endeavour.shortlisted', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'consent' && ($segments[3] ?? '') === 'request' && $method === 'POST') {
        $user = require_permission('volunteering.shortlist', fetch_entity_id($id));
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id'])) {
            respond(['ok' => false, 'error' => 'application_id required'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $check = db()->prepare('SELECT va.id, s.parent_email, s.parent_email_secondary FROM volunteer_applications va JOIN students s ON va.student_id = s.id JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE va.id = ? AND vp.endeavour_id = ?');
        $check->execute([$applicationId, $id]);
        $row = $check->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Application not found'], 404);
        }
        $parentEmail = $row['parent_email'] ?: $row['parent_email_secondary'];
        if (!$parentEmail) {
            respond(['ok' => false, 'error' => 'Parent email missing'], 400);
        }
        $token = bin2hex(random_bytes(24));
        $existing = db()->prepare('SELECT id, token FROM consents WHERE volunteer_application_id = ? AND status = "pending"');
        $existing->execute([$applicationId]);
        $consent = $existing->fetch();
        if ($consent) {
            $token = $consent['token'];
        } else {
            $insert = db()->prepare('INSERT INTO consents (volunteer_application_id, parent_email, token) VALUES (?, ?, ?)');
            $insert->execute([$applicationId, $parentEmail, $token]);
        }
        $link = base_url() . '/consent.html?token=' . urlencode($token) . '&endeavour_id=' . urlencode((string)$id);
        $body = '<p>Please sign the parent consent for the Nixor endeavour.</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Sign consent</a></p>';
        send_email($parentEmail, 'Parent Consent Required', $body, true);
        log_activity($user['id'], 'endeavour', $id, 'consent_requested', 'Consent requested', ['application_id' => $applicationId]);
        emit_ws_event('endeavour.consent_requested', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'consent' && $method === 'POST') {
        if (!rate_limit('consent', 5, 60)) {
            respond(['ok' => false, 'error' => 'Too many requests'], 429);
        }
        $data = read_json();
        // Consent tokens must be generated with >=32 bytes of entropy.
        if (empty($data['token']) || !preg_match('/^[a-zA-Z0-9_-]{32,}$/', $data['token'])) {
            respond(['ok' => false, 'error' => 'Invalid token'], 400);
        }
        $check = db()->prepare('SELECT c.id FROM consents c JOIN volunteer_applications va ON c.volunteer_application_id = va.id JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE c.token = ? AND vp.endeavour_id = ?');
        $check->execute([$data['token'], $id]);
        $row = $check->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Consent not found'], 404);
        }
        $stmt = db()->prepare('UPDATE consents SET status = "signed", signed_at = NOW(), signature_name = ? WHERE id = ?');
        $stmt->execute([sanitize_text($data['signature_name'] ?? '', 190), $row['id']]);
        log_activity(null, 'endeavour', $id, 'consent_signed', 'Consent signed');
        emit_ws_event('endeavour.consent_signed', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'payment' && ($segments[3] ?? '') === 'mark_paid' && $method === 'POST') {
        $user = require_permission('volunteering.ops');
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id']) || (int)$data['application_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Invalid application_id'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $receiptRef = $data['receipt_ref'] ?? '';
        $check = db()->prepare('SELECT va.id FROM volunteer_applications va JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE va.id = ? AND vp.endeavour_id = ?');
        $check->execute([$applicationId, $id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Application not found for endeavour'], 404);
        }
        $stmt = db()->prepare('UPDATE payments SET paid_flag = 1, paid_by = ?, paid_at = NOW(), receipt_ref = ? WHERE volunteer_application_id = ?');
        $stmt->execute([$user['id'], $receiptRef, $applicationId]);
        log_activity($user['id'], 'endeavour', $id, 'payment_marked', 'Payment marked', ['application_id' => $applicationId]);
        emit_ws_event('endeavour.payment_marked', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'attendance' && ($segments[3] ?? '') === 'mark' && $method === 'POST') {
        $user = require_permission('volunteering.ops');
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id']) || (int)$data['application_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Invalid application_id'], 400);
        }
        $status = $data['status'] ?? '';
        if (!in_array($status, ['present', 'absent', 'pending'], true)) {
            respond(['ok' => false, 'error' => 'Invalid status'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        require_permission('volunteering.ops', (int)$endeavour['entity_id'], $user);
        $check = db()->prepare('SELECT va.id FROM volunteer_applications va JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE va.id = ? AND vp.endeavour_id = ?');
        $check->execute([$applicationId, $id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Application not found for endeavour'], 404);
        }
        $stmt = db()->prepare('UPDATE attendance SET status = ?, marked_by = ?, marked_at = NOW() WHERE volunteer_application_id = ?');
        $stmt->execute([$status, $user['id'], $applicationId]);
        log_activity($user['id'], 'endeavour', $id, 'attendance_marked', 'Attendance marked', ['application_id' => $applicationId, 'status' => $status]);
        emit_ws_event('endeavour.attendance_marked', ['id' => $id]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function fetch_entity_id(int $endeavourId): int {
    $stmt = db()->prepare('SELECT entity_id FROM endeavours WHERE id = ?');
    $stmt->execute([$endeavourId]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
    }
    return (int)$row['entity_id'];
}

function fetch_endeavour(int $endeavourId): ?array {
    $stmt = db()->prepare('SELECT * FROM endeavours WHERE id = ?');
    $stmt->execute([$endeavourId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function normalize_money($value, string $field): float {
    if ($value === null || $value === '') {
        respond(['ok' => false, 'error' => "{$field} required"], 400);
    }
    if (!is_numeric($value)) {
        respond(['ok' => false, 'error' => "{$field} must be numeric"], 400);
    }
    $amount = round((float)$value, 2);
    if ($amount < 0) {
        respond(['ok' => false, 'error' => "{$field} must not be negative"], 400);
    }
    return $amount;
}

function normalize_submission_doc_type(string $docType): string {
    $map = [
        'ops_plan' => 'operational_plan',
        'operational' => 'operational_plan',
        'budget' => 'budget_plan',
        'pre' => 'pre_financial',
        'post' => 'post_financial',
    ];
    $docType = $map[$docType] ?? $docType;
    $allowed = ['operational_plan', 'budget_plan', 'pre_financial', 'post_financial', 'epilogue'];
    if (!in_array($docType, $allowed, true)) {
        respond(['ok' => false, 'error' => 'Invalid doc_type'], 400);
    }
    return $docType;
}

function validate_endeavour_date_business_rules(string $eventStart, string $eventEnd, array $data, bool $bypass): void {
    if (!$bypass && !empty($data['volunteering_enabled'])) {
        $deadline = validate_datetime($data['volunteer_registration_deadline'] ?? null, 'volunteer_registration_deadline');
        if (!$deadline) {
            respond(['ok' => false, 'error' => 'volunteer_registration_deadline is required when volunteering is enabled'], 400);
        }
        if (strtotime($deadline) > strtotime($eventStart . ' -48 hours')) {
            respond(['ok' => false, 'error' => 'Volunteering signup deadline must be at least 48 hours before event start'], 400);
        }
    }
    if ($bypass) {
        return;
    }
    $period = corporate_period_for_datetime($eventStart);
    if ($period && strtotime($eventEnd) > strtotime($period['ends_at'])) {
        respond(['ok' => false, 'error' => 'Event end cannot exceed the Corporate Period end date'], 400);
    }
}

function corporate_period_for_datetime(string $dateTime): ?array {
    try {
        $stmt = db()->prepare('SELECT * FROM corporate_periods WHERE starts_at <= ? AND ends_at >= ? ORDER BY starts_at DESC LIMIT 1');
        $stmt->execute([$dateTime, $dateTime]);
        $period = $stmt->fetch();
        return $period ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function applicable_plan_deadline(array $endeavour, string $docType): ?string {
    $docType = normalize_submission_doc_type($docType);
    if ($docType === 'pre_financial') {
        return !empty($endeavour['event_start_at']) ? date('Y-m-d H:i:s', strtotime($endeavour['event_start_at'] . ' -48 hours')) : null;
    }
    if ($docType === 'post_financial') {
        return !empty($endeavour['event_end_at']) ? date('Y-m-d H:i:s', strtotime($endeavour['event_end_at'] . ' +72 hours')) : null;
    }
    if ($docType === 'epilogue') {
        return !empty($endeavour['event_end_at']) ? date('Y-m-d H:i:s', strtotime($endeavour['event_end_at'] . ' +48 hours')) : null;
    }
    if (!in_array($docType, ['operational_plan', 'budget_plan'], true) || empty($endeavour['event_start_at'])) {
        return null;
    }
    $period = corporate_period_for_datetime($endeavour['event_start_at']);
    if (!$period) {
        return null;
    }
    $stmt = db()->prepare('SELECT due_at FROM corporate_period_plan_deadlines WHERE corporate_period_id = ? AND doc_type = ? AND due_at >= NOW() ORDER BY due_at ASC LIMIT 1');
    $stmt->execute([(int)$period['id'], $docType]);
    $future = $stmt->fetchColumn();
    if ($future) {
        return (string)$future;
    }
    $stmt = db()->prepare('SELECT due_at FROM corporate_period_plan_deadlines WHERE corporate_period_id = ? AND doc_type = ? ORDER BY due_at DESC LIMIT 1');
    $stmt->execute([(int)$period['id'], $docType]);
    $past = $stmt->fetchColumn();
    return $past ? (string)$past : null;
}

function create_endeavour_submission(array $endeavour, string $docType, int $fileId, array $user): array {
    $docType = normalize_submission_doc_type($docType);
    $latest = latest_endeavour_submission((int)$endeavour['id'], $docType);
    $version = $latest ? ((int)$latest['version_no'] + 1) : 1;
    $dueAt = applicable_plan_deadline($endeavour, $docType);
    $resubmissionOf = ($latest && in_array($latest['status'], ['rejected', 'needs_resubmission'], true)) ? (int)$latest['id'] : null;
    $isOverdue = $dueAt && strtotime($dueAt) < time() && !$resubmissionOf ? 1 : 0;
    $status = $docType === 'epilogue' ? 'no_approval_required' : 'submitted';
    $stmt = db()->prepare('INSERT INTO endeavour_submissions (endeavour_id, doc_type, file_drive_item_id, version_no, submitted_by, due_at, is_overdue, status, resubmission_of_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)$endeavour['id'], $docType, $fileId, $version, $user['id'], $dueAt, $isOverdue, $status, $resubmissionOf]);
    $submissionId = (int)db()->lastInsertId();
    if ($resubmissionOf) {
        db()->prepare('UPDATE endeavour_submissions SET status = "needs_resubmission" WHERE id = ?')->execute([$resubmissionOf]);
    }
    return [
        'id' => $submissionId,
        'endeavour_id' => (int)$endeavour['id'],
        'doc_type' => $docType,
        'file_drive_item_id' => $fileId,
        'version_no' => $version,
        'due_at' => $dueAt,
        'is_overdue' => $isOverdue,
        'status' => $status,
        'resubmission_of_id' => $resubmissionOf,
    ];
}

function latest_endeavour_submission(int $endeavourId, string $docType): ?array {
    $docType = normalize_submission_doc_type($docType);
    try {
        $stmt = db()->prepare('SELECT * FROM endeavour_submissions WHERE endeavour_id = ? AND doc_type = ? ORDER BY submitted_at DESC, id DESC LIMIT 1');
        $stmt->execute([$endeavourId, $docType]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function latest_submission_approval(int $submissionId, string $group): ?array {
    $stmt = db()->prepare('SELECT * FROM endeavour_submission_approvals WHERE submission_id = ? AND approver_group = ? ORDER BY decided_at DESC, id DESC LIMIT 1');
    $stmt->execute([$submissionId, $group]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function record_endeavour_submission_approval(array $submission, string $group, string $decision, string $comment, array $user): void {
    if ($decision === 'rejected' && trim($comment) === '') {
        respond(['ok' => false, 'error' => 'Rejection comments are required'], 400);
    }
    if (in_array((string)($submission['status'] ?? ''), ['approved', 'rejected'], true)) {
        respond(['ok' => false, 'error' => 'Submission already has a terminal decision'], 400);
    }
    if ($group === 'mob') {
        require_permission('endeavour.approve_mob', null, $user);
    } elseif ($group === 'student_affairs') {
        require_permission('endeavour.approve_sa', null, $user);
        $mobApproval = latest_submission_approval((int)$submission['id'], 'mob');
        if (!$mobApproval || $mobApproval['decision'] !== 'approved') {
            respond(['ok' => false, 'error' => 'Member of Board approval is required first'], 400);
        }
    } else {
        respond(['ok' => false, 'error' => 'Invalid approver group'], 400);
    }
    if (latest_submission_approval((int)$submission['id'], $group)) {
        respond(['ok' => false, 'error' => 'This approver group has already decided this submission'], 400);
    }
    $stmt = db()->prepare('INSERT INTO endeavour_submission_approvals (submission_id, approver_group, decision, comment, decided_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([(int)$submission['id'], $group, $decision, $comment, $user['id']]);
    $newStatus = 'submitted';
    if ($decision === 'rejected') {
        $newStatus = 'rejected';
    } elseif ($group === 'mob') {
        $newStatus = 'mob_approved';
    } elseif ($group === 'student_affairs') {
        $newStatus = 'approved';
    }
    db()->prepare('UPDATE endeavour_submissions SET status = ?, rejection_comment = ? WHERE id = ?')
        ->execute([$newStatus, $decision === 'rejected' ? $comment : null, (int)$submission['id']]);
    if ($decision === 'rejected') {
        notify_endeavour_entity_executives((int)$submission['endeavour_id'], (string)$submission['doc_type'], $comment);
    }
}

function handle_endeavour_submission_approval(array $endeavour, int $submissionId): void {
    $user = require_auth();
    $data = read_json();
    $decision = $data['decision'] ?? '';
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        respond(['ok' => false, 'error' => 'Valid decision required'], 400);
    }
    $group = $data['approver_group'] ?? null;
    if (!$group) {
        $group = can_permission($user, 'endeavour.approve_mob') ? 'mob' : (can_permission($user, 'endeavour.approve_sa') ? 'student_affairs' : '');
    }
    $stmt = db()->prepare('SELECT * FROM endeavour_submissions WHERE id = ? AND endeavour_id = ?');
    $stmt->execute([$submissionId, (int)$endeavour['id']]);
    $submission = $stmt->fetch();
    if (!$submission) {
        respond(['ok' => false, 'error' => 'Submission not found'], 404);
    }
    record_endeavour_submission_approval($submission, $group, $decision, sanitize_text($data['comment'] ?? '', 1000), $user);
    sync_legacy_doc_approval_from_submission((int)$endeavour['id'], (string)$submission['doc_type'], $group, $decision, $user, sanitize_text($data['comment'] ?? '', 1000));
    evaluate_phase_transition((int)$endeavour['id']);
    log_activity($user['id'], 'endeavour', (int)$endeavour['id'], $decision === 'approved' ? 'submission_approved' : 'submission_rejected', 'Submission approval updated', ['submission_id' => $submissionId, 'group' => $group]);
    respond(['ok' => true]);
}

function sync_legacy_doc_approval_from_submission(int $endeavourId, string $docType, string $group, string $decision, array $user, string $comment): void {
    $legacyGroup = $group === 'mob' ? 'bod' : 'student_affairs';
    $stmt = db()->prepare('INSERT INTO endeavour_doc_approvals (endeavour_id, doc_type, approver_group, status, approver_user_id, comment) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), approver_user_id = VALUES(approver_user_id), comment = VALUES(comment)');
    $stmt->execute([$endeavourId, $docType, $legacyGroup, $decision, $user['id'], $comment]);
}

function endeavour_submissions_for_response(int $endeavourId, array $user): array {
    try {
        $stmt = db()->prepare(
            'SELECT es.*, f.name AS file_name, f.mime_type, f.size_bytes, u.full_name AS submitted_by_name
             FROM endeavour_submissions es
             JOIN file_drive_items f ON f.id = es.file_drive_item_id
             JOIN users u ON u.id = es.submitted_by
             WHERE es.endeavour_id = ?
             ORDER BY es.submitted_at DESC, es.id DESC'
        );
        $stmt->execute([$endeavourId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $entityId = null;
    try {
        $entityStmt = db()->prepare('SELECT entity_id FROM endeavours WHERE id = ?');
        $entityStmt->execute([$endeavourId]);
        $entity = $entityStmt->fetchColumn();
        $entityId = $entity !== false ? (int)$entity : null;
    } catch (PDOException $e) {
        $entityId = null;
    }
    $canSeeFiles = $entityId !== null && (
        can_permission($user, 'endeavour.view_confidential', $entityId)
        || can_permission($user, 'endeavour.submit_docs', $entityId)
        || can_permission($user, 'endeavour.approve_mob', $entityId)
        || can_permission($user, 'endeavour.approve_sa', $entityId)
    );
    $submissionIds = array_map(fn($row) => (int)$row['id'], $rows);
    $approvals = [];
    if ($submissionIds) {
        $in = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = db()->prepare("SELECT esa.*, u.full_name AS decided_by_name FROM endeavour_submission_approvals esa JOIN users u ON u.id = esa.decided_by WHERE esa.submission_id IN ({$in}) ORDER BY esa.decided_at ASC");
        $stmt->execute($submissionIds);
        foreach ($stmt->fetchAll() as $approval) {
            $approvals[(int)$approval['submission_id']][] = $approval;
        }
    }
    return array_map(static function ($row) use ($approvals, $canSeeFiles) {
        $row['approvals'] = $approvals[(int)$row['id']] ?? [];
        if ($canSeeFiles) {
            $row['download_url'] = '/api/files/download?type=endeavour_submission&id=' . urlencode((string)$row['id']);
        } else {
            unset($row['file_drive_item_id'], $row['file_name'], $row['mime_type'], $row['size_bytes']);
        }
        return $row;
    }, $rows);
}

function decorate_endeavour_row(array $row, array $user): array {
    $required = ['operational_plan', 'budget_plan', 'pre_financial', 'post_financial', 'epilogue'];
    if (empty($row['volunteering_enabled'])) {
        $row['volunteering_skipped'] = true;
    }
    $left = [];
    foreach ($required as $docType) {
        $latest = latest_endeavour_submission((int)$row['id'], $docType);
        if (!$latest) {
            $left[] = ['type' => 'submission', 'doc_type' => $docType, 'status' => 'missing', 'due_at' => applicable_plan_deadline($row, $docType)];
            continue;
        }
        if ($latest['status'] === 'rejected') {
            $left[] = ['type' => 'submission', 'doc_type' => $docType, 'status' => 'needs_resubmission', 'due_at' => $latest['due_at']];
            continue;
        }
        if (!in_array($latest['status'], ['approved', 'no_approval_required'], true)) {
            $left[] = ['type' => 'approval', 'doc_type' => $docType, 'status' => $latest['status'], 'due_at' => $latest['due_at']];
        }
    }
    $row['workflow_summary'] = [
        'remaining' => $left,
        'next_required_action' => $left ? ($left[0]['status'] . ':' . $left[0]['doc_type']) : 'complete',
        'can_create' => can_permission($user, 'endeavour.create', (int)$row['entity_id']),
        'can_edit' => can_permission($user, 'endeavour.edit', (int)$row['entity_id']),
        'can_submit' => can_permission($user, 'endeavour.submit_docs', (int)$row['entity_id']),
        'can_approve_mob' => can_permission($user, 'endeavour.approve_mob', (int)$row['entity_id']),
        'can_approve_sa' => can_permission($user, 'endeavour.approve_sa', (int)$row['entity_id']),
    ];
    return $row;
}

function update_endeavour_latest_file(int $endeavourId, string $docType, int $fileId): void {
    $column = [
        'operational_plan' => 'operational_plan_file_id',
        'budget_plan' => 'budget_plan_file_id',
        'pre_financial' => 'pre_financial_file_id',
        'post_financial' => 'post_financial_file_id',
        'epilogue' => 'epilogue_file_id',
    ][$docType] ?? null;
    if (!$column) {
        return;
    }
    db()->prepare("UPDATE endeavours SET {$column} = ? WHERE id = ?")->execute([$fileId, $endeavourId]);
}

function normalize_endeavour_update_payload(array $endeavour, array $data): array {
    $eventStart = validate_datetime($data['event_start_at'] ?? $endeavour['event_start_at'] ?? null, 'event_start_at');
    $eventEnd = validate_datetime($data['event_end_at'] ?? $endeavour['event_end_at'] ?? null, 'event_end_at');
    if ($eventStart && $eventEnd && strtotime($eventEnd) < strtotime($eventStart)) {
        respond(['ok' => false, 'error' => 'event_end_at must not be before event_start_at'], 400);
    }
    $transportEnabled = array_key_exists('transport_fee_required', $data) ? (bool)$data['transport_fee_required'] : (bool)$endeavour['transport_fee_required'];
    $transportAmount = $transportEnabled
        ? normalize_money($data['transport_fee_amount'] ?? ($data['transport_payment_required'] ?? ($endeavour['transport_fee_amount'] ?? $endeavour['transport_payment_required'] ?? 0)), 'transport_fee_amount')
        : null;
    return [
        'name' => require_non_empty($data['title'] ?? ($data['name'] ?? $endeavour['name']), 'title', 190),
        'description' => sanitize_text($data['description'] ?? $endeavour['description'] ?? '', 5000),
        'long_description' => sanitize_text($data['long_description'] ?? ($endeavour['long_description'] ?? ($data['description'] ?? $endeavour['description'] ?? '')), 10000),
        'venue' => sanitize_text($data['venue'] ?? $endeavour['venue'] ?? '', 190),
        'schedule' => sanitize_text($data['schedule'] ?? $endeavour['schedule'] ?? '', 500),
        'start_date' => $eventStart ? substr($eventStart, 0, 10) : ($data['start_date'] ?? $endeavour['start_date']),
        'end_date' => $eventEnd ? substr($eventEnd, 0, 10) : ($data['end_date'] ?? $endeavour['end_date']),
        'transport_payment_required' => $transportAmount ?? 0,
        'volunteering_enabled' => array_key_exists('volunteering_enabled', $data) ? (int)!empty($data['volunteering_enabled']) : (int)$endeavour['volunteering_enabled'],
        'transport_fee_required' => $transportEnabled ? 1 : 0,
        'transport_fee_amount' => $transportAmount,
        'volunteer_registration_deadline' => validate_datetime($data['volunteer_registration_deadline'] ?? $endeavour['volunteer_registration_deadline'], 'volunteer_registration_deadline'),
        'pre_financial_deadline' => validate_datetime($data['pre_financial_deadline'] ?? $endeavour['pre_financial_deadline'], 'pre_financial_deadline'),
        'post_financial_deadline' => validate_datetime($data['post_financial_deadline'] ?? $endeavour['post_financial_deadline'], 'post_financial_deadline'),
        'event_start_at' => $eventStart,
        'event_end_at' => $eventEnd,
    ];
}

function endeavour_edit_requires_approval(array $endeavour, array $payload): bool {
    if (edit_only_enables_volunteering_or_transport($endeavour, $payload) && !endeavour_has_pre_financial_approval((int)$endeavour['id'])) {
        return false;
    }
    if (($endeavour['phase'] ?? 'PRE_EVENT') !== 'PRE_EVENT' || !in_array($endeavour['status'] ?? 'draft', ['draft'], true)) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM endeavour_submissions WHERE endeavour_id = ? LIMIT 1');
    try {
        $stmt->execute([(int)$endeavour['id']]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (PDOException $e) {
    }
    $stmt = db()->prepare('SELECT 1 FROM endeavour_doc_approvals WHERE endeavour_id = ? AND status = "approved" LIMIT 1');
    $stmt->execute([(int)$endeavour['id']]);
    return (bool)$stmt->fetchColumn();
}

function edit_only_enables_volunteering_or_transport(array $endeavour, array $payload): bool {
    $allowedChanges = ['volunteering_enabled', 'transport_fee_required', 'transport_fee_amount', 'transport_payment_required', 'volunteer_registration_deadline'];
    foreach ($payload as $key => $value) {
        if (!array_key_exists($key, $endeavour)) {
            continue;
        }
        $old = $endeavour[$key];
        if ((string)$old !== (string)$value && !in_array($key, $allowedChanges, true)) {
            return false;
        }
    }
    return ((int)($endeavour['volunteering_enabled'] ?? 0) === 0 && (int)$payload['volunteering_enabled'] === 1)
        || ((int)($endeavour['transport_fee_required'] ?? 0) === 0 && (int)$payload['transport_fee_required'] === 1);
}

function endeavour_has_pre_financial_approval(int $endeavourId): bool {
    $stmt = db()->prepare('SELECT 1 FROM endeavour_doc_approvals WHERE endeavour_id = ? AND doc_type = "pre_financial" AND status = "approved" LIMIT 1');
    $stmt->execute([$endeavourId]);
    if ($stmt->fetchColumn()) {
        return true;
    }
    try {
        $stmt = db()->prepare('SELECT 1 FROM endeavour_submissions WHERE endeavour_id = ? AND doc_type = "pre_financial" AND status IN ("mob_approved","approved") LIMIT 1');
        $stmt->execute([$endeavourId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function apply_endeavour_update_payload(int $endeavourId, array $payload): void {
    $stmt = db()->prepare('UPDATE endeavours SET name = ?, description = ?, long_description = ?, venue = ?, schedule = ?, start_date = ?, end_date = ?, transport_payment_required = ?, volunteering_enabled = ?, transport_fee_required = ?, transport_fee_amount = ?, volunteer_registration_deadline = ?, pre_financial_deadline = ?, post_financial_deadline = ?, event_start_at = ?, event_end_at = ?, edit_approval_status = "none", edit_pending_payload = NULL, edit_requested_by = NULL, edit_requested_at = NULL WHERE id = ?');
    $stmt->execute([
        $payload['name'],
        $payload['description'],
        $payload['long_description'],
        $payload['venue'],
        $payload['schedule'],
        $payload['start_date'],
        $payload['end_date'],
        $payload['transport_payment_required'],
        $payload['volunteering_enabled'],
        $payload['transport_fee_required'],
        $payload['transport_fee_amount'],
        $payload['volunteer_registration_deadline'],
        $payload['pre_financial_deadline'],
        $payload['post_financial_deadline'],
        $payload['event_start_at'],
        $payload['event_end_at'],
        $endeavourId,
    ]);
}

function notify_submission_approvers(int $endeavourId, int $entityId, string $docType): void {
    $payload = ['endeavour_id' => $endeavourId, 'doc_type' => $docType, 'message' => 'Submission awaiting Member of Board approval'];
    $mobStmt = db()->prepare('SELECT user_id FROM entity_mob_assignments WHERE entity_id = ?');
    try {
        $mobStmt->execute([$entityId]);
        $userIds = array_map('intval', $mobStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        $userIds = [];
    }
    if (!$userIds) {
        $userIds = rbac_user_ids_with_permission('endeavour.approve_mob', $entityId);
    }
    foreach (array_unique($userIds) as $userId) {
        create_notification((int)$userId, 'submission_pending_mob', $payload);
    }
}

function notify_endeavour_entity_executives(int $endeavourId, string $docType, string $comment): void {
    $endeavour = fetch_endeavour($endeavourId);
    if (!$endeavour) {
        return;
    }
    $userIds = array_merge(
        rbac_user_ids_with_permission('endeavour.submit_docs', (int)$endeavour['entity_id']),
        rbac_user_ids_with_permission('endeavour.create', (int)$endeavour['entity_id'])
    );
    foreach (array_unique($userIds) as $userId) {
        create_notification((int)$userId, 'submission_rejected', [
            'endeavour_id' => $endeavourId,
            'doc_type' => $docType,
            'comment' => $comment,
        ]);
    }
}

function rbac_user_ids_with_permission(string $permission, ?int $entityId = null): array {
    if (!rbac_tables_ready()) {
        return [];
    }
    $params = [$permission];
    $entityClause = '';
    if ($entityId !== null) {
        $entityClause = ' AND (ur.entity_id IS NULL OR ur.entity_id = ?)';
        $params[] = $entityId;
    }
    $stmt = db()->prepare(
        'SELECT DISTINCT ur.user_id
         FROM rbac_user_roles ur
         JOIN rbac_role_permissions rp ON rp.role_id = ur.role_id
         JOIN rbac_permissions p ON p.id = rp.permission_id
         WHERE p.code = ?
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
        . $entityClause
    );
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}


function update_status(int $endeavourId, string $status): void {
    $stmt = db()->prepare('UPDATE endeavours SET status = ? WHERE id = ?');
    $stmt->execute([$status, $endeavourId]);
}

function update_phase(int $endeavourId, string $phase): void {
    $stmt = db()->prepare('UPDATE endeavours SET phase = ? WHERE id = ?');
    $stmt->execute([$phase, $endeavourId]);
}

function validate_datetime(?string $value, string $field): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim($value);
    $formats = ['Y-m-d\\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\\TH:i', 'Y-m-d'];
    foreach ($formats as $format) {
        $parseFormat = '!' . $format;
        $dt = DateTime::createFromFormat($parseFormat, $value);
        $errors = DateTime::getLastErrors();
        $hasErrors = $errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if (!$dt || $hasErrors || $dt->format($format) !== $value) {
            continue;
        }
        $year = (int)$dt->format('Y');
        if ($year < 2000 || $year > 2100) {
            respond(['ok' => false, 'error' => "{$field} must be between years 2000 and 2100"], 400);
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    }

    respond(['ok' => false, 'error' => "Invalid datetime for {$field}"], 400);
    return null;
}

function phase_precedes(string $current, string $target): bool {
    $order = [
        'PRE_EVENT' => 1,
        'PRE_FINANCIAL' => 2,
        'VOLUNTEER_REGISTRATION' => 3,
        'VOLUNTEER_SHORTLISTING' => 4,
        'ON_DAY' => 5,
        'POST_EVENT' => 6,
        'COMPLETED' => 7
    ];
    $currentRank = $order[$current] ?? 0;
    $targetRank = $order[$target] ?? 0;
    return $currentRank > 0 && $targetRank > 0 && $currentRank < $targetRank;
}

function ensure_drive_file(int $entityId, int $fileId): void {
    $stmt = db()->prepare('SELECT id FROM file_drive_items WHERE id = ? AND entity_id = ? AND item_type = "file"');
    $stmt->execute([$fileId, $entityId]);
    if (!$stmt->fetch()) {
        respond(['ok' => false, 'error' => 'Drive file not found'], 404);
    }
}

function seed_doc_approvals(int $endeavourId, array $docTypes): void {
    $stmt = db()->prepare('INSERT INTO endeavour_doc_approvals (endeavour_id, doc_type, approver_group, status) VALUES (?, ?, ?, "pending") ON DUPLICATE KEY UPDATE status = "pending", approver_user_id = NULL, comment = NULL');
    foreach ($docTypes as $docType) {
        foreach (['bod', 'student_affairs'] as $group) {
            $stmt->execute([$endeavourId, $docType, $group]);
        }
    }
}

function resolve_approver_group(array $user, ?string $override): ?string {
    if ($override === 'bod' && can_permission($user, 'endeavour.approve_mob')) {
        return 'bod';
    }
    if ($override === 'student_affairs' && can_permission($user, 'endeavour.approve_sa')) {
        return 'student_affairs';
    }
    if (can_permission($user, 'endeavour.approve_mob') && !can_permission($user, 'endeavour.approve_sa')) {
        return 'bod';
    }
    if (can_permission($user, 'endeavour.approve_sa') && !can_permission($user, 'endeavour.approve_mob')) {
        return 'student_affairs';
    }
    return $override && in_array($override, ['bod', 'student_affairs'], true) ? $override : null;
}

function ensure_approver_access(array $user): void {
    if (!can_permission($user, 'endeavour.approve_mob') && !can_permission($user, 'endeavour.approve_sa')) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function evaluate_phase_transition(int $endeavourId): void {
    $endeavour = fetch_endeavour($endeavourId);
    if (!$endeavour) {
        return;
    }
    $phase = $endeavour['phase'] ?: 'PRE_EVENT';
    $approvalStmt = db()->prepare('SELECT doc_type, approver_group, status FROM endeavour_doc_approvals WHERE endeavour_id = ?');
    $approvalStmt->execute([$endeavourId]);
    $approvals = $approvalStmt->fetchAll();
    $approved = [];
    foreach ($approvals as $approval) {
        if ($approval['status'] === 'approved') {
            $approved[$approval['doc_type']][$approval['approver_group']] = true;
        }
    }
    $hasAll = function (string $docType) use ($approved): bool {
        return !empty($approved[$docType]['bod']) && !empty($approved[$docType]['student_affairs']);
    };
    if ($phase === 'PRE_EVENT') {
        if ($hasAll('operational_plan') && $hasAll('budget_plan')) {
            update_phase($endeavourId, 'PRE_FINANCIAL');
        }
    } elseif ($phase === 'PRE_FINANCIAL') {
        if ($hasAll('pre_financial')) {
            $next = (int)$endeavour['volunteering_enabled'] ? 'VOLUNTEER_REGISTRATION' : 'ON_DAY';
            update_phase($endeavourId, $next);
        }
    } elseif ($phase === 'POST_EVENT') {
        $epilogue = latest_endeavour_submission($endeavourId, 'epilogue');
        if ($hasAll('post_financial') && ($epilogue || !empty($endeavour['epilogue_file_id']))) {
            update_phase($endeavourId, 'COMPLETED');
            db()->prepare('UPDATE endeavours SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([$endeavourId]);
        }
    }
}

function notify_shortlisted(int $endeavourId, int $entityId): void {
    $stmt = db()->prepare('SELECT vr.user_id, u.email, u.full_name, s.parent_email, s.parent_email_secondary FROM volunteer_registrations vr JOIN users u ON vr.user_id = u.id LEFT JOIN students s ON s.user_id = u.id WHERE vr.endeavour_id = ? AND vr.status = "shortlisted"');
    $stmt->execute([$endeavourId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return;
    }
    $endeavour = fetch_endeavour($endeavourId);
    $title = $endeavour ? $endeavour['name'] : 'Endeavour';
    $details = $endeavour
        ? sprintf(
            '<p><strong>Event:</strong> %s</p><p><strong>Venue:</strong> %s</p><p><strong>Start:</strong> %s</p><p><strong>End:</strong> %s</p><p><strong>Transport fee:</strong> %s</p>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)($endeavour['venue'] ?? 'TBD'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)($endeavour['event_start_at'] ?? 'TBD'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string)($endeavour['event_end_at'] ?? 'TBD'), ENT_QUOTES, 'UTF-8'),
            !empty($endeavour['transport_fee_required']) ? htmlspecialchars((string)($endeavour['transport_fee_amount'] ?? $endeavour['transport_payment_required'] ?? 'Required'), ENT_QUOTES, 'UTF-8') : 'Not required'
        )
        : '';
    foreach ($rows as $row) {
        $payload = [
            'endeavour_id' => $endeavourId,
            'entity_id' => $entityId,
            'endeavour_public_id' => public_id_for_row('endeavours', $endeavourId),
            'entity_public_id' => public_id_for_row('entities', $entityId),
            'title' => $title
        ];
        $body = '<p>You have been shortlisted for the Nixor endeavour: ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '.</p>';
        $sent = send_email($row['email'], 'You have been shortlisted', $body, true);
        create_notification((int)$row['user_id'], 'volunteer_shortlisted', $payload, !$sent);
        $parentEmail = $row['parent_email'] ?: $row['parent_email_secondary'];
        if ($parentEmail) {
            $parentBody = '<p>Your child has been shortlisted for a Nixor Corporate endeavour.</p>' . $details . '<p>If you would not like your child to participate, please reply to this email.</p>';
            send_email($parentEmail, 'Volunteer shortlisted', $parentBody, true, 'support@nixorcollege.edu.pk');
        } else {
            error_log('Parent email missing for shortlisted volunteer user_id=' . $row['user_id']);
        }
    }
}

function create_notification(int $userId, string $type, array $payload, bool $force = false): void {
    create_platform_notification($userId, $type, $payload, $force);
}

function notification_preference_column_for_type(string $type): string {
    if (str_starts_with($type, 'volunteer')) {
        return 'volunteering_enabled';
    }
    if (str_starts_with($type, 'social')) {
        return 'social_enabled';
    }
    if (str_starts_with($type, 'calendar')) {
        return 'calendar_enabled';
    }
    if (str_contains($type, 'submission') || str_contains($type, 'approval') || str_contains($type, 'rejected')) {
        return 'approvals_enabled';
    }
    return 'platform_enabled';
}

function handle_doc_upload(int $endeavourId, string $docType, string $nextStatus, int $userId, bool $requiresApproval = true): void {
    $endeavour = fetch_endeavour($endeavourId);
    if (!$endeavour) {
        respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
    }
    if (!isset($_FILES['document'])) {
        respond(['ok' => false, 'error' => 'Document missing'], 400);
    }
    $uploaded = save_uploaded_file((string)$endeavourId, $docType, $_FILES['document']);
    $stmt = db()->prepare('INSERT INTO endeavour_documents (endeavour_id, doc_type, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$endeavourId, $docType, $uploaded['path'], $uploaded['original'], $userId]);
    if ($requiresApproval) {
        update_status($endeavourId, $nextStatus);
    }
    log_activity($userId, 'endeavour', $endeavourId, 'document_uploaded', 'Document uploaded', ['doc_type' => $docType]);
    emit_ws_event('endeavour.document_uploaded', ['id' => $endeavourId, 'doc_type' => $docType]);
    respond(['ok' => true, 'data' => ['path' => $uploaded['path']]]);
}

function handle_approval(int $endeavourId): void {
    $user = require_auth();
    $data = read_json();
    $endeavour = fetch_endeavour($endeavourId);
    if (!$endeavour) {
        respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
    }

    $status = $endeavour['status'];
    if (empty($data['decision']) || !in_array($data['decision'], ['approved', 'rejected'], true)) {
        respond(['ok' => false, 'error' => 'Valid decision (approved/rejected) is required'], 400);
    }
    $decision = $data['decision'];
    $roleNeeded = null;
    $nextStatus = $status;

    // Approval state machine: board approvals precede admin approvals for each stage.
    if (in_array($status, ['pending_board_approval', 'ops_plan_pending_board_approval', 'mou_pending_board_approval', 'pre_financial_pending_board_approval', 'volunteer_posting_pending_board_approval', 'post_financial_pending_board_approval'], true)) {
        $roleNeeded = 'board';
    } elseif (in_array($status, ['board_approved_ops_plan_required', 'ops_plan_approved_mou_optional', 'mou_approved_pre_financial_required', 'finance_approved_hr_posting_optional', 'volunteer_posting_approved_hr_publish', 'closed_ops_epilogue_required'], true)) {
        $roleNeeded = 'admin';
    }

    if ($roleNeeded === 'board' && !can_permission($user, 'endeavour.approve_mob', (int)$endeavour['entity_id'])) {
        respond(['ok' => false, 'error' => 'Board approval required'], 403);
    }
    if ($roleNeeded === 'admin' && !can_permission($user, 'endeavour.approve_sa', (int)$endeavour['entity_id'])) {
        respond(['ok' => false, 'error' => 'Student Affairs approval required'], 403);
    }

    if ($decision === 'rejected') {
        $nextStatus = 'rejected';
    } else {
        $nextStatus = match ($status) {
            'pending_board_approval' => 'board_approved_ops_plan_required',
            'ops_plan_pending_board_approval' => 'ops_plan_approved_mou_optional',
            'mou_pending_board_approval' => 'mou_approved_pre_financial_required',
            'pre_financial_pending_board_approval' => 'finance_approved_hr_posting_optional',
            'volunteer_posting_pending_board_approval' => 'volunteer_posting_approved_hr_publish',
            'post_financial_pending_board_approval' => 'closed_ops_epilogue_required',
            'board_approved_ops_plan_required' => 'ops_plan_pending_board_approval',
            'ops_plan_approved_mou_optional' => 'mou_pending_board_approval',
            'mou_approved_pre_financial_required' => 'pre_financial_pending_board_approval',
            'finance_approved_hr_posting_optional' => 'volunteer_posting_pending_board_approval',
            'volunteer_posting_approved_hr_publish' => 'live_volunteer_posting',
            'closed_ops_epilogue_required' => 'completed',
            default => $status,
        };
    }

    $stmt = db()->prepare('INSERT INTO approvals (endeavour_id, stage, role_required, decision, notes, approved_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$endeavourId, $status, $roleNeeded ?? 'board', $decision, $data['notes'] ?? '', $user['id']]);
    update_status($endeavourId, $nextStatus);
    $actionLabel = $decision === 'rejected' ? 'rejected' : 'approved';
    log_activity($user['id'], 'endeavour', $endeavourId, $actionLabel, "Stage {$actionLabel}", ['status' => $nextStatus, 'decision' => $decision]);
    emit_ws_event('endeavour.approval_updated', ['id' => $endeavourId, 'status' => $nextStatus]);
    respond(['ok' => true, 'data' => ['status' => $nextStatus]]);
}
