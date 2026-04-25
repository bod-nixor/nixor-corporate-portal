<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestClient.php';

final class ApiTest extends TestCase {
    private static $serverProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void {
        self::$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8001';
        apply_migrations(db(), __DIR__ . '/../sql/migrations');
        self::startServer();
    }

    public static function tearDownAfterClass(): void {
        if (self::$serverProcess) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    protected function setUp(): void {
        $pdo = db();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                if ($table === 'migrations') {
                    continue;
                }
                $pdo->exec("TRUNCATE TABLE `{$table}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Clear rate limits
        array_map('unlink', glob(sys_get_temp_dir() . '/nixor_rate_*'));
    }

    public function testCsrfBootstrapReturnsTokenAndSetsSessionCookie(): void {
        $client = new TestClient(self::$baseUrl);
        $response = $client->request('GET', '/api/auth/csrf');
        $this->assertSame(200, $response['status']);
        $token = $response['data']['data']['csrfToken'] ?? '';
        $this->assertNotEmpty($token);
        $sessionName = $response['data']['data']['sessionName'] ?? 'PHPSESSID';
        $this->assertNotEmpty($client->getCookie($sessionName));
    }

    public function testLoginRequiresCsrf(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $client = new TestClient(self::$baseUrl);
        $response = $client->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!'
        ]);
        $this->assertSame(403, $response['status']);
    }

    public function testLoginSessionRotationAndLogout(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';
        $sessionName = $csrf['data']['data']['sessionName'] ?? 'PHPSESSID';
        $beforeSession = $client->getCookie($sessionName);

        $login = $client->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);

        if ($login['status'] !== 200) {
            echo "\nLogin Failed: " . json_encode($login) . "\n";
        }
        $this->assertSame(200, $login['status']);

        $afterSession = $client->getCookie($sessionName);
        $this->assertNotEmpty($afterSession);
        $this->assertNotSame($beforeSession, $afterSession);

        $logout = $client->request('POST', '/api/auth/logout', null, ["X-CSRF-Token: {$token}"]);
        $this->assertSame(200, $logout['status']);

        $me = $client->request('GET', '/api/auth/me');
        $this->assertNull($me['data']['data']['user']);
    }

    public function testPasswordLoginRejectsInvalidNullMissingAndUnsupportedHashes(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $this->createUserWithHash('google-only@example.com', null, 'staff');
        $this->createUserWithHash('legacy@example.com', 'not-a-password-hash', 'staff');

        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';

        $invalid = $client->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'WrongPassword123!'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(401, $invalid['status']);
        $this->assertSame('Invalid credentials', $invalid['data']['error']);

        $nullHash = $client->request('POST', '/api/auth/login', [
            'email' => 'google-only@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(401, $nullHash['status']);
        $this->assertSame('Invalid credentials', $nullHash['data']['error']);

        $unsupported = $client->request('POST', '/api/auth/login', [
            'email' => 'legacy@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(401, $unsupported['status']);
        $this->assertSame('Invalid credentials', $unsupported['data']['error']);

        $missing = $client->request('POST', '/api/auth/login', [
            'email' => '',
            'password' => ''
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(400, $missing['status']);
        $this->assertSame('Email and password are required', $missing['data']['error']);
    }

    public function testProtectedRouteRequiresAuth(): void {
        $client = new TestClient(self::$baseUrl);
        $response = $client->request('GET', '/api/endeavours');
        $this->assertSame(401, $response['status']);
    }

    public function testEntityCrudAndAuthorization(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $this->createUser('staff@example.com', 'Password123!', 'staff');

        $adminClient = new TestClient(self::$baseUrl);
        $csrf = $adminClient->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';
        $adminClient->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);

        $create = $adminClient->request('POST', '/api/entities', [
            'name' => 'Test Entity',
            'description' => 'Entity description'
        ], ["X-CSRF-Token: {$token}"]);

        if ($create['status'] !== 200) {
            echo "\nCreate Entity Failed: " . json_encode($create) . "\n";
        }
        $this->assertSame(200, $create['status']);

        $entityId = $create['data']['data']['id'] ?? null;
        $this->assertNotNull($entityId);

        $read = $adminClient->request('GET', "/api/entities/{$entityId}");
        $this->assertSame(200, $read['status']);
        $this->assertSame('Test Entity', $read['data']['data']['name']);

        $update = $adminClient->request('PUT', "/api/entities/{$entityId}", [
            'name' => 'Updated Entity',
            'description' => 'Updated'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(200, $update['status']);

        $staffClient = new TestClient(self::$baseUrl);
        $staffCsrf = $staffClient->request('GET', '/api/auth/csrf');
        $staffToken = $staffCsrf['data']['data']['csrfToken'] ?? '';
        $staffClient->request('POST', '/api/auth/login', [
            'email' => 'staff@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$staffToken}"]);
        $blocked = $staffClient->request('GET', "/api/entities/{$entityId}");

        if ($blocked['status'] !== 403) {
            echo "\nEntity Access Unexpected Status: " . json_encode($blocked) . "\n";
        }
        $this->assertSame(403, $blocked['status']);
        $this->assertSame('Forbidden', $blocked['data']['error']);
    }

    public function testCeoCannotCreateEndeavourOutsideEntityMembership(): void {
        $ceoId = $this->createUser('ceo@example.com', 'Password123!', 'ceo');
        $memberEntityId = $this->createEntity('CEO Member Entity');
        $otherEntityId = $this->createEntity('CEO Other Entity');
        $this->addMembership($memberEntityId, $ceoId, 'management', 'manager');

        $client = $this->loginClient('ceo@example.com', 'Password123!');
        $blocked = $client->request('POST', '/api/endeavours', [
            'entity_id' => $otherEntityId,
            'name' => 'Cross Entity Attempt',
            'event_start_at' => '2026-05-01T12:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);

        $this->assertSame(403, $blocked['status']);
        $this->assertSame('Entity access denied', $blocked['data']['error']);
    }

    public function testAdminAliasesAuthorizeAndValidateEntityAndUserCreation(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $this->createUser('staff@example.com', 'Password123!', 'staff');

        $anonymous = new TestClient(self::$baseUrl);
        $anonymousSummary = $anonymous->request('GET', '/api/admin/summary');
        $this->assertSame(401, $anonymousSummary['status']);

        $staffClient = $this->loginClient('staff@example.com', 'Password123!');
        $staffSummary = $staffClient->request('GET', '/api/admin/summary');
        $this->assertSame(403, $staffSummary['status']);

        $adminClient = $this->loginClient('admin@example.com', 'Password123!');
        $summary = $adminClient->request('GET', '/api/admin/summary');
        $this->assertSame(200, $summary['status']);
        $this->assertArrayHasKey('missing_docs', $summary['data']['data']);

        $entity = $adminClient->request('POST', '/api/admin/entities', [
            'name' => 'Admin Alias Entity',
            'description' => 'Created through admin alias'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $entity['status']);

        $secondEntity = $adminClient->request('POST', '/api/admin/entities', [
            'name' => 'Second Admin Entity',
            'description' => 'Created for rename collision'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $secondEntity['status']);

        $duplicateEntity = $adminClient->request('POST', '/api/admin/entities', [
            'name' => 'admin alias entity',
            'description' => 'Duplicate casing'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(409, $duplicateEntity['status']);

        $duplicateRename = $adminClient->request('PUT', '/api/admin/entities/' . (int)$secondEntity['data']['data']['id'], [
            'name' => 'ADMIN ALIAS ENTITY',
            'description' => 'Duplicate rename'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(409, $duplicateRename['status']);

        $user = $adminClient->request('POST', '/api/admin/users', [
            'email' => 'new-user@example.com',
            'full_name' => 'New User',
            'password' => 'AnotherPassword123!',
            'global_role' => 'staff'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $user['status']);

        $duplicateUser = $adminClient->request('POST', '/api/admin/users', [
            'email' => 'new-user@example.com',
            'full_name' => 'Duplicate User',
            'password' => 'AnotherPassword123!',
            'global_role' => 'staff'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(409, $duplicateUser['status']);
    }

    public function testAdminSetupBlockedForNonAdmin(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $this->createUser('staff@example.com', 'Password123!', 'staff');

        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';
        $client->request('POST', '/api/auth/login', [
            'email' => 'staff@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);

        $setup = $client->request('POST', '/api/admin/setup', [
            'email' => 'newadmin@example.com',
            'full_name' => 'New Admin',
            'password' => 'AnotherStrongPassword123!'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(403, $setup['status']);
    }

    public function testCalendarRejectsBadDatesAndHidesExistingOutOfRangeEvents(): void {
        $adminId = $this->createUser('admin@example.com', 'Password123!', 'admin');
        $entityId = $this->createEntity('Calendar Entity');
        $client = $this->loginClient('admin@example.com', 'Password123!');

        $badYear = $client->request('POST', '/api/calendar', [
            'entity_id' => $entityId,
            'title' => 'Bad Year',
            'event_date' => '1111-11-01T11:11'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(400, $badYear['status']);

        $endBeforeStart = $client->request('POST', '/api/calendar', [
            'entity_id' => $entityId,
            'title' => 'Bad Range',
            'event_date' => '2026-05-01T12:00',
            'end_date' => '2026-05-01T11:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(400, $endBeforeStart['status']);

        $missingTitle = $client->request('POST', '/api/calendar', [
            'entity_id' => $entityId,
            'event_date' => '2026-05-01T12:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(400, $missingTitle['status']);

        $valid = $client->request('POST', '/api/calendar', [
            'entity_id' => $entityId,
            'title' => 'Valid Calendar Event',
            'event_date' => '2026-05-01T12:00',
            'end_date' => '2026-05-01T13:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $valid['status']);
        $eventId = (int)$valid['data']['data']['id'];
        $stored = db()->prepare('SELECT event_date, end_date FROM calendar_events WHERE id = ?');
        $stored->execute([$eventId]);
        $storedEvent = $stored->fetch();
        $this->assertSame('2026-05-01 12:00:00', $storedEvent['event_date']);
        $this->assertSame('2026-05-01 13:00:00', $storedEvent['end_date']);

        $stmt = db()->prepare('INSERT INTO calendar_events (entity_id, title, event_date, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$entityId, 'Legacy Bad Event', '1111-11-01 11:11:00', $adminId]);

        $list = $client->request('GET', '/api/calendar?entity_id=' . $entityId);
        $this->assertSame(200, $list['status']);
        $titles = array_column($list['data']['data'], 'title');
        $this->assertContains('Valid Calendar Event', $titles);
        $this->assertNotContains('Legacy Bad Event', $titles);
        $validEvent = array_values(array_filter($list['data']['data'], fn($event) => $event['title'] === 'Valid Calendar Event'))[0] ?? null;
        $this->assertSame('2026-05-01 13:00:00', $validEvent['end_date'] ?? null);
    }

    public function testVolunteeringOnlyReturnsVisibleRegistrationOpportunities(): void {
        $userId = $this->createUser('volunteer@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Volunteer Entity');
        $this->addMembership($entityId, $userId, 'operations', 'member');
        $this->createEndeavour($entityId, $userId, 'Planning Only', 'PRE_EVENT', true, '+5 days');
        $activeId = $this->createEndeavour($entityId, $userId, 'Registration Open', 'VOLUNTEER_REGISTRATION', true, '+5 days');

        $client = $this->loginClient('volunteer@example.com', 'Password123!');
        $list = $client->request('GET', '/api/endeavours/volunteering');
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['data']['data']);
        $this->assertSame($activeId, (int)$list['data']['data'][0]['id']);
        $this->assertSame('Registration Open', $list['data']['data'][0]['name']);
    }

    public function testEndeavourCreationRejectsImpossibleDates(): void {
        $userId = $this->createUser('exec@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Strict Date Entity');
        $this->addMembership($entityId, $userId, 'operations', 'executive');

        $client = $this->loginClient('exec@example.com', 'Password123!');
        $invalid = $client->request('POST', '/api/endeavours', [
            'entity_id' => $entityId,
            'name' => 'Impossible Date',
            'event_start_at' => '2026-02-31T12:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);

        $this->assertSame(400, $invalid['status']);
        $this->assertSame('Invalid datetime for event_start_at', $invalid['data']['error']);

        $valid = $client->request('POST', '/api/endeavours', [
            'entity_id' => $entityId,
            'name' => 'Normalized Date',
            'event_start_at' => '2026-03-01T12:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $valid['status']);
        $stored = db()->prepare('SELECT event_start_at FROM endeavours WHERE id = ?');
        $stored->execute([(int)$valid['data']['data']['id']]);
        $this->assertSame('2026-03-01 12:00:00', $stored->fetchColumn());
    }

    public function testEntityEndeavourRegistrationsEndpointAndActions(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $execId = $this->createUser('exec@example.com', 'Password123!', 'staff');
        $volunteerId = $this->createUser('volunteer@example.com', 'Password123!', 'staff');
        $otherUserId = $this->createUser('other@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Registration Entity');
        $this->addMembership($entityId, $execId, 'operations', 'executive');
        $this->addMembership($entityId, $volunteerId, 'operations', 'member');
        $endeavourId = $this->createEndeavour($entityId, $execId, 'Registration Managed', 'VOLUNTEER_SHORTLISTING', true, '+5 days');
        $this->setEndeavourTransportFeeRequired($endeavourId);
        $registrationId = $this->createRegistration($endeavourId, $entityId, $volunteerId);

        $execClient = $this->loginClient('exec@example.com', 'Password123!');
        $list = $execClient->request('GET', "/api/endeavours/{$endeavourId}/registrations");
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['data']['data']);
        $this->assertSame('pending', $list['data']['data'][0]['status']);

        $otherClient = $this->loginClient('other@example.com', 'Password123!');
        $otherList = $otherClient->request('GET', "/api/endeavours/{$endeavourId}/registrations");
        $this->assertSame(403, $otherList['status']);

        $volunteerClient = $this->loginClient('volunteer@example.com', 'Password123!');
        $volunteerShortlist = $volunteerClient->request('POST', "/api/endeavours/{$endeavourId}/registrations/shortlist", [
            'registration_id' => $registrationId
        ], ["X-CSRF-Token: {$volunteerClient->csrfToken}"]);
        $this->assertSame(403, $volunteerShortlist['status']);

        $shortlist = $execClient->request('POST', "/api/endeavours/{$endeavourId}/registrations/shortlist", [
            'registration_id' => $registrationId
        ], ["X-CSRF-Token: {$execClient->csrfToken}"]);
        $this->assertSame(200, $shortlist['status']);

        $afterShortlist = $execClient->request('GET', "/api/endeavours/{$endeavourId}/registrations");
        $this->assertSame('shortlisted', $afterShortlist['data']['data'][0]['status']);

        $this->setEndeavourPhase($endeavourId, 'ON_DAY');

        $lateShortlist = $execClient->request('POST', "/api/endeavours/{$endeavourId}/registrations/shortlist", [
            'registration_id' => $registrationId
        ], ["X-CSRF-Token: {$execClient->csrfToken}"]);
        $this->assertSame(400, $lateShortlist['status']);

        $adminClient = $this->loginClient('admin@example.com', 'Password123!');
        $fee = $adminClient->request('POST', "/api/endeavours/{$endeavourId}/registrations/transport_fee", [
            'registration_id' => $registrationId
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $fee['status']);

        $afterFee = $adminClient->request('GET', "/api/endeavours/{$endeavourId}/registrations");
        $this->assertSame(1, (int)$afterFee['data']['data'][0]['transport_fee_paid']);
    }

    public function testHrDepartmentCanReadApplicationsAndMarkAttendance(): void {
        $hrId = $this->createUser('hr@example.com', 'Password123!', 'staff');
        $studentUserId = $this->createUser('student@example.com', 'Password123!', 'volunteer');
        $entityId = $this->createEntity('HR Entity');
        $this->addMembership($entityId, $hrId, 'hr', 'member');
        $endeavourId = $this->createEndeavour($entityId, $hrId, 'HR Managed Event', 'ON_DAY', true, '+5 days');
        $postId = $this->createVolunteerPost($endeavourId, $hrId);
        $studentId = $this->createStudent($studentUserId, 'S-100');
        $applicationId = $this->createVolunteerApplication($postId, $studentId);
        $this->createAttendance($applicationId);

        $client = $this->loginClient('hr@example.com', 'Password123!');
        $applications = $client->request('GET', "/api/endeavours/{$endeavourId}/applications");
        $this->assertSame(200, $applications['status']);
        $this->assertCount(1, $applications['data']['data']);

        $attendance = $client->request('POST', "/api/endeavours/{$endeavourId}/attendance/mark", [
            'application_id' => $applicationId,
            'status' => 'present'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $attendance['status']);

        $readBack = $client->request('GET', "/api/endeavours/{$endeavourId}");
        $this->assertSame(200, $readBack['status']);
        $attRecords = array_filter($readBack['data']['data']['attendance'], fn($a) => (int)$a['volunteer_application_id'] === $applicationId);
        $record = reset($attRecords);
        $this->assertSame('present', $record['status']);
    }

    public function testSocialPostRejectsEndeavourFromAnotherEntity(): void {
        $userId = $this->createUser('member@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Social Entity');
        $otherEntityId = $this->createEntity('Other Social Entity');
        $this->addMembership($entityId, $userId, 'communications', 'member');
        $otherEndeavourId = $this->createEndeavour($otherEntityId, $userId, 'Other Entity Endeavour', 'PRE_EVENT', false, '+5 days');

        $client = $this->loginClient('member@example.com', 'Password123!');
        $response = $client->request('POST', '/api/social', [
            'entity_id' => $entityId,
            'endeavour_id' => $otherEndeavourId,
            'content' => 'This should not cross-link entities.'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('Endeavour not found for entity', $response['data']['error']);
    }

    public function testDashboardProgressAndDeadlinesUseActionableData(): void {
        $adminId = $this->createUser('admin@example.com', 'Password123!', 'admin');
        $entityId = $this->createEntity('Dashboard Entity');
        $approvedId = $this->createEndeavour($entityId, $adminId, 'Approved Docs', 'PRE_EVENT', false, '+3 days');
        $pendingId = $this->createEndeavour($entityId, $adminId, 'Pending Docs', 'PRE_EVENT', false, null);
        $this->createEndeavour($entityId, $adminId, 'No Dates', 'PRE_EVENT', false, null);

        $this->insertDocApproval($approvedId, 'operational_plan', 'bod', 'approved');
        $this->insertDocApproval($approvedId, 'operational_plan', 'student_affairs', 'approved');
        $this->insertDocApproval($pendingId, 'budget_plan', 'bod', 'pending');
        $this->insertDocApproval($pendingId, 'budget_plan', 'student_affairs', 'pending');

        $client = $this->loginClient('admin@example.com', 'Password123!');
        $dashboard = $client->request('GET', '/api/dashboard?entity_id=' . $entityId);
        $this->assertSame(200, $dashboard['status']);
        $this->assertSame(1, $dashboard['data']['data']['doc_progress_approved']);
        $this->assertSame(2, $dashboard['data']['data']['doc_progress_total']);
        $this->assertSame(50, $dashboard['data']['data']['doc_progress']);
        $deadlineNames = array_column($dashboard['data']['data']['deadlines'], 'name');
        $this->assertContains('Approved Docs', $deadlineNames);
        $this->assertNotContains('Pending Docs', $deadlineNames);
        $this->assertNotContains('No Dates', $deadlineNames);
    }

    public function testBoardDashboardCanPostFlagMatchesAnnouncementPermission(): void {
        $this->createUser('board@example.com', 'Password123!', 'board');
        $entityId = $this->createEntity('Board Dashboard Entity');

        $client = $this->loginClient('board@example.com', 'Password123!');
        $dashboard = $client->request('GET', '/api/dashboard?entity_id=' . $entityId);
        $this->assertSame(200, $dashboard['status']);
        $this->assertTrue((bool)$dashboard['data']['data']['can_post_announcements']);

        $announcement = $client->request('POST', '/api/announcements', [
            'entity_id' => $entityId,
            'title' => 'Board visible action',
            'message' => 'The dashboard flag should match backend permission.'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $announcement['status']);
    }

    public function testDrivePermissionModelAndSharing(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $ceoId = $this->createUser('ceo@example.com', 'Password123!', 'ceo');
        $execId = $this->createUser('exec@example.com', 'Password123!', 'staff');
        $otherId = $this->createUser('other@example.com', 'Password123!', 'staff');

        $entityId = $this->createEntity('Entity One');
        $this->addMembership($entityId, $ceoId, 'management', 'manager');
        $this->addMembership($entityId, $execId, 'operations', 'executive');

        $itemId = $this->createDriveItem($entityId, 'Private Doc', 'private', $ceoId);

        $ceoClient = $this->loginClient('ceo@example.com', 'Password123!');
        $ceoList = $ceoClient->request('GET', '/api/drive/list?entity_id=' . $entityId);
        $this->assertSame(200, $ceoList['status']);
        $this->assertCount(1, $ceoList['data']['data']);

        $execClient = $this->loginClient('exec@example.com', 'Password123!');
        $execList = $execClient->request('GET', '/api/drive/list?entity_id=' . $entityId);
        $this->assertSame(200, $execList['status']);
        $this->assertCount(0, $execList['data']['data']);

        $share = $ceoClient->request('POST', '/api/drive/share', [
            'id' => $itemId,
            'sharing_scope' => 'users',
            'users' => [['user_id' => $otherId]]
        ], ["X-CSRF-Token: {$ceoClient->csrfToken}"]);
        $this->assertSame(200, $share['status']);

        $otherClient = $this->loginClient('other@example.com', 'Password123!');
        $item = $otherClient->request('GET', '/api/drive/item?id=' . $itemId);
        $this->assertSame(200, $item['status']);
        $this->assertSame('Private Doc', $item['data']['data']['name']);


        $adminClient = $this->loginClient('admin@example.com', 'Password123!');
        $adminList = $adminClient->request('GET', '/api/drive/list?entity_id=' . $entityId);
        $this->assertSame(200, $adminList['status']);
        $this->assertCount(1, $adminList['data']['data']);
    }

    public function testDriveFolderNavigationRenameDeleteAndLink(): void {
        $userId = $this->createUser('manager@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Entity Two');
        $this->addMembership($entityId, $userId, 'management', 'manager');
        $client = $this->loginClient('manager@example.com', 'Password123!');

        $folder = $client->request('POST', '/api/drive/folder', [
            'entity_id' => $entityId,
            'name' => 'Projects'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $folder['status']);
        $folderId = (int)$folder['data']['data']['id'];

        $link = $client->request('POST', '/api/drive/link', [
            'entity_id' => $entityId,
            'parent_id' => $folderId,
            'name' => 'Portal Link',
            'url' => 'https://example.com/docs'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $link['status']);
        $itemId = (int)$link['data']['data']['id'];

        $list = $client->request('GET', '/api/drive/list?entity_id=' . $entityId . '&parent_id=' . $folderId);
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['data']['data']);

        $rename = $client->request('POST', '/api/drive/rename', [
            'id' => $itemId,
            'name' => 'Portal Link Updated'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $rename['status']);

        $preview = $client->request('GET', '/api/drive/preview?id=' . $itemId);
        $this->assertSame(200, $preview['status']);
        $this->assertSame('link', $preview['data']['data']['kind']);

        $deleteFolder = $client->request('POST', '/api/drive/delete', ['id' => $folderId], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $deleteFolder['status']);

        $afterDelete = $client->request('GET', '/api/drive/item?id=' . $itemId);
        $this->assertSame(404, $afterDelete['status']);
    }

    private function createEntity(string $name): int {
        $stmt = db()->prepare('INSERT INTO entities (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int)db()->lastInsertId();
    }

    private function addMembership(int $entityId, int $userId, string $department, string $role): void {
        $stmt = db()->prepare('INSERT INTO entity_memberships (entity_id, user_id, department, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$entityId, $userId, $department, $role]);
    }

    private function createDriveItem(int $entityId, string $name, string $scope, int $creator): int {
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, item_type, name, sharing_scope, created_by) VALUES (?, "file", ?, ?, ?)');
        $stmt->execute([$entityId, $name, $scope, $creator]);
        return (int)db()->lastInsertId();
    }

    private function createEndeavour(int $entityId, int $creatorId, string $name, string $phase, bool $volunteeringEnabled, ?string $deadlineModifier): int {
        $deadline = $deadlineModifier ? (new DateTimeImmutable($deadlineModifier))->format('Y-m-d H:i:s') : null;
        $stmt = db()->prepare('INSERT INTO endeavours (entity_id, created_by, name, phase, volunteering_enabled, volunteer_registration_deadline, event_start_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, "draft")');
        $stmt->execute([
            $entityId,
            $creatorId,
            $name,
            $phase,
            $volunteeringEnabled ? 1 : 0,
            $deadline,
            $deadline
        ]);
        return (int)db()->lastInsertId();
    }

    private function setEndeavourPhase(int $endeavourId, string $phase): void {
        $stmt = db()->prepare('UPDATE endeavours SET phase = ? WHERE id = ?');
        $stmt->execute([$phase, $endeavourId]);
    }

    private function setEndeavourTransportFeeRequired(int $endeavourId): void {
        $stmt = db()->prepare('UPDATE endeavours SET transport_fee_required = 1 WHERE id = ?');
        $stmt->execute([$endeavourId]);
    }

    private function createRegistration(int $endeavourId, int $entityId, int $userId): int {
        $stmt = db()->prepare('INSERT INTO volunteer_registrations (endeavour_id, entity_id, user_id) VALUES (?, ?, ?)');
        $stmt->execute([$endeavourId, $entityId, $userId]);
        return (int)db()->lastInsertId();
    }

    private function createVolunteerPost(int $endeavourId, int $creatorId): int {
        $stmt = db()->prepare('INSERT INTO volunteer_posts (endeavour_id, description, created_by, published) VALUES (?, ?, ?, 1)');
        $stmt->execute([$endeavourId, 'Volunteer support', $creatorId]);
        return (int)db()->lastInsertId();
    }

    private function createStudent(int $userId, string $studentNumber): int {
        $stmt = db()->prepare('INSERT INTO students (user_id, student_id, parent_email) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $studentNumber, 'parent@example.com']);
        return (int)db()->lastInsertId();
    }

    private function createVolunteerApplication(int $postId, int $studentId): int {
        $stmt = db()->prepare('INSERT INTO volunteer_applications (volunteer_post_id, student_id, answers_json) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $studentId, '{}']);
        return (int)db()->lastInsertId();
    }

    private function createAttendance(int $applicationId): void {
        $stmt = db()->prepare('INSERT INTO attendance (volunteer_application_id, attendance_date) VALUES (?, ?)');
        $stmt->execute([$applicationId, '2026-05-01']);
    }

    private function insertDocApproval(int $endeavourId, string $docType, string $approverGroup, string $status): void {
        $stmt = db()->prepare('INSERT INTO endeavour_doc_approvals (endeavour_id, doc_type, approver_group, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$endeavourId, $docType, $approverGroup, $status]);
    }

    private function loginClient(string $email, string $password): object {
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';
        $login = $client->request('POST', '/api/auth/login', [
            'email' => $email,
            'password' => $password
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(200, $login['status']);
        return new class($client, $token) {
            public TestClient $client;
            public string $csrfToken;
            public function __construct(TestClient $client, string $csrfToken) {
                $this->client = $client;
                $this->csrfToken = $csrfToken;
            }
            public function request(string $method, string $path, ?array $body = null, array $headers = []): array {
                return $this->client->request($method, $path, $body, $headers);
            }
        };
    }

    private function createUser(string $email, string $password, string $role): int {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return $this->createUserWithHash($email, $hash, $role);
    }

    private function createUserWithHash(string $email, ?string $hash, string $role): int {
        $stmt = db()->prepare('INSERT INTO users (email, password_hash, full_name, global_role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$email, $hash, ucfirst($role), $role]);
        return (int)db()->lastInsertId();
    }

    private static function startServer(): void {
        $base = parse_url(self::$baseUrl);
        $host = $base['host'] ?? '127.0.0.1';
        $port = $base['port'] ?? 8001;

        // Resolve absolute path for env file to ensure child process finds it regardless of CWD
        $envPath = getenv('ENV_FILE_PATH') ?: '.env.testing';
        if (!str_starts_with($envPath, '/')) {
            $envPath = dirname(__DIR__) . '/' . $envPath;
        }
        $envPath = realpath($envPath) ?: $envPath;

        $phpIni = realpath(__DIR__ . '/../php.ini');
        $phpConfigArg = $phpIni ? ('-c ' . escapeshellarg($phpIni) . ' ') : '';

        $router = dirname(__DIR__) . '/router.php';
        $command = sprintf('php %s-S %s:%d -t %s %s',
            $phpConfigArg,
            $host,
            $port,
            escapeshellarg(dirname(__DIR__)),
            escapeshellarg($router)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/nixor_test_server.log', 'a'],
            2 => ['file', sys_get_temp_dir() . '/nixor_test_server.err', 'a']
        ];

        $env = array_filter(array_merge($_ENV, $_SERVER), fn($v) => is_scalar($v));
        $env['ENV_FILE_PATH'] = $envPath;

        self::$serverProcess = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env);
        if (!is_resource(self::$serverProcess)) {
            throw new RuntimeException('Failed to start test server');
        }
        $started = false;
        for ($i = 0; $i < 20; $i++) {
            usleep(200000);
            $client = new TestClient(self::$baseUrl);
            try {
                $response = $client->request('GET', '/api/auth/csrf');
                if ($response['status'] === 200) {
                    $started = true;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        if (!$started) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            throw new RuntimeException('Test server did not start');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
    }
}
