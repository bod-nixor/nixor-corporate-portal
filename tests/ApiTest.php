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
        foreach ($tables as $table) {
            if ($table === 'migrations') {
                continue;
            }
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testCsrfBootstrapReturnsTokenAndSetsSessionCookie(): void {
        $client = new TestClient(self::$baseUrl);
        $response = $client->request('GET', '/api/auth/csrf');
        $this->assertSame(200, $response['status']);
        $token = $response['data']['data']['csrfToken'] ?? '';
        $this->assertNotEmpty($token);
        $sessionName = session_name();
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
        $sessionName = session_name();
        $beforeSession = $client->getCookie($sessionName);

        $login = $client->request('POST', '/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$token}"]);
        $this->assertSame(200, $login['status']);
        $afterSession = $client->getCookie($sessionName);
        $this->assertNotEmpty($afterSession);
        $this->assertNotSame($beforeSession, $afterSession);

        $logout = $client->request('POST', '/api/auth/logout', null, ["X-CSRF-Token: {$token}"]);
        $this->assertSame(200, $logout['status']);

        $me = $client->request('GET', '/api/auth/me');
        $this->assertNull($me['data']['data']['user']);
    }

    public function testProtectedRouteRequiresAuth(): void {
        $client = new TestClient(self::$baseUrl);
        $response = $client->request('GET', '/api/endeavours');
        $this->assertSame(401, $response['status']);
    }

    public function testEntityCrudAndAuthorization(): void {
        $adminId = $this->createUser('admin@example.com', 'Password123!', 'admin');
        $staffId = $this->createUser('staff@example.com', 'Password123!', 'staff');

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
        $this->assertSame(403, $blocked['status']);
        $this->assertSame('Forbidden', $blocked['data']['error']);
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

    private function createUser(string $email, string $password, string $role): int {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (email, password_hash, full_name, global_role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$email, $hash, ucfirst($role), $role]);
        return (int)db()->lastInsertId();
    }

    private static function startServer(): void {
        $base = parse_url(self::$baseUrl);
        $host = $base['host'] ?? '127.0.0.1';
        $port = $base['port'] ?? 8001;
        $command = sprintf('php -S %s:%d -t %s', $host, $port, escapeshellarg(dirname(__DIR__)));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/nixor_test_server.log', 'a'],
            2 => ['file', sys_get_temp_dir() . '/nixor_test_server.err', 'a']
        ];
        self::$serverProcess = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
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
            throw new RuntimeException('Test server did not start');
        }
    }
}
