<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid password recovery request.']);
    exit;
}

$action = (string)($input['action'] ?? '');
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
    $clientIp = 'unknown';
}

function logPasswordReset(PDO $pdo, ?string $userId, string $action, string $clientIp): void
{
    $stmt = $pdo->prepare('INSERT INTO security_logs (userId, ip, action, meta, createdAt) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $clientIp, $action, 'Password recovery workflow']);
}

if ($action === 'request') {
    $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
    $emailHash = hash('sha256', $email);
    $genericResponse = [
        'success' => true,
        'message' => 'If an account exists for that email address, a password reset link will be sent. If it does not arrive, try again later or contact the administrator.'
    ];

    try {
        $rateStmt = $pdo->prepare("SELECT
            SUM(CASE WHEN emailHash = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) AS emailCount,
            SUM(CASE WHEN requestIp = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) AS ipCount
            FROM password_reset_attempts");
        $rateStmt->execute([$emailHash, $clientIp]);
        $rate = $rateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($rate['emailCount'] ?? 0) >= 5 || (int)($rate['ipCount'] ?? 0) >= 10) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many password recovery attempts. Please wait 15 minutes and try again.']);
            exit;
        }

        $attemptStmt = $pdo->prepare('INSERT INTO password_reset_attempts (emailHash, requestIp, createdAt) VALUES (?, ?, NOW())');
        $attemptStmt->execute([$emailHash, $clientIp]);
        $pdo->exec('DELETE FROM password_reset_attempts WHERE createdAt < DATE_SUB(NOW(), INTERVAL 1 DAY)');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            echo json_encode($genericResponse);
            exit;
        }

        $userStmt = $pdo->prepare('SELECT id, email, name, role FROM users WHERE email = ? AND isActive = 1 LIMIT 1');
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            logPasswordReset($pdo, null, 'password_reset_requested', $clientIp);
            echo json_encode($genericResponse);
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $pdo->beginTransaction();
        $invalidateStmt = $pdo->prepare('UPDATE password_reset_tokens SET usedAt = NOW() WHERE userId = ? AND usedAt IS NULL');
        $invalidateStmt->execute([$user['id']]);
        $tokenStmt = $pdo->prepare('INSERT INTO password_reset_tokens (userId, tokenHash, expiresAt, createdAt) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())');
        $tokenStmt->execute([$user['id'], hash('sha256', $token)]);
        logPasswordReset($pdo, (string)$user['id'], 'password_reset_requested', $clientIp);
        $pdo->commit();

        $resetUrl = vendlinkBaseUrl() . '/reset-password.html?token=' . rawurlencode($token);
        $body = "Hello {$user['name']},\n\nA password reset was requested for your VendLink account. Use the secure, single-use link below within 30 minutes:\n\n{$resetUrl}\n\nIf you did not request this change, you can ignore this email.\n\nVendLink Security";
        if (!sendVendLinkEmail((string)$user['email'], 'Reset your VendLink password', $body)) {
            error_log('VendLink password reset email could not be delivered.');
        }
        echo json_encode($genericResponse);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('VendLink password reset request failed: ' . get_class($error));
        http_response_code(500);
        echo json_encode(['error' => 'Password recovery is temporarily unavailable. Please try again later.']);
    }
    exit;
}

if ($action === 'reset') {
    $token = is_string($input['token'] ?? null) ? trim($input['token']) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $confirmation = is_string($input['confirmation'] ?? null) ? $input['confirmation'] : '';
    $passwordLength = preg_match_all('/./us', $password);
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset link is invalid or expired. Request a new link.']);
        exit;
    }
    if ($passwordLength === false || $passwordLength < 8 || strlen($password) > 72 || !hash_equals($password, $confirmation)) {
        http_response_code(400);
        echo json_encode(['error' => 'Use a matching password of 8 to 72 characters.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $tokenStmt = $pdo->prepare("SELECT t.id AS tokenId, t.userId, u.name, u.email, u.role
            FROM password_reset_tokens t
            JOIN users u ON u.id = t.userId
            WHERE t.tokenHash = ? AND t.usedAt IS NULL AND t.expiresAt > NOW() AND u.isActive = 1
            LIMIT 1 FOR UPDATE");
        $tokenStmt->execute([hash('sha256', $token)]);
        $reset = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        if (!$reset) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'This reset link is invalid or expired. Request a new link.']);
            exit;
        }
        if ($reset['role'] === 'admin' && $passwordLength < 12) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Administrator passwords must be at least 12 characters.']);
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare('UPDATE users SET password = ?, failedAttempts = 0, lockedUntil = NULL WHERE id = ?');
        $updateStmt->execute([$passwordHash, $reset['userId']]);
        $usedStmt = $pdo->prepare('UPDATE password_reset_tokens SET usedAt = NOW() WHERE id = ? AND usedAt IS NULL');
        $usedStmt->execute([$reset['tokenId']]);
        $invalidateStmt = $pdo->prepare('UPDATE password_reset_tokens SET usedAt = NOW() WHERE userId = ? AND id <> ? AND usedAt IS NULL');
        $invalidateStmt->execute([$reset['userId'], $reset['tokenId']]);
        logPasswordReset($pdo, (string)$reset['userId'], 'password_reset_completed', $clientIp);
        $changedAt = date('Y-m-d H:i:s');
        $notifyStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, 'Password changed', 'Your password was changed successfully.', 'security', NOW())");
        $notifyStmt->execute([$reset['userId']]);
        $adminEmails = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND isActive = 1")->fetchAll(PDO::FETCH_COLUMN);
        $configuredAdmin = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'admin_email' LIMIT 1")->fetchColumn();
        if (is_string($configuredAdmin) && filter_var($configuredAdmin, FILTER_VALIDATE_EMAIL)) {
            $adminEmails[] = $configuredAdmin;
        }
        $pdo->commit();

        $userMessage = "Hello {$reset['name']},\n\nYour VendLink password was changed successfully on {$changedAt}. If you did not make this change, contact the administrator immediately.\n\nVendLink Security";
        if (!sendVendLinkEmail((string)$reset['email'], 'Your VendLink password was changed', $userMessage)) {
            error_log('VendLink password-change confirmation email could not be delivered.');
        }

        $adminMessage = "A VendLink user successfully changed their password.\n\nName: {$reset['name']}\nRole: {$reset['role']}\nDate/time: {$changedAt}\n\nNo password or reset token is included in this notification.";
        foreach (array_unique($adminEmails) as $adminEmail) {
            if (!sendVendLinkEmail((string)$adminEmail, 'VendLink password change notification', $adminMessage)) {
                error_log('VendLink administrator password-change notification could not be delivered.');
            }
        }

        echo json_encode(['success' => true, 'message' => 'Your password has been changed.']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('VendLink password reset completion failed: ' . get_class($error));
        http_response_code(500);
        echo json_encode(['error' => 'The password could not be changed. Please request a new reset link.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid password recovery action.']);
