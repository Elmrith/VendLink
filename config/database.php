<?php
// config/database.php
function ensureDatabaseSchema(PDO $pdo): void {
    $requiredTables = [
        'user_devices' => "CREATE TABLE IF NOT EXISTS user_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NOT NULL,
            deviceTokenHash CHAR(64) NOT NULL,
            deviceName VARCHAR(100) NULL,
            userAgent VARCHAR(255) NULL,
            ipAddress VARCHAR(45) NULL,
            lastSeenAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_devices_token (userId, deviceTokenHash),
            INDEX idx_user_devices_user_last_seen (userId, lastSeenAt),
            CONSTRAINT fk_user_devices_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
        )",
        'user_login_verifications' => "CREATE TABLE IF NOT EXISTS user_login_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NOT NULL,
            verificationCode CHAR(6) NOT NULL,
            purpose ENUM('login', 'email') NOT NULL DEFAULT 'login',
            ipAddress VARCHAR(45) NULL,
            userAgent VARCHAR(255) NULL,
            expiresAt DATETIME NOT NULL,
            isVerified TINYINT(1) NOT NULL DEFAULT 0,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_login_verifications_user_expiry (userId, expiresAt, isVerified),
            CONSTRAINT fk_user_login_verifications_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
        )",
        'cookie_preferences' => "CREATE TABLE IF NOT EXISTS cookie_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NULL,
            sessionId VARCHAR(128) NULL,
            essential TINYINT(1) NOT NULL DEFAULT 1,
            analytics TINYINT(1) NOT NULL DEFAULT 0,
            functional TINYINT(1) NOT NULL DEFAULT 0,
            marketing TINYINT(1) NOT NULL DEFAULT 0,
            consentSource VARCHAR(50) NOT NULL DEFAULT 'banner',
            policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cookie_preferences_user (userId),
            UNIQUE KEY uq_cookie_preferences_session (sessionId),
            INDEX idx_cookie_preferences_user (userId),
            INDEX idx_cookie_preferences_session (sessionId),
            CONSTRAINT fk_cookie_preferences_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
        )",
        'user_consents' => "CREATE TABLE IF NOT EXISTS user_consents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NULL,
            consentPurpose VARCHAR(80) NOT NULL,
            consentGranted TINYINT(1) NOT NULL DEFAULT 0,
            policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
            ipAddress VARCHAR(45) NULL,
            userAgent VARCHAR(255) NULL,
            consentDetails TEXT NULL,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_consents_user (userId, consentPurpose),
            INDEX idx_user_consents_purpose (consentPurpose, createdAt),
            CONSTRAINT fk_user_consents_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE
        )",
        'email_preferences' => "CREATE TABLE IF NOT EXISTS email_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NOT NULL,
            promotionalEmails TINYINT(1) NOT NULL DEFAULT 0,
            transactionalEmails TINYINT(1) NOT NULL DEFAULT 1,
            unsubscribeToken CHAR(64) NOT NULL UNIQUE,
            updatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_email_preferences_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_email_preferences_user (userId)
        )",
        'privacy_requests' => "CREATE TABLE IF NOT EXISTS privacy_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId VARCHAR(36) NULL,
            requestType VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            requestReason TEXT NULL,
            requestedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewedAt DATETIME NULL,
            reviewedBy VARCHAR(36) NULL,
            notes TEXT NULL,
            policyVersion VARCHAR(30) NOT NULL DEFAULT '2025-01',
            INDEX idx_privacy_requests_user (userId, status, requestedAt),
            INDEX idx_privacy_requests_status (status, requestedAt),
            CONSTRAINT fk_privacy_requests_user FOREIGN KEY (userId) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_privacy_requests_reviewer FOREIGN KEY (reviewedBy) REFERENCES users(id) ON DELETE SET NULL
        )",
        'policy_versions' => "CREATE TABLE IF NOT EXISTS policy_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(30) NOT NULL UNIQUE,
            title VARCHAR(120) NOT NULL,
            effectiveAt DATETIME NOT NULL,
            summary TEXT NULL,
            createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
    ];

    $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $table => $definition) {
        if (!in_array($table, $existingTables, true)) {
            $pdo->exec($definition);
        }
    }

    if (in_array('policy_versions', $existingTables, true) || in_array('policy_versions', array_keys($requiredTables), true)) {
        $pdo->exec("INSERT INTO policy_versions (version, title, effectiveAt, summary) VALUES ('2025-01', 'VendLink privacy and consent policy', NOW(), 'Baseline privacy, cookie, consent, and data-processing documentation for VendLink.') ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary)");
    }

    $requiredColumns = [
        'users' => [
            'businessName' => 'VARCHAR(100) NULL',
            'applicationMessage' => 'TEXT NULL',
            'accountSetupTokenHash' => 'CHAR(64) NULL',
            'accountSetupExpiresAt' => 'DATETIME NULL',
            'passwordSetupCompletedAt' => 'DATETIME NULL',
            'address' => 'TEXT NULL',
            'municipality' => 'VARCHAR(100) NULL',
            'province' => 'VARCHAR(100) NULL',
            'barangay' => 'VARCHAR(100) NULL',
            'sitioPurok' => 'VARCHAR(150) NULL',
            'additionalAddress' => 'TEXT NULL',
            'contactNumber' => 'VARCHAR(30) NULL',
            'gcashNumber' => 'VARCHAR(30) NULL',
            'paymayaNumber' => 'VARCHAR(30) NULL',
            'username' => 'VARCHAR(50) NULL',
            'role' => "ENUM('admin', 'vendor', 'supplier') NOT NULL DEFAULT 'vendor'",
            'profileImage' => 'VARCHAR(255) NULL',
            'isActive' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'isApproved' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'approvalStatus' => "ENUM('pending', 'approved', 'disapproved') NOT NULL DEFAULT 'pending'",
            'failedAttempts' => 'INT NOT NULL DEFAULT 0',
            'lockedUntil' => 'DATETIME NULL',
            'twoFactorEnabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'twoFactorTempCode' => 'VARCHAR(10) NULL',
            'twoFactorExpires' => 'DATETIME NULL',
            'backupCodes' => 'TEXT NULL',
            'notificationsEnabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'emailVerified' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'createdAt' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updatedAt' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'notifications' => [
            'userId' => 'VARCHAR(36) NOT NULL',
            'title' => 'VARCHAR(150) NOT NULL',
            'message' => 'TEXT NOT NULL',
            'type' => 'VARCHAR(50) NOT NULL',
            'isRead' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'createdAt' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        ],
        'user_login_verifications' => [
            'purpose' => "ENUM('login', 'email') NOT NULL DEFAULT 'login'",
        ],
        'settings' => [
            'business_name' => 'VARCHAR(120) NOT NULL DEFAULT "VendLink"',
            'business_email' => 'VARCHAR(191) NULL',
            'business_phone' => 'VARCHAR(30) NULL',
            'business_address' => 'TEXT NULL',
            'business_hours' => 'VARCHAR(150) NULL',
            'privacy_policy_version' => 'VARCHAR(30) NOT NULL DEFAULT "2025-01"',
            'terms_version' => 'VARCHAR(30) NOT NULL DEFAULT "2025-01"',
            'cookie_policy_version' => 'VARCHAR(30) NOT NULL DEFAULT "2025-01"',
        ],
    ];

    $passwordSetupMarkerAdded = false;
    foreach ($requiredColumns as $table => $columns) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            continue;
        }

        foreach ($columns as $column => $definition) {
            if (in_array($column, $existing, true)) {
                continue;
            }

            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            if ($table === 'users' && $column === 'passwordSetupCompletedAt') {
                $passwordSetupMarkerAdded = true;
            }
        }
    }

    if ($passwordSetupMarkerAdded) {
        $pdo->exec("UPDATE users SET passwordSetupCompletedAt = COALESCE(updatedAt, createdAt, NOW()) WHERE role = 'supplier' AND approvalStatus = 'approved' AND accountSetupTokenHash IS NULL AND passwordSetupCompletedAt IS NULL");
    }

    $pdo->exec("INSERT INTO settings (`key`, `value`) VALUES
        ('site_background', 'default'),
        ('require_email_verification', '1'),
        ('admin_email', 'admin@vendlink.com'),
        ('business_name', 'VendLink'),
        ('business_email', 'support@vendlink.com'),
        ('business_phone', '+63 33 000 0000'),
        ('business_address', 'Guimbal, Iloilo, Philippines'),
        ('business_hours', 'Monday - Saturday, 8:00 AM - 5:00 PM'),
        ('privacy_policy_version', '2025-01'),
        ('terms_version', '2025-01'),
        ('cookie_policy_version', '2025-01')
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
}

require_once __DIR__ . '/env.php';

$databaseConfig = [
    'host' => vendlink_env('DB_HOST', vendlink_env('VENDLINK_DB_HOST', '')),
    'database' => vendlink_env('DB_NAME', vendlink_env('VENDLINK_DB_NAME', '')),
    'username' => vendlink_env('DB_USER', vendlink_env('VENDLINK_DB_USER', '')),
    'password' => vendlink_env('DB_PASS', vendlink_env('VENDLINK_DB_PASS', '')),
    'charset' => vendlink_env('DB_CHARSET', vendlink_env('VENDLINK_DB_CHARSET', 'utf8mb4')),
];

$credentialsPath = __DIR__ . '/database.credentials.php';
if (is_file($credentialsPath)) {
    try {
        $localDatabaseConfig = require $credentialsPath;
    } catch (Throwable $configurationError) {
        error_log('VendLink database credentials file could not be loaded.');
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database configuration is invalid.']);
        exit;
    }
    if (!is_array($localDatabaseConfig)) {
        error_log('VendLink database configuration must return an array.');
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database configuration is invalid.']);
        exit;
    }
    $databaseConfig = array_replace($databaseConfig, array_filter($localDatabaseConfig, static fn ($value): bool => $value !== null && $value !== ''));
}

foreach ([
    'host' => ['DB_HOST', 'VENDLINK_DB_HOST'],
    'database' => ['DB_NAME', 'VENDLINK_DB_NAME'],
    'username' => ['DB_USER', 'VENDLINK_DB_USER'],
    'password' => ['DB_PASS', 'VENDLINK_DB_PASS'],
    'charset' => ['DB_CHARSET', 'VENDLINK_DB_CHARSET'],
] as $key => $environmentVariables) {
    foreach ($environmentVariables as $environmentVariable) {
        $environmentValue = vendlink_env($environmentVariable, '');
        if ($environmentValue !== '') {
            $databaseConfig[$key] = $environmentValue;
            break;
        }
    }
}

foreach (['host', 'database', 'username', 'password', 'charset'] as $key) {
    if (!isset($databaseConfig[$key]) || !is_string($databaseConfig[$key])) {
        error_log('VendLink database configuration contains an invalid value for: ' . $key);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database configuration is invalid.']);
        exit;
    }
}

if ($databaseConfig['host'] === '' || $databaseConfig['database'] === '' || $databaseConfig['username'] === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database configuration is missing. Set DB_HOST, DB_NAME, and DB_USER environment variables.']);
    exit;
}

$host = $databaseConfig['host'];
$db = $databaseConfig['database'];
$user = $databaseConfig['username'];
$pass = $databaseConfig['password'];
$charset = $databaseConfig['charset'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    ensureDatabaseSchema($pdo);
} catch (\PDOException $e) {
    header('Content-Type: application/json');
    error_log('VendLink database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}