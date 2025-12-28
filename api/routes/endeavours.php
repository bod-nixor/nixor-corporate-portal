<?php
function handle_endeavours(string $method, array $segments): void {
    $id = isset($segments[1]) ? (int)$segments[1] : null;
    $action = $segments[2] ?? null;

    if ($method === 'GET' && !$id) {
        $user = require_auth();
        $rows = db()->query('SELECT e.*, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id ORDER BY e.created_at DESC')->fetchAll();
        respond(['ok' => true, 'data' => $rows, 'meta' => ['user' => $user]]);
    }

    if ($method === 'POST' && !$id) {
        $user = require_role(['admin', 'ceo']);
        $data = read_json();
        if (empty($data['entity_id']) || empty($data['name'])) {
            respond(['ok' => false, 'error' => 'entity_id and name are required'], 400);
        }
        $stmt = db()->prepare('INSERT INTO endeavours (entity_id, created_by, name, type_id, description, venue, schedule, start_date, end_date, transport_payment_required, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['entity_id'],
            $user['id'],
            $data['name'],
            $data['type_id'] ?? null,
            $data['description'] ?? '',
            $data['venue'] ?? '',
            $data['schedule'] ?? '',
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['transport_payment_required'] ?? 0,
            'pending_board_approval'
        ]);
        $endeavourId = (int)db()->lastInsertId();
        log_activity($user['id'], 'endeavour', $endeavourId, 'created', 'CEO created endeavour', ['status' => 'pending_board_approval']);
        emit_ws_event('endeavour.created', ['id' => $endeavourId]);
        respond(['ok' => true, 'data' => ['id' => $endeavourId]]);
    }

    if ($method === 'GET' && $id && !$action) {
        $user = require_auth();
        $stmt = db()->prepare('SELECT e.*, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id WHERE e.id = ?');
        $stmt->execute([$id]);
        $endeavour = $stmt->fetch();
        $activity = db()->prepare('SELECT a.*, u.full_name FROM activity_log a LEFT JOIN users u ON a.actor_id = u.id WHERE entity_type = "endeavour" AND entity_id = ? ORDER BY a.created_at DESC');
        $activity->execute([$id]);
        respond(['ok' => true, 'data' => ['endeavour' => $endeavour, 'activity' => $activity->fetchAll()], 'meta' => ['user' => $user]]);
    }

    if ($method === 'PUT' && $id && !$action) {
        $user = require_role(['admin', 'ceo']);
        $endeavour = fetch_endeavour($id);
        if (!$endeavour) {
            respond(['ok' => false, 'error' => 'Endeavour not found'], 404);
        }
        $data = read_json();
        if (empty($data['name'])) {
            respond(['ok' => false, 'error' => 'name is required'], 400);
        }
        $stmt = db()->prepare('UPDATE endeavours SET name = ?, description = ?, venue = ?, schedule = ?, start_date = ?, end_date = ?, transport_payment_required = ? WHERE id = ?');
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['venue'] ?? null,
            $data['schedule'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['transport_payment_required'] ?? 0,
            $id
        ]);
        log_activity($user['id'], 'endeavour', $id, 'updated', 'Endeavour updated');
        respond(['ok' => true, 'data' => ['id' => $id]]);
    }

    if ($id && $action === 'submit_ops_plan' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['operations']);
        handle_doc_upload($id, 'ops_plan', 'ops_plan_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_mou' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['operations']);
        handle_doc_upload($id, 'mou', 'mou_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_pre_financial' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['finance']);
        handle_doc_upload($id, 'pre_financial', 'pre_financial_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_post_financial' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['finance']);
        handle_doc_upload($id, 'post_financial', 'post_financial_pending_board_approval', $user['id']);
    }

    if ($id && $action === 'submit_epilogue' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['operations']);
        handle_doc_upload($id, 'epilogue', 'completed', $user['id'], false);
    }

    if ($id && $action === 'approve' && $method === 'POST') {
        handle_approval($id);
    }

    if ($id && $action === 'request_post_to_feed' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['hr']);
        $data = read_json();
        $stmt = db()->prepare('INSERT INTO volunteer_posts (endeavour_id, description, eligibility_notes, venue, schedule, transport_payment, questionnaire_mode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $id,
            $data['description'] ?? '',
            $data['eligibility_notes'] ?? '',
            $data['venue'] ?? '',
            $data['schedule'] ?? '',
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
        $user = ensure_entity_access(fetch_entity_id($id), ['hr']);
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
        require_role(['admin', 'board', 'hr']);
        $stmt = db()->prepare('SELECT va.*, s.student_id, u.full_name FROM volunteer_applications va JOIN students s ON va.student_id = s.id JOIN users u ON s.user_id = u.id WHERE va.volunteer_post_id IN (SELECT id FROM volunteer_posts WHERE endeavour_id = ?)');
        $stmt->execute([$id]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($id && $action === 'applications' && $method === 'POST') {
        $user = require_auth();
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
        $stmt = db()->prepare('INSERT INTO volunteer_applications (volunteer_post_id, student_id, answers_json) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $student['id'], json_encode($data['answers'] ?? [])]);
        emit_ws_event('endeavour.application_submitted', ['id' => $id]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    if ($id && $action === 'shortlist' && $method === 'POST') {
        $user = ensure_entity_access(fetch_entity_id($id), ['hr']);
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id']) || (int)$data['application_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Invalid application_id'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $stmt = db()->prepare('INSERT INTO shortlists (volunteer_application_id, shortlisted_by) VALUES (?, ?)');
        $stmt->execute([$applicationId, $user['id']]);
        $update = db()->prepare('UPDATE volunteer_applications SET status = "shortlisted" WHERE id = ?');
        $update->execute([$applicationId]);
        emit_ws_event('endeavour.shortlisted', ['id' => $id]);
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
        $stmt = db()->prepare('UPDATE consents SET status = "signed", signed_at = NOW(), signature_name = ? WHERE token = ?');
        $stmt->execute([$data['signature_name'] ?? '', $data['token'] ?? '']);
        emit_ws_event('endeavour.consent_signed', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'payment' && ($segments[3] ?? '') === 'mark_paid' && $method === 'POST') {
        $user = require_role(['admin']);
        $data = read_json();
        if (empty($data['application_id']) || !is_numeric($data['application_id']) || (int)$data['application_id'] <= 0) {
            respond(['ok' => false, 'error' => 'Invalid application_id'], 400);
        }
        $applicationId = (int)$data['application_id'];
        $receiptRef = $data['receipt_ref'] ?? '';
        $stmt = db()->prepare('UPDATE payments SET paid_flag = 1, paid_by = ?, paid_at = NOW(), receipt_ref = ? WHERE volunteer_application_id = ?');
        $stmt->execute([$user['id'], $receiptRef, $applicationId]);
        emit_ws_event('endeavour.payment_marked', ['id' => $id]);
        respond(['ok' => true]);
    }

    if ($id && $action === 'attendance' && ($segments[3] ?? '') === 'mark' && $method === 'POST') {
        $user = require_auth();
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
        $allowed = ['admin'];
        if ($endeavour['type_id']) {
            $allowed = ['admin', 'board', 'hr'];
        }
        if (!in_array($user['global_role'], $allowed, true)) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $check = db()->prepare('SELECT va.id FROM volunteer_applications va JOIN volunteer_posts vp ON va.volunteer_post_id = vp.id WHERE va.id = ? AND vp.endeavour_id = ?');
        $check->execute([$applicationId, $id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Application not found for endeavour'], 404);
        }
        $stmt = db()->prepare('UPDATE attendance SET status = ?, marked_by = ?, marked_at = NOW() WHERE volunteer_application_id = ?');
        $stmt->execute([$status, $user['id'], $applicationId]);
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


function update_status(int $endeavourId, string $status): void {
    $stmt = db()->prepare('UPDATE endeavours SET status = ? WHERE id = ?');
    $stmt->execute([$status, $endeavourId]);
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

    if ($roleNeeded === 'board' && $user['global_role'] !== 'board' && $user['global_role'] !== 'admin') {
        respond(['ok' => false, 'error' => 'Board approval required'], 403);
    }
    if ($roleNeeded === 'admin' && $user['global_role'] !== 'admin') {
        respond(['ok' => false, 'error' => 'Admin approval required'], 403);
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
