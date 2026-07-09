<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestClient.php';
require_once __DIR__ . '/../api/routes/auth.php';

final class ApiTest extends TestCase {
    private static $serverProcess;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void {
        $_ENV['NCP_API_SHARED_SECRET'] = 'test-connect-shared-secret';
        $_SERVER['NCP_API_SHARED_SECRET'] = 'test-connect-shared-secret';
        putenv('NCP_API_SHARED_SECRET=test-connect-shared-secret');
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
        $mailLog = rtrim((string)env_value('LOG_PATH', sys_get_temp_dir()), "\\/") . '/mail.log';
        if (is_file($mailLog)) {
            unlink($mailLog);
        }
    }

    public function testLegalPagesAreReachableLoggedOutAndLinkedAfterLogin(): void {
        $client = new TestClient(self::$baseUrl);
        $privacy = $client->request('GET', '/privacy.html');
        $terms = $client->request('GET', '/terms.html');

        $this->assertSame(200, $privacy['status']);
        $this->assertSame(200, $terms['status']);
        $this->assertStringContainsString('Privacy Policy', $privacy['body']);
        $this->assertStringContainsString('Terms &amp; Conditions', $terms['body']);

        $this->createUser('legal-admin@example.com', 'Password123!', 'admin');
        $loggedIn = $this->loginClient('legal-admin@example.com', 'Password123!');
        $settings = $loggedIn->client->request('GET', '/settings.html');
        $sidebar = $loggedIn->client->request('GET', '/assets/sidebar.js');

        $this->assertSame(200, $settings['status']);
        $this->assertSame(200, $sidebar['status']);
        $this->assertStringContainsString('Privacy Policy', $settings['body']);
        $this->assertStringContainsString('Terms &amp; Conditions', $settings['body']);
        $this->assertStringContainsString('/privacy.html', $sidebar['body']);
        $this->assertStringContainsString('/terms.html', $sidebar['body']);
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

    public function testNonNixorEmailPasswordLoginWorks(): void {
        $this->createUser('external.partner@example.com', 'Password123!', 'staff');
        $client = $this->loginClient('external.partner@example.com', 'Password123!');
        $me = $client->request('GET', '/api/auth/me');
        $this->assertSame(200, $me['status']);
        $this->assertSame('external.partner@example.com', $me['data']['data']['user']['email'] ?? null);
    }

    public function testForgotPasswordIsGenericAndStoresOnlyTokenHash(): void {
        $this->createUser('reset-user@example.com', 'Password123!', 'staff');
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';

        $existing = $client->request('POST', '/api/auth/forgot-password', [
            'email' => 'reset-user@example.com'
        ], ["X-CSRF-Token: {$token}"]);
        $missing = $client->request('POST', '/api/auth/forgot-password', [
            'email' => 'missing-user@example.com'
        ], ["X-CSRF-Token: {$token}"]);

        $this->assertSame(200, $existing['status']);
        $this->assertSame(200, $missing['status']);
        $this->assertSame($existing['data']['data']['message'], $missing['data']['data']['message']);
        $this->assertSame('If an account exists, a reset link has been sent.', $existing['data']['data']['message']);

        $tokens = db()->query('SELECT token_hash, token_type, used_at FROM auth_tokens')->fetchAll();
        $this->assertCount(1, $tokens);
        $this->assertSame('password_reset', $tokens[0]['token_type']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokens[0]['token_hash']);
        $this->assertNull($tokens[0]['used_at']);

        $mailLog = rtrim((string)env_value('LOG_PATH', sys_get_temp_dir()), "\\/") . '/mail.log';
        $this->assertFileExists($mailLog);
        $mail = file_get_contents($mailLog);
        $this->assertStringContainsString('reset-user@example.com', $mail);
        $this->assertStringNotContainsString($tokens[0]['token_hash'], $mail);
    }

    public function testPasswordResetTokenValidationExpiryReuseAndStrength(): void {
        $userId = $this->createUser('token-user@example.com', 'Password123!', 'staff');
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $csrfToken = $csrf['data']['data']['csrfToken'] ?? '';

        $invalid = $client->request('POST', '/api/auth/reset-password/validate', [
            'token' => 'not-a-valid-token'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(400, $invalid['status']);

        $expiredToken = $this->passwordResetToken();
        $this->createAuthToken($userId, $expiredToken, 'password_reset', '-5 minutes');
        $expired = $client->request('POST', '/api/auth/reset-password', [
            'token' => $expiredToken,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(400, $expired['status']);

        $usableToken = $this->passwordResetToken();
        $this->createAuthToken($userId, $usableToken);
        $weak = $client->request('POST', '/api/auth/reset-password', [
            'token' => $usableToken,
            'password' => 'short',
            'password_confirmation' => 'short'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(400, $weak['status']);
        $this->assertSame('Password does not meet security requirements', $weak['data']['error']);

        db()->prepare('INSERT INTO mobile_sessions (user_id, token_hash, platform, expires_at) VALUES (?, ?, "ios", UTC_TIMESTAMP() + INTERVAL 1 DAY)')
            ->execute([$userId, hash('sha256', 'mobile-token-to-revoke')]);
        db()->prepare('UPDATE users SET force_password_reset = 1, password_setup_required = 1 WHERE id = ?')->execute([$userId]);
        $success = $client->request('POST', '/api/auth/reset-password', [
            'token' => $usableToken,
            'password' => 'NewStrongPassword123!',
            'password_confirmation' => 'NewStrongPassword123!'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(200, $success['status']);

        $row = db()->prepare('SELECT password_hash, force_password_reset, password_setup_required, password_changed_at, session_version FROM users WHERE id = ?');
        $row->execute([$userId]);
        $user = $row->fetch();
        $this->assertTrue(password_verify('NewStrongPassword123!', $user['password_hash']));
        $this->assertSame(0, (int)$user['force_password_reset']);
        $this->assertSame(0, (int)$user['password_setup_required']);
        $this->assertNotEmpty($user['password_changed_at']);
        $this->assertSame(1, (int)$user['session_version']);

        $used = db()->prepare('SELECT used_at FROM auth_tokens WHERE token_hash = ?');
        $used->execute([hash('sha256', $usableToken)]);
        $this->assertNotEmpty($used->fetchColumn());
        $revoked = db()->query('SELECT revoked_at FROM mobile_sessions LIMIT 1')->fetchColumn();
        $this->assertNotEmpty($revoked);

        $reuse = $client->request('POST', '/api/auth/reset-password', [
            'token' => $usableToken,
            'password' => 'AnotherStrongPassword123!',
            'password_confirmation' => 'AnotherStrongPassword123!'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(400, $reuse['status']);
    }

    public function testForcedResetBlocksProtectedAccessAndSessionSetupClearsFlags(): void {
        $userId = $this->createUser('forced-admin@example.com', 'Password123!', 'admin');
        db()->prepare('UPDATE users SET force_password_reset = 1 WHERE id = ?')->execute([$userId]);
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $csrfToken = $csrf['data']['data']['csrfToken'] ?? '';
        $login = $client->request('POST', '/api/auth/login', [
            'email' => 'forced-admin@example.com',
            'password' => 'Password123!'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(200, $login['status']);
        $this->assertTrue((bool)($login['data']['data']['requires_password_setup'] ?? false));
        $this->assertSame('/reset_password.html?mode=session', $login['data']['data']['redirect'] ?? null);

        $blocked = $client->request('GET', '/api/admin/summary');
        $this->assertSame(403, $blocked['status']);
        $this->assertSame('Password setup required', $blocked['data']['error']);

        $setup = $client->request('POST', '/api/auth/password/setup', [
            'password' => 'SessionStrongPassword123!',
            'password_confirmation' => 'SessionStrongPassword123!'
        ], ["X-CSRF-Token: {$csrfToken}"]);
        $this->assertSame(200, $setup['status']);

        $allowed = $client->request('GET', '/api/admin/summary');
        $this->assertSame(200, $allowed['status']);
        $flags = db()->prepare('SELECT force_password_reset, password_setup_required FROM users WHERE id = ?');
        $flags->execute([$userId]);
        $row = $flags->fetch();
        $this->assertSame(0, (int)$row['force_password_reset']);
        $this->assertSame(0, (int)$row['password_setup_required']);
    }

    public function testGoogleDomainRestrictionForSso(): void {
        $this->assertSame(['nixorcollege.edu.pk'], allowed_google_domains());
        $this->expectException(AuthRouteException::class);
        try {
            find_or_create_google_user([
                'sub' => 'external-google-id',
                'email' => 'external@example.com',
                'email_verified' => true,
                'name' => 'External User',
            ]);
        } catch (AuthRouteException $e) {
            $this->assertSame(403, $e->status());
            $this->assertSame('domain_not_allowed', $e->clientErrorCode());
            throw $e;
        }
    }

    public function testNixorGoogleSsoCanResolveExistingUser(): void {
        $userId = $this->createUser('student@nixorcollege.edu.pk', 'Password123!', 'staff');
        $user = find_or_create_google_user([
            'sub' => 'nixor-google-id',
            'email' => 'student@nixorcollege.edu.pk',
            'email_verified' => true,
            'name' => 'Nixor Student',
        ]);
        $this->assertSame($userId, (int)$user['id']);
        $this->assertSame('nixor-google-id', $user['google_id']);
    }

    public function testForgotPasswordRateLimitBlocksAbuse(): void {
        $client = new TestClient(self::$baseUrl);
        $csrf = $client->request('GET', '/api/auth/csrf');
        $token = $csrf['data']['data']['csrfToken'] ?? '';
        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $client->request('POST', '/api/auth/forgot-password', [
                'email' => "abuse{$i}@example.com"
            ], ["X-CSRF-Token: {$token}"]);
        }
        $this->assertSame(429, $last['status']);
    }

    public function testMobilePasswordLoginIssuesBearerTokenAndRevokesIt(): void {
        $this->createUser('mobile-admin@example.com', 'Password123!', 'admin');
        $entityId = $this->createEntity('Mobile Token Entity');
        $client = new TestClient(self::$baseUrl);

        $bad = $client->request('POST', '/api/auth/mobile/login', [
            'email' => 'mobile-admin@example.com',
            'password' => 'WrongPassword123!',
            'platform' => 'ios'
        ]);
        $this->assertSame(401, $bad['status']);

        $login = $client->request('POST', '/api/auth/mobile/login', [
            'email' => 'mobile-admin@example.com',
            'password' => 'Password123!',
            'platform' => 'ios'
        ]);
        $this->assertSame(200, $login['status']);
        $token = $login['data']['data']['token'] ?? '';
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,256}$/', $token);
        $this->assertNotEmpty($login['data']['data']['expiresAt'] ?? '');

        $stored = db()->prepare('SELECT token_hash, platform, revoked_at FROM mobile_sessions WHERE token_hash = ?');
        $stored->execute([hash('sha256', $token)]);
        $session = $stored->fetch();
        $this->assertNotFalse($session);
        $this->assertSame(hash('sha256', $token), $session['token_hash']);
        $this->assertSame('ios', $session['platform']);
        $this->assertNull($session['revoked_at']);

        $rawLookup = db()->prepare('SELECT COUNT(*) FROM mobile_sessions WHERE token_hash = ?');
        $rawLookup->execute([$token]);
        $this->assertSame(0, (int)$rawLookup->fetchColumn());

        $me = $client->request('GET', '/api/auth/me', null, ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $me['status']);
        $this->assertSame('mobile-admin@example.com', $me['data']['data']['user']['email'] ?? null);

        $withoutBearer = $client->request('GET', '/api/auth/me');
        $this->assertSame(200, $withoutBearer['status']);
        $this->assertNull($withoutBearer['data']['data']['user']);

        $announcement = $client->request('POST', '/api/announcements', [
            'entity_id' => $entityId,
            'title' => 'Mobile bearer post',
            'message' => 'Bearer auth can protect native mutating calls without CSRF.'
        ], ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $announcement['status']);

        $missingTokenLogout = $client->request('POST', '/api/auth/mobile/logout');
        $this->assertSame(401, $missingTokenLogout['status']);

        $logout = $client->request('POST', '/api/auth/mobile/logout', null, ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $logout['status']);
        $secondLogout = $client->request('POST', '/api/auth/mobile/logout', null, ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $secondLogout['status']);
        $revoked = db()->prepare('SELECT revoked_at FROM mobile_sessions WHERE token_hash = ?');
        $revoked->execute([hash('sha256', $token)]);
        $this->assertNotEmpty($revoked->fetchColumn());

        $revokedMe = $client->request('GET', '/api/auth/me', null, ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $revokedMe['status']);
        $this->assertNull($revokedMe['data']['data']['user']);

        $secondLogin = $client->request('POST', '/api/auth/mobile/login', [
            'email' => 'mobile-admin@example.com',
            'password' => 'Password123!',
            'platform' => 'android'
        ]);
        $this->assertSame(200, $secondLogin['status']);
        $expiredToken = $secondLogin['data']['data']['token'] ?? '';
        db()->prepare('UPDATE mobile_sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token_hash = ?')
            ->execute([hash('sha256', $expiredToken)]);
        $expiredMe = $client->request('GET', '/api/auth/me', null, ["Authorization: Bearer {$expiredToken}"]);
        $this->assertSame(200, $expiredMe['status']);
        $this->assertNull($expiredMe['data']['data']['user']);
    }

    public function testMobileGoogleExchangeIssuesBearerTokenAndConsumesCodeOnce(): void {
        $userId = $this->createUser('google-mobile@example.com', 'Password123!', 'staff');
        $code = $this->mobileAuthCode();
        $insert = db()->prepare(
            'INSERT INTO mobile_auth_codes (user_id, code_hash, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 5 MINUTE)'
        );
        $insert->execute([$userId, hash('sha256', $code)]);

        $client = new TestClient(self::$baseUrl);
        $exchange = $client->request('POST', '/api/auth/mobile/exchange', [
            'code' => $code,
            'platform' => 'android'
        ]);
        $this->assertSame(200, $exchange['status']);
        $token = $exchange['data']['data']['token'] ?? '';
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,256}$/', $token);

        $platform = db()->prepare('SELECT platform FROM mobile_sessions WHERE token_hash = ?');
        $platform->execute([hash('sha256', $token)]);
        $this->assertSame('android', $platform->fetchColumn());

        $freshClient = new TestClient(self::$baseUrl);
        $me = $freshClient->request('GET', '/api/auth/me', null, ["Authorization: Bearer {$token}"]);
        $this->assertSame(200, $me['status']);
        $this->assertSame('google-mobile@example.com', $me['data']['data']['user']['email'] ?? null);

        $repeat = $freshClient->request('POST', '/api/auth/mobile/exchange', [
            'code' => $code,
            'platform' => 'android'
        ]);
        $this->assertSame(401, $repeat['status']);
        $this->assertSame('Mobile auth code already used', $repeat['data']['error']);
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
            'description' => 'Should be blocked before creation.',
            'venue' => 'Auditorium',
            'event_start_at' => '2026-05-01T12:00',
            'event_end_at' => '2026-05-01T14:00'
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
            'global_role' => 'staff',
            'status' => 'active',
            'send_invite' => true
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $user['status']);
        $newUserId = (int)$user['data']['data']['id'];
        $createdUser = db()->prepare('SELECT password_hash, force_password_reset, password_setup_required FROM users WHERE id = ?');
        $createdUser->execute([$newUserId]);
        $createdUserRow = $createdUser->fetch();
        $this->assertNull($createdUserRow['password_hash']);
        $this->assertSame(1, (int)$createdUserRow['force_password_reset']);
        $this->assertSame(1, (int)$createdUserRow['password_setup_required']);
        $setupTokens = db()->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ? AND token_type = "password_setup" AND used_at IS NULL');
        $setupTokens->execute([$newUserId]);
        $this->assertSame(1, (int)$setupTokens->fetchColumn());

        $manualPassword = $adminClient->request('POST', '/api/admin/users', [
            'email' => 'manual-password@example.com',
            'full_name' => 'Manual Password',
            'password' => 'AnotherPassword123!',
            'global_role' => 'staff'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(400, $manualPassword['status']);

        $duplicateUser = $adminClient->request('POST', '/api/admin/users', [
            'email' => 'new-user@example.com',
            'full_name' => 'Duplicate User',
            'global_role' => 'staff'
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(409, $duplicateUser['status']);

        $staffForce = $staffClient->request('POST', "/api/users/{$newUserId}/force-password-reset", [
            'send_email' => true
        ], ["X-CSRF-Token: {$staffClient->csrfToken}"]);
        $this->assertSame(403, $staffForce['status']);

        $adminForce = $adminClient->request('POST', "/api/users/{$newUserId}/force-password-reset", [
            'send_email' => true
        ], ["X-CSRF-Token: {$adminClient->csrfToken}"]);
        $this->assertSame(200, $adminForce['status']);
        $forced = db()->prepare('SELECT force_password_reset, password_reset_forced_by FROM users WHERE id = ?');
        $forced->execute([$newUserId]);
        $forcedRow = $forced->fetch();
        $this->assertSame(1, (int)$forcedRow['force_password_reset']);
        $this->assertSame(1, (int)$forcedRow['password_reset_forced_by']);
    }

    public function testConnectGoogleResolveServiceAuthAndEntitlementContract(): void {
        $client = new TestClient(self::$baseUrl);
        $body = [
            'google_sub' => 'google-sub-normal',
            'email' => 'connect.user@nixorcollege.edu.pk',
            'name' => 'Connect User',
            'picture' => 'https://example.com/avatar.png',
            'email_verified' => true,
        ];

        $missingAuth = $client->request('POST', '/api/connect/identity/resolve-google', $body);
        $this->assertSame(401, $missingAuth['status']);
        $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $missingAuth['data']);

        $badAuth = $client->request('POST', '/api/connect/identity/resolve-google', $body, ['Authorization: Bearer wrong-token']);
        $this->assertSame(401, $badAuth['status']);
        $this->assertSame(['ok' => false, 'error' => 'unauthorized'], $badAuth['data']);

        $unverified = $client->request('POST', '/api/connect/identity/resolve-google', [
            ...$body,
            'email_verified' => false,
        ], $this->connectServiceHeaders());
        $this->assertSame(403, $unverified['status']);
        $this->assertSame(['ok' => false, 'error' => 'not_allowed'], $unverified['data']);

        $unknown = $client->request('POST', '/api/connect/identity/resolve-google', $body, $this->connectServiceHeaders());
        $this->assertSame(403, $unknown['status']);
        $this->assertSame(['ok' => false, 'error' => 'not_allowed'], $unknown['data']);

        $this->createConnectIdentity('connect.user@nixorcollege.edu.pk', false, ['display_name' => 'Pending User']);
        $unapproved = $client->request('POST', '/api/connect/identity/resolve-google', $body, $this->connectServiceHeaders());
        $this->assertSame(403, $unapproved['status']);
        $this->assertSame(['ok' => false, 'error' => 'not_allowed'], $unapproved['data']);

        $this->createUser('suspended.connect@nixorcollege.edu.pk', 'Password123!', 'staff');
        db()->prepare('UPDATE users SET status = "suspended" WHERE email = ?')->execute(['suspended.connect@nixorcollege.edu.pk']);
        $this->createConnectIdentity('suspended.connect@nixorcollege.edu.pk', true, ['display_name' => 'Suspended Connect']);
        $suspended = $client->request('POST', '/api/connect/identity/resolve-google', [
            ...$body,
            'google_sub' => 'google-sub-suspended',
            'email' => 'suspended.connect@nixorcollege.edu.pk',
        ], $this->connectServiceHeaders());
        $this->assertSame(403, $suspended['status']);
        $this->assertSame(['ok' => false, 'error' => 'not_allowed'], $suspended['data']);

        db()->prepare('DELETE FROM connect_google_identities WHERE email = ?')->execute(['connect.user@nixorcollege.edu.pk']);
        $localUserId = $this->createUser('connect.user@nixorcollege.edu.pk', 'Password123!', 'staff');
        $identityId = $this->createConnectIdentity('connect.user@nixorcollege.edu.pk', true, ['display_name' => 'Connect User']);
        $this->addConnectMembership($identityId, 'srv_1de34dba73134a9fb62661a65fd1263e', 'moderator');

        $allowed = $client->request('POST', '/api/connect/identity/resolve-google', $body, $this->connectServiceHeaders());
        $this->assertSame(200, $allowed['status']);
        $this->assertSame(['ok', 'user'], array_keys($allowed['data']));
        $user = $allowed['data']['user'];
        $this->assertSame([
            'id',
            'email',
            'display_name',
            'matrix_user_id',
            'is_school_admin',
            'is_approved_developer',
            'developer_permissions',
            'owned_developer_app_ids',
            'memberships',
        ], array_keys($user));
        $this->assertSame((string)$localUserId, $user['id']);
        $this->assertSame('connect.user@nixorcollege.edu.pk', $user['email']);
        $this->assertSame('Connect User', $user['display_name']);
        $this->assertSame('@connect.user:connect.nixorcorporate.com', $user['matrix_user_id']);
        $this->assertFalse($user['is_school_admin']);
        $this->assertFalse($user['is_approved_developer']);
        $this->assertSame([], $user['developer_permissions']);
        $this->assertSame([], $user['owned_developer_app_ids']);
        $this->assertSame([
            ['server_public_id' => 'srv_1de34dba73134a9fb62661a65fd1263e', 'role' => 'moderator'],
        ], $user['memberships']);
        $boundSub = db()->prepare('SELECT google_sub FROM connect_google_identities WHERE id = ?');
        $boundSub->execute([$identityId]);
        $this->assertSame('google-sub-normal', $boundSub->fetchColumn());

        $devIdentityId = $this->createConnectIdentity('developer@nixorcollege.edu.pk', true, [
            'display_name' => 'Developer User',
            'matrix_user_id' => '@developer.manual:connect.nixorcorporate.com',
            'is_school_admin' => true,
            'is_approved_developer' => true,
            'developer_permissions' => ['apps:create', 'apps:manage:own', 'tokens:dangerous-scopes'],
            'owned_developer_app_ids' => ['app_alpha', 'app_beta'],
        ]);
        $this->addConnectMembership($devIdentityId, 'srv_developer', 'owner');
        $developer = $client->request('POST', '/api/connect/identity/resolve-google', [
            ...$body,
            'google_sub' => 'google-sub-developer',
            'email' => 'developer@nixorcollege.edu.pk',
            'name' => 'Google Developer Name',
        ], $this->connectServiceHeaders());

        $this->assertSame(200, $developer['status']);
        $developerUser = $developer['data']['user'];
        $this->assertTrue($developerUser['is_school_admin']);
        $this->assertTrue($developerUser['is_approved_developer']);
        $this->assertSame(['apps:create', 'apps:manage:own', 'tokens:dangerous-scopes'], $developerUser['developer_permissions']);
        $this->assertSame(['app_alpha', 'app_beta'], $developerUser['owned_developer_app_ids']);
        $this->assertSame('@developer.manual:connect.nixorcorporate.com', $developerUser['matrix_user_id']);
        $this->assertSame([['server_public_id' => 'srv_developer', 'role' => 'owner']], $developerUser['memberships']);
    }

    public function testAdminConnectEntitlementsValidateMembershipRolesAndTestResolve(): void {
        $this->createUser('admin@example.com', 'Password123!', 'admin');
        $admin = $this->loginClient('admin@example.com', 'Password123!');

        $invalidCreate = $admin->request('POST', '/api/admin/connect-entitlements', [
            'email' => 'invalid-role@nixorcollege.edu.pk',
            'display_name' => 'Invalid Role',
            'is_allowed' => true,
            'memberships' => [
                ['server_public_id' => 'srv_invalid_role', 'role' => 'manager'],
            ],
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(400, $invalidCreate['status']);
        $this->assertSame('Invalid membership role', $invalidCreate['data']['error']);

        $created = $admin->request('POST', '/api/admin/connect-entitlements', [
            'email' => 'admin-created@nixorcollege.edu.pk',
            'display_name' => 'Admin Created',
            'is_allowed' => true,
            'is_school_admin' => false,
            'is_approved_developer' => true,
            'developer_permissions' => ['apps:create', 'apps:manage:own'],
            'owned_developer_app_ids' => ['app_admin_created'],
            'memberships' => [
                ['server_public_id' => 'srv_admin_created', 'role' => 'member'],
            ],
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(200, $created['status']);
        $identityId = (int)$created['data']['data']['entitlement']['id'];

        $unchangedUpdate = $admin->request('PUT', '/api/admin/connect-entitlements/' . $identityId, [
            'email' => 'admin-created@nixorcollege.edu.pk',
            'display_name' => 'Admin Created',
            'is_allowed' => true,
            'is_school_admin' => false,
            'is_approved_developer' => true,
            'developer_permissions' => ['apps:create', 'apps:manage:own'],
            'owned_developer_app_ids' => ['app_admin_created'],
            'memberships' => [
                ['server_public_id' => 'srv_admin_created', 'role' => 'member'],
            ],
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(200, $unchangedUpdate['status']);

        $invalidUpdate = $admin->request('PUT', '/api/admin/connect-entitlements/' . $identityId, [
            'email' => 'admin-created@nixorcollege.edu.pk',
            'display_name' => 'Admin Created',
            'is_allowed' => true,
            'memberships' => [
                ['server_public_id' => 'srv_admin_created', 'role' => 'manager'],
            ],
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(400, $invalidUpdate['status']);
        $this->assertSame('Invalid membership role', $invalidUpdate['data']['error']);

        $testResolve = $admin->request('POST', '/api/admin/connect-entitlements/test-resolve', [
            'email' => 'admin-created@nixorcollege.edu.pk',
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(200, $testResolve['status']);
        $this->assertSame(200, $testResolve['data']['data']['status']);
        $this->assertTrue($testResolve['data']['data']['response']['ok']);
        $this->assertSame(['apps:create', 'apps:manage:own'], $testResolve['data']['data']['response']['user']['developer_permissions']);
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
        $userId = $this->createUser('exec@example.com', 'Password123!', 'ceo');
        $entityId = $this->createEntity('Strict Date Entity');
        $this->addMembership($entityId, $userId, 'management', 'manager');

        $client = $this->loginClient('exec@example.com', 'Password123!');
        $invalid = $client->request('POST', '/api/endeavours', [
            'entity_id' => $entityId,
            'name' => 'Impossible Date',
            'description' => 'Invalid date attempt.',
            'venue' => 'Auditorium',
            'event_start_at' => '2026-02-31T12:00',
            'event_end_at' => '2026-03-01T13:00'
        ], ["X-CSRF-Token: {$client->csrfToken}"]);

        $this->assertSame(400, $invalid['status']);
        $this->assertSame('Invalid datetime for event_start_at', $invalid['data']['error']);

        $valid = $client->request('POST', '/api/endeavours', [
            'entity_id' => $entityId,
            'name' => 'Normalized Date',
            'description' => 'Valid normalized date.',
            'venue' => 'Auditorium',
            'event_start_at' => '2026-03-01T12:00',
            'event_end_at' => '2026-03-01T13:00'
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

    public function testStudentAffairsCanReadApplicationsAndMarkAttendance(): void {
        $hrId = $this->createUser('hr@example.com', 'Password123!', 'student_affairs');
        $studentUserId = $this->createUser('student@example.com', 'Password123!', 'volunteer');
        $entityId = $this->createEntity('HR Entity');
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
        $this->addMembership($entityId, $userId, 'communications', 'executive');
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

    public function testEndeavourListReturnsEmptyStateAsSuccess(): void {
        $userId = $this->createUser('exec-empty@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Empty Endeavours Entity');
        $this->addMembership($entityId, $userId, 'operations', 'executive');

        $client = $this->loginClient('exec-empty@example.com', 'Password123!');
        $empty = $client->request('GET', '/api/endeavours?entity_id=' . $entityId);
        $this->assertSame(200, $empty['status']);
        $this->assertSame([], $empty['data']['data']);

        $endeavourId = $this->createEndeavour($entityId, $userId, 'Loaded Workflow', 'PRE_EVENT', false, '+5 days');
        $loaded = $client->request('GET', '/api/endeavours?entity_id=' . $entityId);
        $this->assertSame(200, $loaded['status']);
        $ids = array_map('intval', array_column($loaded['data']['data'], 'id'));
        $this->assertContains($endeavourId, $ids);
    }

    public function testCalendarManagerCanEditAndDeleteButMemberCannot(): void {
        $creatorId = $this->createUser('calendar-creator@example.com', 'Password123!', 'staff');
        $managerId = $this->createUser('calendar-manager@example.com', 'Password123!', 'staff');
        $memberId = $this->createUser('calendar-member@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Calendar Permissions Entity');
        $this->addMembership($entityId, $creatorId, 'operations', 'member');
        $this->addMembership($entityId, $managerId, 'management', 'manager');
        $this->addMembership($entityId, $memberId, 'operations', 'member');

        $eventId = $this->createCalendarEvent($entityId, $creatorId, 'Editable Event');
        $managerClient = $this->loginClient('calendar-manager@example.com', 'Password123!');
        $list = $managerClient->request('GET', '/api/calendar?entity_id=' . $entityId);
        $this->assertSame(200, $list['status']);
        $listedEvent = array_values(array_filter($list['data']['data'], fn($event) => (int)$event['id'] === $eventId))[0] ?? null;
        $this->assertTrue((bool)($listedEvent['can_manage'] ?? false));

        $update = $managerClient->request('PUT', '/api/calendar/' . $eventId, [
            'title' => 'Edited Event',
            'event_date' => '2026-06-01T10:00',
            'end_date' => '2026-06-01T11:00',
            'location' => 'Board Room',
            'description' => 'Updated by manager'
        ], ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $update['status']);

        $memberClient = $this->loginClient('calendar-member@example.com', 'Password123!');
        $blocked = $memberClient->request('DELETE', '/api/calendar/' . $eventId, null, ["X-CSRF-Token: {$memberClient->csrfToken}"]);
        $this->assertSame(403, $blocked['status']);

        $delete = $managerClient->request('DELETE', '/api/calendar/' . $eventId, null, ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $delete['status']);
    }

    public function testSocialModerationPermissionsForPostsAndComments(): void {
        $authorId = $this->createUser('social-author@example.com', 'Password123!', 'staff');
        $commenterId = $this->createUser('social-commenter@example.com', 'Password123!', 'staff');
        $managerId = $this->createUser('social-manager@example.com', 'Password123!', 'staff');
        $memberId = $this->createUser('social-member@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Social Permissions Entity');
        $this->addMembership($entityId, $authorId, 'operations', 'member');
        $this->addMembership($entityId, $commenterId, 'operations', 'member');
        $this->addMembership($entityId, $managerId, 'communications', 'manager');
        $this->addMembership($entityId, $memberId, 'operations', 'member');

        $postId = $this->createSocialPost($entityId, $authorId, 'Original post');
        $commentId = $this->createSocialComment($postId, $commenterId, 'Original reply');

        $managerClient = $this->loginClient('social-manager@example.com', 'Password123!');
        $feed = $managerClient->request('GET', '/api/social?entity_id=' . $entityId);
        $this->assertSame(200, $feed['status']);
        $listedPost = array_values(array_filter($feed['data']['data']['posts'], fn($post) => (int)$post['id'] === $postId))[0] ?? null;
        $listedComment = array_values(array_filter($feed['data']['data']['comments'], fn($comment) => (int)$comment['id'] === $commentId))[0] ?? null;
        $this->assertTrue((bool)($listedPost['can_manage'] ?? false));
        $this->assertTrue((bool)($listedComment['can_manage'] ?? false));

        $postUpdate = $managerClient->request('PUT', '/api/social/' . $postId, [
            'content' => 'Moderated post'
        ], ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $postUpdate['status']);

        $commentUpdate = $managerClient->request('PUT', '/api/social/comments/' . $commentId, [
            'comment' => 'Moderated reply'
        ], ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $commentUpdate['status']);

        $memberClient = $this->loginClient('social-member@example.com', 'Password123!');
        $blockedPost = $memberClient->request('DELETE', '/api/social/' . $postId, null, ["X-CSRF-Token: {$memberClient->csrfToken}"]);
        $this->assertSame(403, $blockedPost['status']);

        $ownPostId = $this->createSocialPost($entityId, $authorId, 'Author owned post');
        $ownCommentId = $this->createSocialComment($ownPostId, $commenterId, 'Commenter owned reply');

        $authorClient = $this->loginClient('social-author@example.com', 'Password123!');
        $authorFeed = $authorClient->request('GET', '/api/social?entity_id=' . $entityId);
        $this->assertSame(200, $authorFeed['status']);
        $listedOwnPost = array_values(array_filter($authorFeed['data']['data']['posts'], fn($post) => (int)$post['id'] === $ownPostId))[0] ?? null;
        $this->assertTrue((bool)($listedOwnPost['can_manage'] ?? false));

        $commenterClient = $this->loginClient('social-commenter@example.com', 'Password123!');
        $commenterFeed = $commenterClient->request('GET', '/api/social?entity_id=' . $entityId);
        $this->assertSame(200, $commenterFeed['status']);
        $listedOwnComment = array_values(array_filter($commenterFeed['data']['data']['comments'], fn($comment) => (int)$comment['id'] === $ownCommentId))[0] ?? null;
        $this->assertTrue((bool)($listedOwnComment['can_manage'] ?? false));

        $blockedOwnComment = $memberClient->request('DELETE', '/api/social/comments/' . $ownCommentId, null, ["X-CSRF-Token: {$memberClient->csrfToken}"]);
        $this->assertSame(403, $blockedOwnComment['status']);
        $deleteOwnComment = $commenterClient->request('DELETE', '/api/social/comments/' . $ownCommentId, null, ["X-CSRF-Token: {$commenterClient->csrfToken}"]);
        $this->assertSame(200, $deleteOwnComment['status']);
        $deleteOwnPost = $authorClient->request('DELETE', '/api/social/' . $ownPostId, null, ["X-CSRF-Token: {$authorClient->csrfToken}"]);
        $this->assertSame(200, $deleteOwnPost['status']);

        $deleteComment = $managerClient->request('DELETE', '/api/social/comments/' . $commentId, null, ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $deleteComment['status']);
        $deletePost = $managerClient->request('DELETE', '/api/social/' . $postId, null, ["X-CSRF-Token: {$managerClient->csrfToken}"]);
        $this->assertSame(200, $deletePost['status']);
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

    public function testNotificationsExposeReadableContentFromPayload(): void {
        $userId = $this->createUser('notify-user@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Notification Entity');
        $endeavourId = $this->createEndeavour($entityId, $userId, 'Long Community Service Endeavour', 'PRE_EVENT', false, '+2 days');
        db()->prepare('INSERT INTO notifications (user_id, type, payload_json) VALUES (?, ?, ?)')
            ->execute([
                $userId,
                'submission_pending_mob',
                json_encode([
                    'endeavour_id' => $endeavourId,
                    'doc_type' => 'budget_plan',
                    'message' => 'Submission awaiting Member of Board approval',
                ])
            ]);

        $client = $this->loginClient('notify-user@example.com', 'Password123!');
        $notifications = $client->request('GET', '/api/notifications?limit=5');
        $this->assertSame(200, $notifications['status']);
        $first = $notifications['data']['data'][0] ?? [];
        $this->assertNotSame('Notification', $first['title'] ?? '');
        $this->assertStringContainsString('Budget plan', $first['message'] ?? '');
        $this->assertStringContainsString('Long Community Service Endeavour', $first['message'] ?? '');
        $this->assertSame('/endeavour_view.html?id=' . $endeavourId, $first['target_url'] ?? null);
    }

    public function testAnnouncementEditAndDeleteRequireAnnouncementPermission(): void {
        $managerId = $this->createUser('announce-manager@example.com', 'Password123!', 'staff');
        $memberId = $this->createUser('announce-member@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Announcement RBAC Entity');
        $this->addMembership($entityId, $managerId, 'management', 'manager');
        $this->addMembership($entityId, $memberId, 'operations', 'member');

        db()->prepare('INSERT INTO dashboard_announcements (entity_id, title, message, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$entityId, 'Original title', 'Original message', $memberId]);
        $announcementId = (int)db()->lastInsertId();

        $member = $this->loginClient('announce-member@example.com', 'Password123!');
        $blockedEdit = $member->request('PUT', '/api/announcements/' . $announcementId, [
            'title' => 'Blocked edit',
            'message' => 'Blocked message'
        ], ["X-CSRF-Token: {$member->csrfToken}"]);
        $this->assertSame(403, $blockedEdit['status']);
        $blockedDelete = $member->request('DELETE', '/api/announcements/' . $announcementId, null, ["X-CSRF-Token: {$member->csrfToken}"]);
        $this->assertSame(403, $blockedDelete['status']);

        $manager = $this->loginClient('announce-manager@example.com', 'Password123!');
        $edit = $manager->request('PUT', '/api/announcements/' . $announcementId, [
            'title' => 'Updated title',
            'message' => 'Updated message'
        ], ["X-CSRF-Token: {$manager->csrfToken}"]);
        $this->assertSame(200, $edit['status']);
        $this->assertSame('Updated title', $edit['data']['data']['title'] ?? null);
        $this->assertTrue((bool)($edit['data']['data']['can_edit'] ?? false));

        $list = $manager->request('GET', '/api/announcements?entity_id=' . $entityId . '&limit=5');
        $this->assertSame(200, $list['status']);
        $this->assertTrue((bool)($list['data']['data'][0]['can_delete'] ?? false));

        $delete = $manager->request('DELETE', '/api/announcements/' . $announcementId, null, ["X-CSRF-Token: {$manager->csrfToken}"]);
        $this->assertSame(200, $delete['status']);
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

    public function testCustomRbacRoleAssignmentControlsAuthMeNavigation(): void {
        $adminId = $this->createUser('rbac-admin@example.com', 'Password123!', 'admin');
        $staffId = $this->createUser('rbac-staff@example.com', 'Password123!', 'staff');
        $this->seedRbacPermissionCodes(['nav.admin', 'settings.view']);

        $admin = $this->loginClient('rbac-admin@example.com', 'Password123!');
        $role = $admin->request('POST', '/api/admin/roles', [
            'code' => 'custom_auditor',
            'name' => 'Custom Auditor',
            'scope' => 'global',
            'permissions' => ['nav.admin']
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(200, $role['status']);

        $assignment = $admin->request('POST', '/api/users/' . $staffId . '/roles', [
            'role_id' => (int)$role['data']['data']['id']
        ], ["X-CSRF-Token: {$admin->csrfToken}"]);
        $this->assertSame(200, $assignment['status']);

        $staff = $this->loginClient('rbac-staff@example.com', 'Password123!');
        $me = $staff->request('GET', '/api/auth/me');
        $this->assertSame(200, $me['status']);
        $this->assertContains('nav.admin', $me['data']['data']['permissions']);
        $navIds = array_column($me['data']['data']['navigation'], 'id');
        $this->assertContains('admin', $navIds);
        $this->assertNotContains('dashboard', $navIds);
    }

    public function testEndeavourDeadlinesApprovalsAndResubmissionRules(): void {
        $execId = $this->createUser('deadline-exec@example.com', 'Password123!', 'staff');
        $this->createUser('deadline-board@example.com', 'Password123!', 'board');
        $this->createUser('deadline-sa@example.com', 'Password123!', 'student_affairs');
        $entityId = $this->createEntity('Deadline Entity');
        $this->addMembership($entityId, $execId, 'hr', 'executive');
        $eventStart = (new DateTimeImmutable('+10 days'))->format('Y-m-d H:i:s');
        $eventEnd = (new DateTimeImmutable('+10 days +2 hours'))->format('Y-m-d H:i:s');
        $endeavourId = $this->createEndeavourWithDates($entityId, $execId, 'Deadline Workflow', $eventStart, $eventEnd);
        $fileId = $this->createDriveItem($entityId, 'Ops Plan', 'entity', $execId);
        $secondFileId = $this->createDriveItem($entityId, 'Ops Plan v2', 'entity', $execId);

        $period = db()->prepare('INSERT INTO corporate_periods (name, starts_at, ends_at, created_by) VALUES (?, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 30 DAY, ?)');
        $period->execute(['Current Period', $execId]);
        $periodId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO corporate_period_plan_deadlines (corporate_period_id, doc_type, due_at, is_tentative) VALUES (?, "operational_plan", NOW() + INTERVAL 5 DAY, 1)')->execute([$periodId]);
        db()->prepare('INSERT INTO corporate_period_plan_deadlines (corporate_period_id, doc_type, due_at, is_tentative) VALUES (?, "operational_plan", NOW() + INTERVAL 2 DAY, 0)')->execute([$periodId]);

        $exec = $this->loginClient('deadline-exec@example.com', 'Password123!');
        $submit = $exec->request('POST', "/api/endeavours/{$endeavourId}/submissions", [
            'doc_type' => 'operational_plan',
            'file_drive_item_id' => $fileId
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(200, $submit['status']);
        $submissionId = (int)$submit['data']['data']['id'];
        $storedDue = db()->prepare('SELECT due_at, is_overdue FROM endeavour_submissions WHERE id = ?');
        $storedDue->execute([$submissionId]);
        $firstSubmission = $storedDue->fetch();
        $this->assertSame(0, (int)$firstSubmission['is_overdue']);
        $this->assertNotEmpty($firstSubmission['due_at']);

        $sa = $this->loginClient('deadline-sa@example.com', 'Password123!');
        $saEarly = $sa->request('POST', "/api/endeavours/{$endeavourId}/submissions/{$submissionId}/approve", [
            'decision' => 'approved'
        ], ["X-CSRF-Token: {$sa->csrfToken}"]);
        $this->assertSame(400, $saEarly['status']);
        $this->assertSame('Member of Board approval is required first', $saEarly['data']['error']);

        $board = $this->loginClient('deadline-board@example.com', 'Password123!');
        $rejectMissingComment = $board->request('POST', "/api/endeavours/{$endeavourId}/submissions/{$submissionId}/approve", [
            'decision' => 'rejected'
        ], ["X-CSRF-Token: {$board->csrfToken}"]);
        $this->assertSame(400, $rejectMissingComment['status']);

        $boardReject = $board->request('POST', "/api/endeavours/{$endeavourId}/submissions/{$submissionId}/approve", [
            'decision' => 'rejected',
            'comment' => 'Please resubmit with venue details.'
        ], ["X-CSRF-Token: {$board->csrfToken}"]);
        $this->assertSame(200, $boardReject['status']);

        db()->prepare('UPDATE corporate_period_plan_deadlines SET due_at = NOW() - INTERVAL 1 DAY WHERE corporate_period_id = ? AND doc_type = "operational_plan"')->execute([$periodId]);
        $resubmit = $exec->request('POST', "/api/endeavours/{$endeavourId}/submissions", [
            'doc_type' => 'operational_plan',
            'file_drive_item_id' => $secondFileId
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(200, $resubmit['status']);
        $resubmissionId = (int)$resubmit['data']['data']['id'];
        $resubmission = db()->prepare('SELECT is_overdue, resubmission_of_id FROM endeavour_submissions WHERE id = ?');
        $resubmission->execute([$resubmissionId]);
        $row = $resubmission->fetch();
        $this->assertSame(0, (int)$row['is_overdue']);
        $this->assertSame($submissionId, (int)$row['resubmission_of_id']);

        $boardApprove = $board->request('POST', "/api/endeavours/{$endeavourId}/submissions/{$resubmissionId}/approve", [
            'decision' => 'approved'
        ], ["X-CSRF-Token: {$board->csrfToken}"]);
        $this->assertSame(200, $boardApprove['status']);
        $saApprove = $sa->request('POST', "/api/endeavours/{$endeavourId}/submissions/{$resubmissionId}/approve", [
            'decision' => 'approved'
        ], ["X-CSRF-Token: {$sa->csrfToken}"]);
        $this->assertSame(200, $saApprove['status']);
        $status = db()->prepare('SELECT status FROM endeavour_submissions WHERE id = ?');
        $status->execute([$resubmissionId]);
        $this->assertSame('approved', $status->fetchColumn());
    }

    public function testEndeavourEditApprovalAndDirectSubmissionLinkAuthorization(): void {
        $execId = $this->createUser('edit-exec@example.com', 'Password123!', 'staff');
        $otherId = $this->createUser('edit-other@example.com', 'Password123!', 'staff');
        $entityId = $this->createEntity('Edit Approval Entity');
        $this->addMembership($entityId, $execId, 'operations', 'executive');
        $endeavourId = $this->createEndeavourWithDates($entityId, $execId, 'Original Title', '2026-05-10 10:00:00', '2026-05-10 12:00:00');
        $fileId = $this->createDriveItem($entityId, 'Confidential Submission', 'private', $execId);
        db()->prepare('INSERT INTO endeavour_submissions (endeavour_id, doc_type, file_drive_item_id, version_no, submitted_by, status) VALUES (?, "operational_plan", ?, 1, ?, "approved")')
            ->execute([$endeavourId, $fileId, $execId]);
        $submissionId = (int)db()->lastInsertId();

        $exec = $this->loginClient('edit-exec@example.com', 'Password123!');
        $edit = $exec->request('PUT', '/api/endeavours/' . $endeavourId, [
            'name' => 'Changed Title'
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(200, $edit['status']);
        $this->assertTrue((bool)($edit['data']['data']['edit_approval_required'] ?? false));

        $other = $this->loginClient('edit-other@example.com', 'Password123!');
        $blocked = $other->request('GET', '/api/files/download?type=endeavour_submission&id=' . $submissionId);
        $this->assertSame(403, $blocked['status']);

        $authorized = $exec->request('GET', '/api/files/download?type=endeavour_submission&id=' . $submissionId);
        $this->assertNotSame(403, $authorized['status']);
    }

    public function testVolunteeringOpsAndCalendarParticipantsMinutes(): void {
        $opsId = $this->createUser('ops-sa@example.com', 'Password123!', 'student_affairs');
        $regularId = $this->createUser('ops-regular@example.com', 'Password123!', 'staff');
        $creatorId = $this->createUser('calendar-cco@example.com', 'Password123!', 'staff');
        $entityA = $this->createEntity('Calendar Entity A');
        $entityB = $this->createEntity('Calendar Entity B');
        $this->addMembership($entityA, $regularId, 'operations', 'member');
        $this->addMembership($entityA, $creatorId, 'communications', 'manager');
        $this->addMembership($entityB, $regularId, 'operations', 'member');
        $endeavourId = $this->createEndeavour($entityA, $creatorId, 'Ops Panel Event', 'ON_DAY', true, '+5 days');
        $this->setEndeavourTransportFeeRequired($endeavourId);
        $this->createStudent($regularId, 'OPS-REGULAR-1');
        $registrationId = $this->createRegistration($endeavourId, $entityA, $regularId);
        db()->prepare('UPDATE volunteer_registrations SET status = "shortlisted" WHERE id = ?')->execute([$registrationId]);

        $regular = $this->loginClient('ops-regular@example.com', 'Password123!');
        $blockedOps = $regular->request('GET', '/api/endeavours/volunteering_ops');
        $this->assertSame(403, $blockedOps['status']);

        $ops = $this->loginClient('ops-sa@example.com', 'Password123!');
        $opsList = $ops->request('GET', '/api/endeavours/volunteering_ops?student_id=ops-regular&entity_id=' . $entityA . '&q=Ops');
        $this->assertSame(200, $opsList['status']);
        $this->assertCount(1, $opsList['data']['data']);
        $mark = $ops->request('POST', '/api/endeavours/volunteering_ops', [
            'registration_id' => $registrationId,
            'attendance_status' => 'present',
            'transport_fee_paid' => true
        ], ["X-CSRF-Token: {$ops->csrfToken}"]);
        $this->assertSame(200, $mark['status']);

        $creator = $this->loginClient('calendar-cco@example.com', 'Password123!');
        $event = $creator->request('POST', '/api/calendar', [
            'entity_id' => $entityA,
            'participant_entity_ids' => [$entityA, $entityB],
            'title' => 'Cross Entity Meeting',
            'event_date' => '2026-05-11T10:00',
            'end_date' => '2026-05-11T11:00'
        ], ["X-CSRF-Token: {$creator->csrfToken}"]);
        $this->assertSame(200, $event['status']);
        $eventId = (int)$event['data']['data']['id'];

        $rsvp = $regular->request('POST', "/api/calendar/{$eventId}/rsvp", [
            'entity_id' => $entityB,
            'status' => 'absent',
            'absence_comment' => 'Class conflict'
        ], ["X-CSRF-Token: {$regular->csrfToken}"]);
        $this->assertSame(200, $rsvp['status']);

        $minutesFile = $this->createDriveItem($entityA, 'Meeting Minutes', 'entity', $creatorId);
        $minutes = $creator->request('POST', "/api/calendar/{$eventId}/minutes", [
            'entity_id' => $entityA,
            'file_drive_item_id' => $minutesFile
        ], ["X-CSRF-Token: {$creator->csrfToken}"]);
        $this->assertSame(200, $minutes['status']);

        $participantList = $regular->request('GET', '/api/calendar?entity_id=' . $entityB);
        $this->assertSame(200, $participantList['status']);
        $listed = array_values(array_filter($participantList['data']['data'], fn($row) => (int)$row['id'] === $eventId))[0] ?? null;
        $this->assertNotNull($listed);
        $this->assertNotEmpty($listed['minutes']);
    }

    public function testPublicGlobalSocialFeedAndAuthenticatedRestrictions(): void {
        $entityId = $this->createEntity('Global Entity');
        $authorId = $this->createUser('global-author@example.com', 'Password123!', 'ceo');
        $volunteerId = $this->createUser('global-volunteer@example.com', 'Password123!', 'staff');
        $this->addMembership($entityId, $authorId, 'management', 'manager');
        $this->addMembership($entityId, $volunteerId, 'operations', 'volunteer');

        $client = $this->loginClient('global-author@example.com', 'Password123!');
        $post = $client->request('POST', '/api/social', [
            'feed_scope' => 'global',
            'content' => '**Global** update https://example.com',
            'image_urls' => ['https://example.com/image.jpg']
        ], ["X-CSRF-Token: {$client->csrfToken}"]);
        $this->assertSame(200, $post['status']);
        $postId = (int)$post['data']['data']['id'];

        $public = (new TestClient(self::$baseUrl))->request('GET', '/api/public/social_global');
        $this->assertSame(200, $public['status']);
        $this->assertCount(1, $public['data']['data']['posts']);
        $this->assertStringContainsString('<strong>Global</strong>', $public['data']['data']['posts'][0]['safe_html']);

        $publicUnified = (new TestClient(self::$baseUrl))->request('GET', '/api/social/global');
        $this->assertSame(200, $publicUnified['status']);
        $this->assertCount(1, $publicUnified['data']['data']['posts']);
        $this->assertFalse((bool)$publicUnified['data']['meta']['permissions']['authenticated']);
        $this->assertFalse((bool)$publicUnified['data']['meta']['permissions']['can_like']);
        $this->assertArrayNotHasKey('user_id', $publicUnified['data']['data']['posts'][0]);

        $anonymous = new TestClient(self::$baseUrl);
        $anonymousCsrf = $anonymous->request('GET', '/api/auth/csrf');
        $anonymousToken = $anonymousCsrf['data']['data']['csrfToken'] ?? '';
        $anonymousPost = $anonymous->request('POST', '/api/social', [
            'feed_scope' => 'global',
            'content' => 'Blocked'
        ], ["X-CSRF-Token: {$anonymousToken}"]);
        $this->assertSame(401, $anonymousPost['status']);

        $anonymousLike = $anonymous->request('POST', "/api/social/{$postId}/like", [], ["X-CSRF-Token: {$anonymousToken}"]);
        $this->assertSame(401, $anonymousLike['status']);
        $anonymousComment = $anonymous->request('POST', "/api/social/{$postId}/comments", [
            'comment' => 'Blocked'
        ], ["X-CSRF-Token: {$anonymousToken}"]);
        $this->assertSame(401, $anonymousComment['status']);

        $volunteer = $this->loginClient('global-volunteer@example.com', 'Password123!');
        $volunteerPost = $volunteer->request('POST', '/api/social', [
            'feed_scope' => 'global',
            'content' => 'Volunteers cannot publish globally.'
        ], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(403, $volunteerPost['status']);
    }

    public function testSocialFeedBusinessPermissions(): void {
        $entityA = $this->createEntity('Entity Feed A');
        $entityB = $this->createEntity('Entity Feed B');
        $volunteerId = $this->createUser('feed-volunteer@example.com', 'Password123!', 'staff');
        $execId = $this->createUser('feed-exec@example.com', 'Password123!', 'staff');
        $otherExecId = $this->createUser('feed-other-exec@example.com', 'Password123!', 'staff');
        $ceoId = $this->createUser('feed-ceo@example.com', 'Password123!', 'ceo');

        $this->addMembership($entityA, $volunteerId, 'operations', 'volunteer');
        $this->addMembership($entityA, $execId, 'operations', 'executive');
        $this->addMembership($entityB, $otherExecId, 'operations', 'executive');
        $this->addMembership($entityA, $ceoId, 'management', 'manager');

        $publicEntityFeed = (new TestClient(self::$baseUrl))->request('GET', '/api/social?entity_id=' . $entityA);
        $this->assertSame(401, $publicEntityFeed['status']);

        $volunteer = $this->loginClient('feed-volunteer@example.com', 'Password123!');
        $volunteerFeed = $volunteer->request('GET', '/api/social?entity_id=' . $entityA);
        $this->assertSame(200, $volunteerFeed['status']);
        $this->assertFalse((bool)$volunteerFeed['data']['meta']['permissions']['can_post']);
        $this->assertTrue((bool)$volunteerFeed['data']['meta']['permissions']['can_interact']);

        $volunteerPost = $volunteer->request('POST', '/api/social', [
            'entity_id' => $entityA,
            'content' => 'Volunteer should not post.'
        ], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(403, $volunteerPost['status']);

        $exec = $this->loginClient('feed-exec@example.com', 'Password123!');
        $entityPost = $exec->request('POST', '/api/social', [
            'entity_id' => $entityA,
            'content' => 'Executive entity update.'
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(200, $entityPost['status']);
        $postId = (int)$entityPost['data']['data']['id'];

        $like = $volunteer->request('POST', "/api/social/{$postId}/like", [], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(200, $like['status']);
        $comment = $volunteer->request('POST', "/api/social/{$postId}/comments", [
            'comment' => 'Volunteer comment.'
        ], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(200, $comment['status']);

        $otherFeed = $volunteer->request('GET', '/api/social?entity_id=' . $entityB);
        $this->assertSame(403, $otherFeed['status']);

        $otherExec = $this->loginClient('feed-other-exec@example.com', 'Password123!');
        $otherPost = $otherExec->request('POST', '/api/social', [
            'entity_id' => $entityB,
            'content' => 'Other entity update.'
        ], ["X-CSRF-Token: {$otherExec->csrfToken}"]);
        $this->assertSame(200, $otherPost['status']);
        $otherPostId = (int)$otherPost['data']['data']['id'];

        $crossLike = $volunteer->request('POST', "/api/social/{$otherPostId}/like", [], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(403, $crossLike['status']);
        $crossComment = $volunteer->request('POST', "/api/social/{$otherPostId}/comments", [
            'comment' => 'Blocked cross-entity comment.'
        ], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(403, $crossComment['status']);

        $crossPost = $exec->request('POST', '/api/social', [
            'entity_id' => $entityB,
            'content' => 'Wrong entity update.'
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(403, $crossPost['status']);

        $nonCGlobal = $exec->request('POST', '/api/social', [
            'feed_scope' => 'global',
            'content' => 'Non-C-level global update.'
        ], ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(403, $nonCGlobal['status']);

        $ceo = $this->loginClient('feed-ceo@example.com', 'Password123!');
        $globalPost = $ceo->request('POST', '/api/social', [
            'feed_scope' => 'global',
            'content' => 'C-level global update.'
        ], ["X-CSRF-Token: {$ceo->csrfToken}"]);
        $this->assertSame(200, $globalPost['status']);
        $globalPostId = (int)$globalPost['data']['data']['id'];

        $globalLike = $volunteer->request('POST', "/api/social/{$globalPostId}/like", [], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(200, $globalLike['status']);
        $globalComment = $volunteer->request('POST', "/api/social/{$globalPostId}/comments", [
            'comment' => 'Visible global comment.'
        ], ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(200, $globalComment['status']);
        $globalCommentId = (int)$globalComment['data']['data']['id'];

        $blockedGlobalDelete = $exec->request('DELETE', "/api/social/{$globalPostId}", null, ["X-CSRF-Token: {$exec->csrfToken}"]);
        $this->assertSame(403, $blockedGlobalDelete['status']);
        $deleteGlobalComment = $volunteer->request('DELETE', "/api/social/comments/{$globalCommentId}", null, ["X-CSRF-Token: {$volunteer->csrfToken}"]);
        $this->assertSame(200, $deleteGlobalComment['status']);
        $deleteGlobalPost = $ceo->request('DELETE', "/api/social/{$globalPostId}", null, ["X-CSRF-Token: {$ceo->csrfToken}"]);
        $this->assertSame(200, $deleteGlobalPost['status']);
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

    private function createEndeavourWithDates(int $entityId, int $creatorId, string $name, string $eventStart, string $eventEnd): int {
        $stmt = db()->prepare('INSERT INTO endeavours (entity_id, created_by, name, description, venue, phase, volunteering_enabled, event_start_at, event_end_at, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, "PRE_EVENT", 0, ?, ?, ?, ?, "draft")');
        $stmt->execute([
            $entityId,
            $creatorId,
            $name,
            'Detailed description',
            'Auditorium',
            $eventStart,
            $eventEnd,
            substr($eventStart, 0, 10),
            substr($eventEnd, 0, 10)
        ]);
        return (int)db()->lastInsertId();
    }

    private function createCalendarEvent(int $entityId, int $creatorId, string $title): int {
        $stmt = db()->prepare('INSERT INTO calendar_events (entity_id, title, event_date, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$entityId, $title, '2026-05-01 12:00:00', $creatorId]);
        return (int)db()->lastInsertId();
    }

    private function createSocialPost(int $entityId, int $userId, string $content): int {
        $stmt = db()->prepare('INSERT INTO social_posts (entity_id, user_id, content) VALUES (?, ?, ?)');
        $stmt->execute([$entityId, $userId, $content]);
        return (int)db()->lastInsertId();
    }

    private function createSocialComment(int $postId, int $userId, string $comment): int {
        $stmt = db()->prepare('INSERT INTO social_comments (post_id, user_id, comment) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $userId, $comment]);
        return (int)db()->lastInsertId();
    }

    private function seedRbacPermissionCodes(array $codes): void {
        $stmt = db()->prepare('INSERT IGNORE INTO rbac_permissions (code, description) VALUES (?, ?)');
        foreach ($codes as $code) {
            $stmt->execute([$code, $code]);
        }
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

    private function mobileAuthCode(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function passwordResetToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function createAuthToken(int $userId, string $token, string $type = 'password_reset', string $expiresModifier = '+30 minutes'): int {
        $expiresAt = (new DateTimeImmutable($expiresModifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = db()->prepare(
            'INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at, created_ip)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, hash('sha256', $token), $expiresAt, '127.0.0.1']);
        return (int)db()->lastInsertId();
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

    private function connectServiceHeaders(string $token = 'test-connect-shared-secret'): array {
        return ["Authorization: Bearer {$token}"];
    }

    private function createConnectIdentity(string $email, bool $allowed, array $overrides = []): int {
        $stmt = db()->prepare(
            'INSERT INTO connect_google_identities
             (user_id, google_sub, email, display_name, matrix_user_id, is_allowed, is_school_admin, is_approved_developer, developer_permissions, owned_developer_app_ids)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $overrides['user_id'] ?? null,
            $overrides['google_sub'] ?? null,
            strtolower($email),
            $overrides['display_name'] ?? 'Connect User',
            $overrides['matrix_user_id'] ?? null,
            $allowed ? 1 : 0,
            !empty($overrides['is_school_admin']) ? 1 : 0,
            !empty($overrides['is_approved_developer']) ? 1 : 0,
            json_encode($overrides['developer_permissions'] ?? []),
            json_encode($overrides['owned_developer_app_ids'] ?? []),
        ]);
        return (int)db()->lastInsertId();
    }

    private function addConnectMembership(int $identityId, string $serverPublicId, string $role): int {
        $stmt = db()->prepare('INSERT INTO connect_user_memberships (identity_id, server_public_id, role) VALUES (?, ?, ?)');
        $stmt->execute([$identityId, $serverPublicId, $role]);
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
