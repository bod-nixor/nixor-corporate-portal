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

        $duplicateEntity = $adminClient->request('POST', '/api/admin/entities', [
            'name' => 'admin alias entity',
            'description' => 'Duplicate casing'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(409, $duplicateEntity['status']);

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
            'event_date' => '2026-05-01T12:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $valid['status']);

        $stmt = db()->prepare('INSERT INTO calendar_events (entity_id, title, event_date, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$entityId, 'Legacy Bad Event', '1111-11-01 11:11:00', $adminId]);

        $list = $client->request('GET', '/api/calendar?entity_id=' . $entityId);
        $this->assertSame(200, $list['status']);
        $titles = array_column($list['data']['data'], 'title');
        $this->assertContains('Valid Calendar Event', $titles);
        $this->assertNotContains('Legacy Bad Event', $titles);
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
        $this->assertNotContains('No Dates', $deadlineNames);
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
