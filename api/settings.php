<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

function getSettingValue(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
}

function setSettingValue(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute([$key, $value]);
}

$allowedBackgrounds = ['default', 'ocean', 'meadow', 'sunset'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        echo json_encode(['siteBackground' => 'default']);
        exit;
    }

    $background = getSettingValue($pdo, 'site_background', 'default');
    $requireEmailVerification = filter_var(getSettingValue($pdo, 'require_email_verification', '1'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $adminEmail = getSettingValue($pdo, 'admin_email', '');
    if ($adminEmail === '') {
        $adminUserStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? AND role = ? LIMIT 1');
        $adminUserStmt->execute([$_SESSION['user_id'] ?? '', 'admin']);
        $adminEmail = (string)$adminUserStmt->fetchColumn();
    }

    echo json_encode([
        'siteBackground' => in_array($background, $allowedBackgrounds, true) ? $background : 'default',
        'requireEmailVerification' => $requireEmailVerification ?? true,
        'adminEmail' => $adminEmail,
    ]);
    exit;
}

if ($method === 'PUT') {
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    requireCsrfToken();
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $background = $payload['siteBackground'] ?? 'default';
    $requireEmailVerification = $payload['requireEmailVerification'] ?? null;
    $adminEmail = trim((string)($payload['adminEmail'] ?? ''));

    if (!in_array($background, $allowedBackgrounds, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid background preset.']);
        exit;
    }

    if ($requireEmailVerification !== null && !is_bool($requireEmailVerification) && !in_array($requireEmailVerification, ['0', '1', 0, 1, 'true', 'false'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Email verification must be enabled or disabled.']);
        exit;
    }

    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['error' => 'Provide a valid admin email address.']);
        exit;
    }

    setSettingValue($pdo, 'site_background', $background);

    if ($requireEmailVerification !== null) {
        setSettingValue($pdo, 'require_email_verification', filter_var($requireEmailVerification, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
    }

    if ($adminEmail !== '') {
        setSettingValue($pdo, 'admin_email', strtolower($adminEmail));

        $adminUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? AND role = ? LIMIT 1');
        $adminUserStmt->execute([strtolower($adminEmail), $_SESSION['user_id'] ?? '', 'admin']);
        if ($adminUserStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'That admin email is already assigned to another admin account.']);
            exit;
        }

        $adminUpdateStmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND role = ?');
        $adminUpdateStmt->execute([strtolower($adminEmail), $_SESSION['user_id'] ?? '', 'admin']);
    }

    echo json_encode([
        'siteBackground' => $background,
        'requireEmailVerification' => filter_var(getSettingValue($pdo, 'require_email_verification', '1'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
        'adminEmail' => $adminEmail !== '' ? strtolower($adminEmail) : getSettingValue($pdo, 'admin_email', ''),
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);