<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$userId = (string)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

function profileResponse(array $user): array {
    unset($user['password']);
    $user['notificationsEnabled'] = (int)$user['notificationsEnabled'];
    return $user;
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare('SELECT id, name, username, email, role, businessName, address, municipality, province, barangay, sitioPurok, additionalAddress, contactNumber, profileImage, gcashNumber, paymayaNumber, notificationsEnabled, createdAt FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Profile not found.']);
            exit;
        }
        echo json_encode(profileResponse($user));
    } catch (Throwable $e) {
        error_log('VendLink profile read error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Profile is temporarily unavailable.']);
    }
    exit;
}

if (!in_array($method, ['POST', 'PUT'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PUT');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid profile data.']);
    exit;
}

$name = trim((string)($input['name'] ?? ''));
$username = trim((string)($input['username'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$businessName = trim((string)($input['businessName'] ?? ''));
$address = trim((string)($input['address'] ?? ''));
$municipality = trim((string)($input['municipality'] ?? 'Guimbal'));
$province = trim((string)($input['province'] ?? 'Iloilo'));
$barangay = trim((string)($input['barangay'] ?? ''));
$sitioPurok = trim((string)($input['sitioPurok'] ?? ''));
$additionalAddress = trim((string)($input['additionalAddress'] ?? ''));
$contactNumber = trim((string)($input['contactNumber'] ?? ''));
$gcashNumber = trim((string)($input['gcashNumber'] ?? ''));
$paymayaNumber = trim((string)($input['paymayaNumber'] ?? ''));
$profileImage = trim((string)($input['profileImage'] ?? ''));
$notificationsEnabled = filter_var($input['notificationsEnabled'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$currentPassword = (string)($input['currentPassword'] ?? '');
$newPassword = (string)($input['newPassword'] ?? '');
$confirmPassword = (string)($input['confirmPassword'] ?? '');

$allowedRoles = ['admin', 'vendor', 'supplier'];
$validImage = $profileImage === '' || preg_match('/^(https:\/\/|\/|assets\/)/i', $profileImage) === 1;
$validImage = $validImage || (
    preg_match('/^uploads\/profiles\/profile_[a-f0-9]{12}_[a-f0-9]{32}\.(jpg|png|webp)$/i', $profileImage) === 1
    && str_starts_with($profileImage, 'uploads/profiles/profile_' . substr(hash('sha256', $userId), 0, 12) . '_')
    && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $profileImage))
);
$validPhone = static function (string $value): bool {
    return $value === '' || preg_match('/^[0-9+() .-]{7,30}$/', $value) === 1;
};

if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191 || strlen($username) > 50 || ($username !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1) || strlen($businessName) > 100 || strlen($address) > 2000 || strlen($barangay) > 100 || strlen($sitioPurok) > 150 || strlen($additionalAddress) > 2000 || !$validPhone($contactNumber) || !$validPhone($gcashNumber) || !$validPhone($paymayaNumber) || !$validImage || $notificationsEnabled === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide valid profile details.']);
    exit;
}

$passwordChangeRequested = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';
if ($passwordChangeRequested && ($currentPassword === '' || $newPassword === '' || $newPassword !== $confirmPassword)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter your current password and matching new password confirmation.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $userStmt = $pdo->prepare('SELECT id, password, role, name, username FROM users WHERE id = ? FOR UPDATE');
    $userStmt->execute([$userId]);
    $existing = $userStmt->fetch();
    if (!$existing || !in_array($existing['role'], $allowedRoles, true)) {
        throw new RuntimeException('Profile not found.', 404);
    }
    if ($existing['role'] !== 'admin') {
        if ($name !== $existing['name'] || $username !== (string)($existing['username'] ?? '')) {
            throw new RuntimeException('Only an administrator may change a registered user name or username.', 403);
        }
        $municipality = 'Guimbal';
        $province = 'Iloilo';
        if ($barangay !== '') {
            $address = implode(', ', array_filter([
                $additionalAddress,
                $sitioPurok,
                $barangay,
                $municipality,
                $province,
                'Philippines'
            ], static fn(string $part): bool => $part !== ''));
        }
    }

    if ($passwordChangeRequested) {
        $newPasswordLength = preg_match_all('/./us', $newPassword);
        $minimumLength = $existing['role'] === 'admin' ? 12 : 6;
        $maximumLength = $existing['role'] === 'admin' ? 72 : PHP_INT_MAX;
        if ($newPasswordLength === false || $newPasswordLength < $minimumLength || strlen($newPassword) > $maximumLength || $newPassword === $currentPassword) {
            $message = $existing['role'] === 'admin'
                ? 'Admin passwords must be new and between 12 and 72 characters.'
                : 'New passwords must be at least 6 characters and differ from the current password.';
            throw new RuntimeException($message, 400);
        }
    }

    if ($passwordChangeRequested && !password_verify($currentPassword, $existing['password'])) {
        throw new RuntimeException('Current password is incorrect.', 400);
    }

    $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE (email = ? OR (username IS NOT NULL AND username = ?)) AND id != ? LIMIT 1');
    $duplicateStmt->execute([$email, $username === '' ? null : $username, $userId]);
    if ($duplicateStmt->fetch()) {
        throw new RuntimeException('That email address or username is already in use.', 409);
    }

    $passwordSql = $passwordChangeRequested ? ', password = ?' : '';
    if ($existing['role'] === 'admin') {
        $params = [$name, $contactNumber, $username === '' ? null : $username, $email, $notificationsEnabled ? 1 : 0, $profileImage];
        $updateSql = "UPDATE users SET name = ?, contactNumber = ?, username = ?, email = ?, notificationsEnabled = ?, profileImage = ?{$passwordSql} WHERE id = ?";
    } else {
        $params = [$businessName, $address, $municipality, $province, $barangay ?: null, $sitioPurok ?: null, $additionalAddress ?: null, $contactNumber, $email, $gcashNumber, $paymayaNumber, $notificationsEnabled ? 1 : 0, $profileImage];
        $updateSql = "UPDATE users SET businessName = ?, address = ?, municipality = ?, province = ?, barangay = ?, sitioPurok = ?, additionalAddress = ?, contactNumber = ?, email = ?, gcashNumber = ?, paymayaNumber = ?, notificationsEnabled = ?, profileImage = ?{$passwordSql} WHERE id = ?";
    }
    if ($passwordChangeRequested) {
        $params[] = password_hash($newPassword, PASSWORD_BCRYPT);
    }
    $params[] = $userId;
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($params);

    $notificationStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, 'Profile Information Updated', 'Your VendLink profile information was updated successfully.', 'security', NOW())");
    $notificationStmt->execute([$userId]);

    $pdo->commit();
    $_SESSION['user_name'] = $name;
    if ($existing['role'] !== 'admin') {
        $_SESSION['business_name'] = $businessName;
    }
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('VendLink profile update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Profile could not be updated.']);
}
