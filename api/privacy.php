<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

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

function currentConsentSessionId(): string
{
    $sessionId = session_id();
    if ($sessionId !== '') {
        return $sessionId;
    }

    $seed = ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    return hash('sha256', $seed);
}

function getClientIpAddress(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') {
            continue;
        }

        $ip = trim((string)explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
if (!is_array($input)) {
    $input = [];
}

if ($action === 'cookie-state') {
    $preferences = [
        'essential' => true,
        'analytics' => false,
        'functional' => false,
        'marketing' => false,
        'consentSource' => 'none',
        'policyVersion' => '2025-01',
    ];

    $userId = $_SESSION['user_id'] ?? null;
    $sessionId = currentConsentSessionId();
    $stmt = $pdo->prepare('SELECT essential, analytics, functional, marketing, consentSource, policyVersion FROM cookie_preferences WHERE userId = ? ORDER BY updatedAt DESC LIMIT 1');
    if ($userId !== null) {
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare('SELECT essential, analytics, functional, marketing, consentSource, policyVersion FROM cookie_preferences WHERE sessionId = ? ORDER BY updatedAt DESC LIMIT 1');
        $stmt->execute([$sessionId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $preferences = [
            'essential' => (bool)(int)$row['essential'],
            'analytics' => (bool)(int)$row['analytics'],
            'functional' => (bool)(int)$row['functional'],
            'marketing' => (bool)(int)$row['marketing'],
            'consentSource' => $row['consentSource'] ?? 'banner',
            'policyVersion' => $row['policyVersion'] ?? '2025-01',
        ];
    }

    echo json_encode(['success' => true, 'preferences' => $preferences]);
    exit;
}

if ($action === 'save-cookie-preferences') {
    $preferences = [
        'essential' => true,
        'analytics' => filter_var($input['analytics'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'functional' => filter_var($input['functional'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'marketing' => filter_var($input['marketing'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
    $policyVersion = trim((string)($input['policyVersion'] ?? '2025-01')) ?: '2025-01';
    $sessionId = currentConsentSessionId();
    $userId = $_SESSION['user_id'] ?? null;

    $existing = $pdo->prepare('SELECT id FROM cookie_preferences WHERE userId = ? OR sessionId = ? ORDER BY updatedAt DESC LIMIT 1');
    $existing->execute([$userId, $sessionId]);
    $cookieRow = $existing->fetch(PDO::FETCH_ASSOC);
    if ($cookieRow) {
        $stmt = $pdo->prepare('UPDATE cookie_preferences SET userId = ?, sessionId = ?, essential = 1, analytics = ?, functional = ?, marketing = ?, consentSource = ?, policyVersion = ?, updatedAt = NOW() WHERE id = ?');
        $stmt->execute([$userId, $sessionId, (int)$preferences['analytics'], (int)$preferences['functional'], (int)$preferences['marketing'], 'banner', $policyVersion, $cookieRow['id']]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO cookie_preferences (userId, sessionId, essential, analytics, functional, marketing, consentSource, policyVersion, createdAt, updatedAt) VALUES (?, ?, 1, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$userId, $sessionId, (int)$preferences['analytics'], (int)$preferences['functional'], (int)$preferences['marketing'], 'banner', $policyVersion]);
    }

    $consent = $preferences['analytics'] || $preferences['functional'] || $preferences['marketing'];
    if ($consent) {
        $details = json_encode($preferences, JSON_THROW_ON_ERROR);
        $userIdForConsent = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO user_consents (userId, consentPurpose, consentGranted, policyVersion, ipAddress, userAgent, consentDetails, createdAt) VALUES (?, ?, 1, ?, ?, ?, ?, NOW())');
        $stmt->execute([$userIdForConsent, 'cookies', $policyVersion, getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $details]);
    }

    echo json_encode(['success' => true, 'preferences' => $preferences]);
    exit;
}

if ($action === 'save-consent') {
    $purpose = trim((string)($input['purpose'] ?? ''));
    $granted = filter_var($input['granted'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $policyVersion = trim((string)($input['policyVersion'] ?? '2025-01')) ?: '2025-01';
    if ($purpose === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A consent purpose is required.']);
        exit;
    }

    $userId = $_SESSION['user_id'] ?? null;
    $details = json_encode($input['details'] ?? [], JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare('INSERT INTO user_consents (userId, consentPurpose, consentGranted, policyVersion, ipAddress, userAgent, consentDetails, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $purpose, $granted ? 1 : 0, $policyVersion, getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $details]);
    echo json_encode(['success' => true, 'recorded' => true]);
    exit;
}

if ($action === 'email-preferences') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to manage your email preferences.']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT promotionalEmails, transactionalEmails, unsubscribeToken FROM email_preferences WHERE userId = ? LIMIT 1');
    $stmt->execute([$userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$preferences) {
        $token = hash('sha256', $userId . ':' . random_bytes(32));
        $insert = $pdo->prepare('INSERT INTO email_preferences (userId, promotionalEmails, transactionalEmails, unsubscribeToken, createdAt, updatedAt) VALUES (?, 0, 1, ?, NOW(), NOW())');
        $insert->execute([$userId, $token]);
        $preferences = ['promotionalEmails' => 0, 'transactionalEmails' => 1, 'unsubscribeToken' => $token];
    }

    echo json_encode([
        'success' => true,
        'promotionalEmails' => (bool)(int)($preferences['promotionalEmails'] ?? 0),
        'transactionalEmails' => (bool)(int)($preferences['transactionalEmails'] ?? 1),
        'unsubscribeToken' => $preferences['unsubscribeToken'] ?? '',
    ]);
    exit;
}

if ($action === 'save-email-preferences') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to update marketing email preferences.']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $promotional = filter_var($input['promotionalEmails'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $transactional = filter_var($input['transactionalEmails'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $stmt = $pdo->prepare('INSERT INTO email_preferences (userId, promotionalEmails, transactionalEmails, unsubscribeToken, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE promotionalEmails = VALUES(promotionalEmails), transactionalEmails = VALUES(transactionalEmails), updatedAt = NOW()');
    $token = hash('sha256', $userId . ':' . random_bytes(32));
    $stmt->execute([$userId, $promotional ? 1 : 0, $transactional ? 1 : 0, $token]);

    echo json_encode(['success' => true, 'promotionalEmails' => (bool)$promotional, 'transactionalEmails' => (bool)$transactional, 'unsubscribeToken' => $token]);
    exit;
}

if ($action === 'privacy-request') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to submit a privacy request.']);
        exit;
    }

    $type = trim((string)($input['type'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $policyVersion = trim((string)($input['policyVersion'] ?? '2025-01')) ?: '2025-01';
    if ($type === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Choose a privacy request type.']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO privacy_requests (userId, requestType, status, requestReason, policyVersion, requestedAt) VALUES (?, ?, "pending", ?, ?, NOW())');
    $stmt->execute([$_SESSION['user_id'], $type, $reason !== '' ? $reason : 'Requested through account privacy settings.', $policyVersion]);
    echo json_encode(['success' => true, 'message' => 'Your privacy request was received and sent to the VendLink team for review.']);
    exit;
}

if ($action === 'admin-requests') {
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }

    $rows = $pdo->query('SELECT pr.id, pr.userId, u.name, u.email, pr.requestType, pr.status, pr.requestReason, pr.requestedAt, pr.reviewedAt, pr.notes, pr.policyVersion FROM privacy_requests pr LEFT JOIN users u ON u.id = pr.userId ORDER BY pr.requestedAt DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'requests' => $rows]);
    exit;
}

if ($action === 'admin-request-status') {
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }

    $requestId = filter_var($input['requestId'] ?? '', FILTER_VALIDATE_INT);
    $status = trim((string)($input['status'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $allowedStatuses = ['pending', 'in_progress', 'completed', 'rejected'];

    if ($requestId === false || $requestId <= 0 || !in_array($status, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Select a valid privacy request and status.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE privacy_requests SET status = ?, reviewedAt = NOW(), reviewedBy = ?, notes = ? WHERE id = ?');
    $stmt->execute([$status, $_SESSION['user_id'], $notes !== '' ? $notes : null, $requestId]);
    if ($stmt->rowCount() !== 1) {
        http_response_code(404);
        echo json_encode(['error' => 'Privacy request not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'status' => $status]);
    exit;
}

if ($action === 'business-info') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $businessInfo = [
            'businessName' => getSettingValue($pdo, 'business_name', 'VendLink'),
            'businessAddress' => getSettingValue($pdo, 'business_address', 'Guimbal, Iloilo, Philippines'),
            'businessEmail' => getSettingValue($pdo, 'business_email', 'support@vendlink.com'),
            'businessPhone' => getSettingValue($pdo, 'business_phone', '+63 33 000 0000'),
            'businessHours' => getSettingValue($pdo, 'business_hours', 'Monday - Saturday, 8:00 AM - 5:00 PM'),
            'contactEmail' => getSettingValue($pdo, 'business_email', 'support@vendlink.com'),
        ];
        echo json_encode(['success' => true, 'businessInfo' => $businessInfo]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        if (($_SESSION['user_role'] ?? null) !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required.']);
            exit;
        }
        requireCsrfToken();
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        foreach (['business_name' => $payload['businessName'] ?? 'VendLink', 'business_email' => $payload['businessEmail'] ?? 'support@vendlink.com', 'business_phone' => $payload['businessPhone'] ?? '+63 33 000 0000', 'business_address' => $payload['businessAddress'] ?? 'Guimbal, Iloilo, Philippines', 'business_hours' => $payload['businessHours'] ?? 'Monday - Saturday, 8:00 AM - 5:00 PM'] as $key => $value) {
            setSettingValue($pdo, $key, (string)$value);
        }
        echo json_encode(['success' => true, 'message' => 'Business information updated.']);
        exit;
    }
}

if ($action === 'unsubscribe') {
    $token = trim((string)($_GET['token'] ?? $input['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['error' => 'A valid unsubscribe token is required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT userId FROM email_preferences WHERE unsubscribeToken = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'This unsubscribe link is invalid or expired.']);
        exit;
    }

    $update = $pdo->prepare('UPDATE email_preferences SET promotionalEmails = 0, updatedAt = NOW() WHERE userId = ?');
    $update->execute([$row['userId']]);
    echo json_encode(['success' => true, 'message' => 'Your marketing email preferences were updated.']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Privacy action not found.']);
