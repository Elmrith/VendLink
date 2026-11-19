<?php
// api/auth.php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/email.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

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

function rememberDeviceToken(string $tokenHash): void
{
    if ($tokenHash === '') {
        return;
    }

    $lifetime = time() + (60 * 60 * 24 * 30);
    setcookie('vendlink_device_token', $tokenHash, [
        'expires' => $lifetime,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['vendlink_device_token'] = $tokenHash;
}

function getCurrentDeviceTokenHash(): string
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipAddress = getClientIpAddress();
    $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $deviceSeed = $userAgent . '|' . $ipAddress . '|' . $language . '|' . ($_SERVER['HTTP_SEC_CH_UA'] ?? '');
    return hash('sha256', $deviceSeed);
}

function insertLoginNotification(PDO $pdo, string $userId, string $title, string $message): void
{
    $notificationStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, ?, ?, 'security', NOW())");
    $notificationStmt->execute([$userId, $title, $message]);
}

function getSettingValue(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
}

function emailVerificationEnabled(PDO $pdo): bool
{
    $enabled = filter_var(getSettingValue($pdo, 'require_email_verification', '1'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $enabled ?? true;
}

function isRealEmailAddress(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host = strtolower(substr($email, strrpos($email, '@') + 1));
    if ($host === '' || strpos($host, '.') === false) {
        return false;
    }

    return checkdnsrr($host, 'MX') || checkdnsrr($host, 'A') || checkdnsrr($host, 'AAAA');
}

function supplierSetupAttemptAllowed(): bool
{
    $now = time();
    $attempts = $_SESSION['supplier_setup_attempts'] ?? null;
    $startedAt = is_array($attempts) ? (int)($attempts['startedAt'] ?? 0) : 0;
    $count = is_array($attempts) ? (int)($attempts['count'] ?? 0) : 0;

    if ($startedAt <= 0 || $startedAt > $now || $now - $startedAt >= 900) {
        $startedAt = $now;
        $count = 0;
    }
    if ($count >= 10) {
        return false;
    }

    $_SESSION['supplier_setup_attempts'] = ['startedAt' => $startedAt, 'count' => $count + 1];
    return true;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($action === 'csrf') {
    echo json_encode(['success' => true, 'csrfToken' => csrfToken()]);
    exit;
}

try {

if ($action === 'completeSupplierSetup') {
    requireCsrfToken();
    if (!supplierSetupAttemptAllowed()) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many setup attempts. Wait 15 minutes and try again.']);
        exit;
    }

    $setupToken = is_string($input['token'] ?? null) ? trim($input['token']) : '';
    $newPassword = is_string($input['password'] ?? null) ? $input['password'] : '';
    $passwordConfirmation = is_string($input['passwordConfirmation'] ?? null) ? $input['passwordConfirmation'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/i', $setupToken)) {
        http_response_code(410);
        echo json_encode(['error' => 'This supplier setup link is invalid or expired. Ask the admin to send a new approval email.']);
        exit;
    }

    $passwordLength = preg_match_all('/./us', $newPassword);
    if ($passwordLength === false || $passwordLength < 12 || strlen($newPassword) > 72) {
        http_response_code(400);
        echo json_encode(['error' => 'Choose a password with at least 12 characters and no more than 72 bytes.']);
        exit;
    }
    if (!hash_equals($newPassword, $passwordConfirmation)) {
        http_response_code(400);
        echo json_encode(['error' => 'The password confirmation does not match.']);
        exit;
    }

    $updateStmt = $pdo->prepare("UPDATE users SET password = ?, accountSetupTokenHash = NULL, accountSetupExpiresAt = NULL, passwordSetupCompletedAt = NOW() WHERE accountSetupTokenHash = ? AND accountSetupExpiresAt > NOW() AND role = 'supplier' AND approvalStatus = 'approved' AND isActive = 1 AND passwordSetupCompletedAt IS NULL");
    $updateStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), hash('sha256', $setupToken)]);
    if ($updateStmt->rowCount() !== 1) {
        http_response_code(410);
        echo json_encode(['error' => 'This supplier setup link is invalid or expired. Ask the admin to send a new approval email.']);
        exit;
    }

    unset($_SESSION['supplier_setup_attempts']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'login') {
    requireCsrfToken();
    unset($_SESSION['supplier_pending_login']);
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, email, password, name, businessName, role, isActive, isApproved, approvalStatus, passwordSetupCompletedAt, emailVerified, failedAttempts, lockedUntil, (lockedUntil IS NOT NULL AND lockedUntil > NOW()) AS isLocked FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && $user['role'] === 'admin' && (int)$user['isLocked'] === 1) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many failed sign-in attempts. Try again in 15 minutes.']);
        exit;
    }

    if (!$user || !password_verify($password, $user['password'])) {
        if ($user && $user['role'] === 'admin') {
            $attemptStmt = $pdo->prepare("UPDATE users SET lockedUntil = CASE WHEN failedAttempts + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE lockedUntil END, failedAttempts = failedAttempts + 1 WHERE id = ? AND role = 'admin'");
            $attemptStmt->execute([$user['id']]);
        }
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if ($user['role'] === 'admin') {
        $resetAttemptsStmt = $pdo->prepare("UPDATE users SET failedAttempts = 0, lockedUntil = NULL WHERE id = ? AND role = 'admin'");
        $resetAttemptsStmt->execute([$user['id']]);
    }

    if ($user['isActive'] == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Account has been deactivated. Please contact support.']);
        exit;
    }

    $approvalStatus = $user['approvalStatus'] ?? ((int)$user['isApproved'] ? 'approved' : 'pending');
    if (in_array($user['role'], ['vendor', 'supplier'], true) && $approvalStatus !== 'approved') {
        http_response_code(403);
        $message = $approvalStatus === 'disapproved'
            ? 'Your account application was disapproved. Please contact support.'
            : 'Your account is waiting for admin approval. Please try again later.';
        echo json_encode(['error' => $message]);
        exit;
    }

    if ($user['role'] === 'supplier' && empty($user['passwordSetupCompletedAt'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Finish creating your supplier password using the latest setup email before signing in.']);
        exit;
    }

    $deviceTokenHash = getCurrentDeviceTokenHash();
    $deviceStmt = $pdo->prepare("SELECT id, deviceName FROM user_devices WHERE userId = ? AND deviceTokenHash = ? LIMIT 1");
    $deviceStmt->execute([$user['id'], $deviceTokenHash]);
    $trustedDevice = $deviceStmt->fetch();

    $verificationEnabled = emailVerificationEnabled($pdo);
    $emailVerificationRequired = $user['role'] !== 'admin' && $verificationEnabled && (int)$user['emailVerified'] !== 1;

    if ($emailVerificationRequired) {
        $verificationCode = sprintf('%06d', random_int(100000, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $verificationStmt = $pdo->prepare("INSERT INTO user_login_verifications (userId, verificationCode, purpose, ipAddress, userAgent, expiresAt, isVerified, createdAt) VALUES (?, ?, 'email', ?, ?, ?, 0, NOW())");
        $verificationStmt->execute([$user['id'], $verificationCode, getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]);

        $emailBody = "Hello {$user['name']},\n\nTo finish signing in to your VendLink account, verify your email address with the code below.\n\nYour verification code is: {$verificationCode}\n\nThis code expires in 15 minutes. If you did not try to sign in, please change your password immediately.\n\nRegards,\nVendLink Security";
        $emailSent = sendVendLinkEmail($user['email'], 'Verify your VendLink email address', $emailBody);

        if ($user['role'] === 'supplier') {
            $_SESSION['supplier_pending_login'] = ['userId' => $user['id'], 'expiresAt' => time() + 900];
        }

        echo json_encode([
            'success' => true,
            'requiresVerification' => true,
            'verificationType' => 'email',
            'emailSent' => $emailSent,
            'message' => $emailSent
                ? 'Your email address needs to be verified before this account can sign in.'
                : 'Your email address needs to be verified, but we could not deliver the verification email. Please try again or contact VendLink support.'
        ]);
        exit;
    }

    if (!$trustedDevice && $user['role'] !== 'admin' && $verificationEnabled) {
        $verificationCode = sprintf('%06d', random_int(100000, 999999));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $verificationStmt = $pdo->prepare("INSERT INTO user_login_verifications (userId, verificationCode, purpose, ipAddress, userAgent, expiresAt, isVerified, createdAt) VALUES (?, ?, 'login', ?, ?, ?, 0, NOW())");
        $verificationStmt->execute([$user['id'], $verificationCode, getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]);

        $emailBody = "Hello {$user['name']},\n\nWe detected a sign-in attempt using a new device on your VendLink account.\n\nYour verification code is: {$verificationCode}\n\nThis code expires in 15 minutes. If you did not try to sign in, please change your password immediately.\n\nRegards,\nVendLink Security";
        $emailSent = sendVendLinkEmail($user['email'], 'Verify your VendLink sign-in', $emailBody);

        $existingDevices = $pdo->prepare("SELECT userId, deviceName, userAgent, ipAddress FROM user_devices WHERE userId = ? ORDER BY lastSeenAt DESC");
        $existingDevices->execute([$user['id']]);
        foreach ($existingDevices->fetchAll() as $knownDevice) {
            $deviceLabel = $knownDevice['deviceName'] ?: 'a remembered device';
            $deliveryNotice = $emailSent
                ? 'If this was you, verify the code sent to your email.'
                : 'The verification email could not be delivered; please try signing in again.';
            insertLoginNotification($pdo, $user['id'], 'New device sign-in detected', "A sign-in was attempted from a different device. {$deliveryNotice} Active device: {$deviceLabel}.");
        }

        if ($user['role'] === 'supplier') {
            $_SESSION['supplier_pending_login'] = ['userId' => $user['id'], 'expiresAt' => time() + 900];
        }

        echo json_encode([
            'success' => true,
            'requiresVerification' => true,
            'emailSent' => $emailSent,
            'message' => $emailSent
                ? 'A verification code has been sent to your email. Confirm it to finish signing in from this device.'
                : 'We could not deliver your sign-in verification code. Please try again or contact VendLink support.'
        ]);
        exit;
    }

    if ($trustedDevice) {
        $deviceUpdateStmt = $pdo->prepare("UPDATE user_devices SET lastSeenAt = NOW(), userAgent = ?, ipAddress = ? WHERE id = ?");
        $deviceUpdateStmt->execute([$_SERVER['HTTP_USER_AGENT'] ?? '', getClientIpAddress(), $trustedDevice['id']]);
    }

    // Set Session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['business_name'] = $user['businessName'];
    regenerateAuthenticatedSession();
    rememberDeviceToken($deviceTokenHash);

    echo json_encode([
        'success' => true,
        'role' => $user['role'],
        'redirect' => $user['role'] === 'supplier' ? 'supplier.php' : ($user['role'] === 'admin' ? 'admin.php' : 'marketplace.php')
    ]);
    exit;
}

if ($action === 'verifyLogin') {
    requireCsrfToken();
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $code = trim((string)($input['code'] ?? ''));
    $verificationType = in_array(($input['verificationType'] ?? 'login'), ['login', 'email'], true) ? ($input['verificationType'] ?? 'login') : 'login';

    if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter the 6-digit verification code for this email address.']);
        exit;
    }

    $userStmt = $pdo->prepare("SELECT id, email, name, businessName, role, isActive, isApproved, approvalStatus, passwordSetupCompletedAt FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'We could not find an account for that email.']);
        exit;
    }

    if ($user['role'] === 'supplier') {
        $approvalStatus = $user['approvalStatus'] ?? ((int)$user['isApproved'] ? 'approved' : 'pending');
        if ((int)$user['isActive'] !== 1 || $approvalStatus !== 'approved') {
            http_response_code(403);
            echo json_encode(['error' => 'Your supplier account is not approved for sign-in.']);
            exit;
        }
        if (empty($user['passwordSetupCompletedAt'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Finish creating your supplier password before signing in.']);
            exit;
        }
        $pendingLogin = $_SESSION['supplier_pending_login'] ?? [];
        if (!is_array($pendingLogin) || (string)($pendingLogin['userId'] ?? '') !== (string)$user['id'] || (int)($pendingLogin['expiresAt'] ?? 0) <= time()) {
            http_response_code(403);
            echo json_encode(['error' => 'Sign in with your password again to request a verification code.']);
            exit;
        }
    }

    if ($user['role'] !== 'admin' && !emailVerificationEnabled($pdo)) {
        http_response_code(403);
        echo json_encode(['error' => 'Verification codes are disabled. Sign in again to continue.']);
        exit;
    }

    $verificationStmt = $pdo->prepare("SELECT id, userId, verificationCode, expiresAt FROM user_login_verifications WHERE userId = ? AND verificationCode = ? AND purpose = ? AND expiresAt > NOW() AND isVerified = 0 ORDER BY createdAt DESC LIMIT 1");
    $verificationStmt->execute([$user['id'], $code, $verificationType]);
    $verification = $verificationStmt->fetch();

    if (!$verification) {
        http_response_code(401);
        echo json_encode(['error' => $verificationType === 'email' ? 'The email verification code is invalid or has expired.' : 'The code is invalid or has expired. Please request a new sign-in code.']);
        exit;
    }

    if ($verificationType === 'email') {
        $verifyEmailStmt = $pdo->prepare("UPDATE users SET emailVerified = 1 WHERE id = ? AND emailVerified = 0");
        $verifyEmailStmt->execute([$user['id']]);
    }

    $deviceTokenHash = getCurrentDeviceTokenHash();
    $deviceInsertStmt = $pdo->prepare("INSERT INTO user_devices (userId, deviceTokenHash, deviceName, userAgent, ipAddress, lastSeenAt, createdAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE lastSeenAt = NOW(), userAgent = VALUES(userAgent), ipAddress = VALUES(ipAddress)");
    $deviceInsertStmt->execute([$user['id'], $deviceTokenHash, 'Current device', $_SERVER['HTTP_USER_AGENT'] ?? '', getClientIpAddress()]);

    $verificationUpdateStmt = $pdo->prepare("UPDATE user_login_verifications SET isVerified = 1 WHERE id = ?");
    $verificationUpdateStmt->execute([$verification['id']]);

    unset($_SESSION['supplier_pending_login']);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['business_name'] = $user['businessName'];
    regenerateAuthenticatedSession();
    rememberDeviceToken($deviceTokenHash);

    echo json_encode([
        'success' => true,
        'role' => $user['role'],
        'redirect' => $user['role'] === 'supplier' ? 'supplier.php' : ($user['role'] === 'admin' ? 'admin.php' : 'marketplace.php')
    ]);
    exit;
}

if ($action === 'signup') {
    requireCsrfToken();
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = $input['password'] ?? '';
    $role = in_array($input['role'] ?? '', ['vendor', 'supplier']) ? $input['role'] : 'vendor';
    $applicationMessage = trim($input['applicationMessage'] ?? '');
    $municipality = 'Guimbal';
    $province = 'Iloilo';
    $barangay = trim((string)($input['barangay'] ?? ''));
    $sitioPurok = trim((string)($input['sitioPurok'] ?? ''));
    $additionalAddress = trim((string)($input['additionalAddress'] ?? ''));
    $profileImage = trim((string)($input['profileImage'] ?? ''));
    $dataProcessingConsent = filter_var($input['dataProcessingConsent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $marketingConsent = filter_var($input['marketingConsent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $registrationImage = (string)($_SESSION['registration_profile_image'] ?? '');
    $businessPermit = (string)($_SESSION['registration_business_permit'] ?? '');
    $address = implode(', ', array_filter([
        $additionalAddress,
        $sitioPurok,
        $barangay,
        $municipality,
        $province,
        'Philippines'
    ], static fn(string $part): bool => $part !== ''));

    $validProfileImage = $profileImage === ''
        || ($registrationImage !== ''
            && hash_equals($registrationImage, $profileImage)
            && preg_match('/^uploads\/profiles\/registration_[a-f0-9]{32}\.(jpg|png|webp)$/i', $profileImage) === 1
            && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $profileImage)));
    $validBusinessPermit = $businessPermit !== ''
        && preg_match('/^uploads\/business-permits\/permit_[a-f0-9]{32}\.(jpg|png|webp)$/i', $businessPermit) === 1
        && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $businessPermit));
    if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191
        || $barangay === '' || strlen($barangay) > 100 || strlen($sitioPurok) > 150
        || strlen($additionalAddress) > 255 || strlen($address) > 2000 || !$validProfileImage || !$validBusinessPermit || !$dataProcessingConsent) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter your name, a valid email address and Guimbal barangay, and upload a valid business permit image.']);
        exit;
    }

    if (!isRealEmailAddress($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please provide a valid email address that belongs to an active domain.']);
        exit;
    }

    if ($role === 'vendor' && strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Vendor applications require a password of at least 8 characters and a business address.']);
        exit;
    }

    $applicationMessageLength = preg_match_all('/./us', $applicationMessage);
    if ($role === 'supplier' && ($applicationMessage === '' || $applicationMessageLength === false || $applicationMessageLength > 1000)) {
        http_response_code(400);
        echo json_encode(['error' => 'Supplier applications require a message explaining the account request (up to 1,000 characters).']);
        exit;
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'An account with this email already exists.']);
        exit;
    }

    $id = bin2hex(random_bytes(16));
    $initialPassword = $role === 'vendor' ? $password : bin2hex(random_bytes(32));
    $hashed = password_hash($initialPassword, PASSWORD_BCRYPT);

    $emailSent = null;
    $verificationEmailBody = null;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (id, email, password, name, businessName, address, municipality, province, barangay, sitioPurok, additionalAddress, profileImage, businessPermitPath, businessPermitReviewStatus, contactNumber, role, isActive, isApproved, approvalStatus, applicationMessage, emailVerified)
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NULL, ?, 1, 0, 'pending', ?, ?)
        ");
        $emailVerified = ($role === 'supplier' || $role === 'vendor') && !emailVerificationEnabled($pdo) ? 1 : 0;
        $stmt->execute([
            $id,
            $email,
            $hashed,
            $name,
            $address ?: null,
            $municipality,
            $province,
            $barangay,
            $sitioPurok ?: null,
            $additionalAddress ?: null,
            $profileImage ?: null,
            $businessPermit,
            $role,
            $role === 'supplier' ? $applicationMessage : null,
            $emailVerified
        ]);

        if (emailVerificationEnabled($pdo) && $emailVerified === 0) {
            $verificationCode = sprintf('%06d', random_int(100000, 999999));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $verificationStmt = $pdo->prepare("INSERT INTO user_login_verifications (userId, verificationCode, purpose, ipAddress, userAgent, expiresAt, isVerified, createdAt) VALUES (?, ?, 'email', ?, ?, ?, 0, NOW())");
            $verificationStmt->execute([$id, $verificationCode, getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]);

            $verificationEmailBody = "Hello {$name},\n\nThank you for joining VendLink. To finish setting up your account, verify your email address with the code below.\n\nYour verification code is: {$verificationCode}\n\nThis code expires in 15 minutes.\n\nRegards,\nVendLink Team";
        }

        $notificationTitle = $role === 'supplier' ? 'New supplier application' : 'New vendor application';
        $notificationMessage = "{$name} ({$email}) submitted a {$role} account application.";
        if ($role === 'supplier') {
            $notificationMessage .= ' Application message: ' . $applicationMessage;
        }
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        $notificationStmt = $pdo->prepare("INSERT INTO notifications (userId, title, message, type, createdAt) VALUES (?, ?, ?, 'account_application', NOW())");
        foreach ($admins as $adminId) {
            $notificationStmt->execute([$adminId, $notificationTitle, $notificationMessage]);
        }

        $consentStmt = $pdo->prepare('INSERT INTO user_consents (userId, consentPurpose, consentGranted, policyVersion, ipAddress, userAgent, consentDetails, createdAt) VALUES (?, ?, 1, ?, ?, ?, ?, NOW())');
        $consentDetails = json_encode(['dataProcessingConsent' => $dataProcessingConsent, 'marketingConsent' => $marketingConsent]);
        $consentStmt->execute([$id, 'account_registration', '2025-01', getClientIpAddress(), $_SERVER['HTTP_USER_AGENT'] ?? '', $consentDetails]);

        if ($marketingConsent) {
            $marketingStmt = $pdo->prepare('INSERT INTO email_preferences (userId, promotionalEmails, transactionalEmails, unsubscribeToken, createdAt, updatedAt) VALUES (?, 1, 1, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE promotionalEmails = 1, transactionalEmails = 1, updatedAt = NOW()');
            $marketingStmt->execute([$id, hash('sha256', $id . ':' . random_bytes(32))]);
        }

        $pdo->commit();
        unset($_SESSION['registration_profile_image']);
        unset($_SESSION['registration_business_permit']);
    } catch (Throwable $signupError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $signupError;
    }

    if ($verificationEmailBody !== null) {
        $emailSent = sendVendLinkEmail($email, 'Verify your VendLink email address', $verificationEmailBody);
    }

    $response = [
        'success' => true,
        'role' => $role,
        'pendingApproval' => true
    ];
    if ($emailSent !== null) {
        $response['emailSent'] = $emailSent;
    }
    echo json_encode($response);
    exit;
}

if ($action === 'logout') {
    requireCsrfToken();
    session_unset();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
} catch (Throwable $e) {
    error_log('VendLink authentication error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Authentication service unavailable.']);
}