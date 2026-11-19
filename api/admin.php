<?php
require_once __DIR__ . '/../includes/auth_check.php';
checkAuth('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email.php';

header('Content-Type: application/json; charset=utf-8');

function sendSupplierSetupEmail(string $recipient, string $name, string $setupToken): bool
{
    $setupUrl = vendlinkBaseUrl() . '/set-password.html?token=' . rawurlencode($setupToken);
    $emailBody = "Hello {$name},\n\nYour VendLink supplier account has been approved. Create your password using this secure link within 30 minutes:\n\n{$setupUrl}\n\nThe link can be used once. If it expires, contact the VendLink admin team and ask them to resend your password setup email.\n\nVendLink Admin Team";
    return sendVendLinkEmail($recipient, 'Your VendLink supplier account is approved', $emailBody);
}

function storedBusinessPermitExists(mixed $permitPath): bool
{
    if (!is_string($permitPath)
        || preg_match('/^uploads\/business-permits\/permit_[a-f0-9]{32}\.(jpg|png|webp)$/i', $permitPath) !== 1) {
        return false;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $permitPath);
    return is_file($absolutePath);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        requireCsrfToken();
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';

        if ($action === 'editAccount') {
            $userId = is_string($input['userId'] ?? null) ? trim($input['userId']) : '';
            $name = trim((string)($input['name'] ?? ''));
            $username = trim((string)($input['username'] ?? ''));
            $businessName = trim((string)($input['businessName'] ?? ''));
            if ($userId === '' || $name === '' || strlen($name) > 100 || strlen($username) > 50
                || ($username !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1)
                || strlen($businessName) > 100) {
                http_response_code(400);
                echo json_encode(['error' => 'Enter a valid full name, account username, and business name.']);
                exit;
            }

            $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('vendor', 'supplier') LIMIT 1");
            $targetStmt->execute([$userId]);
            if (!$targetStmt->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['error' => 'Vendor or supplier account not found.']);
                exit;
            }

            if ($username !== '') {
                $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $duplicateStmt->execute([$username, $userId]);
                if ($duplicateStmt->fetchColumn()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'That username is already in use.']);
                    exit;
                }
            }

            $updateStmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, businessName = ? WHERE id = ? AND role IN ('vendor', 'supplier')");
            $updateStmt->execute([$name, $username !== '' ? $username : null, $businessName !== '' ? $businessName : null, $userId]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'reviewPermit') {
            $userId = is_string($input['userId'] ?? null) ? trim($input['userId']) : '';
            $reviewStatus = $input['reviewStatus'] ?? '';
            if ($userId === '' || !in_array($reviewStatus, ['verified', 'rejected'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Choose whether the business permit is verified or rejected.']);
                exit;
            }

            $permitStmt = $pdo->prepare("SELECT businessPermitPath FROM users WHERE id = ? AND role IN ('vendor', 'supplier') LIMIT 1");
            $permitStmt->execute([$userId]);
            $permitPath = $permitStmt->fetchColumn();
            if (!storedBusinessPermitExists($permitPath)) {
                http_response_code(404);
                echo json_encode(['error' => 'The uploaded business permit could not be found.']);
                exit;
            }

            $reviewStmt = $pdo->prepare("UPDATE users SET businessPermitReviewStatus = ? WHERE id = ? AND role IN ('vendor', 'supplier')");
            $reviewStmt->execute([$reviewStatus, $userId]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'createSupplier') {
            $name = trim((string)($input['name'] ?? ''));
            $email = strtolower(trim((string)($input['email'] ?? '')));
            if ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
                http_response_code(400);
                echo json_encode(['error' => 'Enter a supplier name and valid email address.']);
                exit;
            }

            $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $duplicateStmt->execute([$email]);
            if ($duplicateStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'An account with this email already exists.']);
                exit;
            }

            $supplierId = bin2hex(random_bytes(16));
            $setupToken = bin2hex(random_bytes(32));
            $pdo->beginTransaction();
            $createStmt = $pdo->prepare("INSERT INTO users (id, email, password, name, role, isActive, isApproved, approvalStatus, accountSetupTokenHash, accountSetupExpiresAt) VALUES (?, ?, ?, ?, 'supplier', 1, 1, 'approved', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
            $createStmt->execute([
                $supplierId,
                $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                $name,
                hash('sha256', $setupToken)
            ]);
            $pdo->commit();
            $emailSent = sendSupplierSetupEmail($email, $name, $setupToken);
            echo json_encode(['success' => true, 'emailSent' => $emailSent]);
            exit;
        }

        $userId = $input['userId'] ?? '';
        $allowedActions = ['approve', 'disapprove', 'delete', 'resendSetup'];
        if (!in_array($action, $allowedActions, true) || !is_string($userId) || $userId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid account action.']);
            exit;
        }

        if ($action === 'resendSetup') {
            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare("SELECT id, name, email, role, isActive, approvalStatus, passwordSetupCompletedAt FROM users WHERE id = ? AND role = 'supplier' FOR UPDATE");
            $targetStmt->execute([$userId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Supplier account not found.']);
                exit;
            }
            if ((int)$target['isActive'] !== 1 || $target['approvalStatus'] !== 'approved' || !empty($target['passwordSetupCompletedAt'])) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => 'This supplier is not waiting for password setup.']);
                exit;
            }

            $setupToken = bin2hex(random_bytes(32));
            $updateStmt = $pdo->prepare("UPDATE users SET accountSetupTokenHash = ?, accountSetupExpiresAt = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ? AND role = 'supplier' AND approvalStatus = 'approved' AND passwordSetupCompletedAt IS NULL");
            $updateStmt->execute([hash('sha256', $setupToken), $userId]);
            $pdo->commit();

            $emailSent = sendSupplierSetupEmail($target['email'], $target['name'], $setupToken);
            echo json_encode(['success' => true, 'emailSent' => $emailSent]);
            exit;
        }

        if ($action === 'delete') {
            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role IN ('vendor', 'supplier') FOR UPDATE");
            $targetStmt->execute([$userId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Vendor or supplier account not found.']);
                exit;
            }

            if ($target['role'] === 'supplier') {
                $deleteSupplierOrderItems = $pdo->prepare('DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.orderId WHERE o.supplierId = ?');
                $deleteSupplierOrderItems->execute([$userId]);
                $deleteProductOrderItems = $pdo->prepare('DELETE oi FROM order_items oi JOIN products p ON p.id = oi.productId WHERE p.supplierId = ?');
                $deleteProductOrderItems->execute([$userId]);
                $deleteSupplierOrders = $pdo->prepare('DELETE FROM orders WHERE supplierId = ?');
                $deleteSupplierOrders->execute([$userId]);
                $deleteProducts = $pdo->prepare('DELETE FROM products WHERE supplierId = ?');
                $deleteProducts->execute([$userId]);
            } else {
                $deleteVendorOrderItems = $pdo->prepare('DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.orderId WHERE o.vendorId = ?');
                $deleteVendorOrderItems->execute([$userId]);
                $deleteVendorOrders = $pdo->prepare('DELETE FROM orders WHERE vendorId = ?');
                $deleteVendorOrders->execute([$userId]);
            }

            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $deleteUser->execute([$userId]);
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
        }

        $pdo->beginTransaction();
        $targetStmt = $pdo->prepare("SELECT id, name, email, role, approvalStatus, passwordSetupCompletedAt, businessPermitPath, businessPermitReviewStatus FROM users WHERE id = ? AND role IN ('vendor', 'supplier') FOR UPDATE");
        $targetStmt->execute([$userId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Vendor or supplier account not found.']);
            exit;
        }

        if ($action === 'approve' && !empty($target['businessPermitPath'])
            && ($target['businessPermitReviewStatus'] !== 'verified' || !storedBusinessPermitExists($target['businessPermitPath']))) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'Verify the uploaded business permit and ensure its file is available before approving the account.']);
            exit;
        }

        if (($action === 'approve' && $target['approvalStatus'] === 'approved') || ($action === 'disapprove' && $target['approvalStatus'] === 'disapproved')) {
            $pdo->commit();
            echo json_encode(['success' => true, 'alreadyProcessed' => true]);
            exit;
        }

        $emailKind = '';
        if ($action === 'approve') {
            if ($target['role'] === 'supplier' && empty($target['passwordSetupCompletedAt'])) {
                $setupToken = bin2hex(random_bytes(32));
                $updateStmt = $pdo->prepare("UPDATE users SET isApproved = 1, approvalStatus = 'approved', accountSetupTokenHash = ?, accountSetupExpiresAt = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
                $updateStmt->execute([hash('sha256', $setupToken), $userId]);
                $emailKind = 'supplierSetup';
            } else {
                $updateStmt = $pdo->prepare("UPDATE users SET isApproved = 1, approvalStatus = 'approved', accountSetupTokenHash = NULL, accountSetupExpiresAt = NULL WHERE id = ?");
                $updateStmt->execute([$userId]);
                $emailKind = 'approved';
            }
        } else {
            $updateStmt = $pdo->prepare("UPDATE users SET isApproved = 0, approvalStatus = 'disapproved', accountSetupTokenHash = NULL, accountSetupExpiresAt = NULL WHERE id = ?");
            $updateStmt->execute([$userId]);
            $emailKind = 'disapproved';
        }

        $pdo->commit();

        if ($emailKind === 'supplierSetup') {
            $emailSent = sendSupplierSetupEmail($target['email'], $target['name'], $setupToken);
        } else {
            $baseUrl = vendlinkBaseUrl();
            $emailSubject = $emailKind === 'approved' ? 'Your VendLink account is approved' : 'Update on your VendLink account application';
            $emailBody = $emailKind === 'approved'
                ? "Hello {$target['name']},\n\nYour VendLink account has been approved. You can now sign in:\n\n{$baseUrl}/index.html#access\n\nVendLink Admin Team"
                : "Hello {$target['name']},\n\nThe VendLink admin team reviewed your account application and could not approve it at this time. Please contact VendLink support if you have questions.\n\nVendLink Admin Team";
            $emailSent = sendVendLinkEmail($target['email'], $emailSubject, $emailBody);
        }
        echo json_encode(['success' => true, 'emailSent' => $emailSent]);
        exit;
    }

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    if (($_GET['action'] ?? '') === 'businessPermit') {
        $userId = is_string($_GET['userId'] ?? null) ? trim($_GET['userId']) : '';
        $permitStmt = $pdo->prepare("SELECT businessPermitPath FROM users WHERE id = ? AND role IN ('vendor', 'supplier') LIMIT 1");
        $permitStmt->execute([$userId]);
        $permitPath = $permitStmt->fetchColumn();
        if (!is_string($permitPath)
            || preg_match('/^uploads\/business-permits\/permit_[a-f0-9]{32}\.(jpg|png|webp)$/i', $permitPath) !== 1) {
            http_response_code(404);
            echo json_encode(['error' => 'Business permit not found.']);
            exit;
        }

        $permitDirectory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'business-permits');
        $permitFile = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $permitPath));
        if ($permitDirectory === false || $permitFile === false || !str_starts_with($permitFile, $permitDirectory . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            echo json_encode(['error' => 'Business permit not found.']);
            exit;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($permitFile);
        $permitMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $permitMimeTypes, true)) {
            http_response_code(415);
            echo json_encode(['error' => 'Business permit image format is not supported.']);
            exit;
        }

        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="business-permit"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($permitFile);
        exit;
    }

    $users = $pdo->query("SELECT id, name, username, email, role, businessName, address, barangay, sitioPurok, applicationMessage, businessPermitReviewStatus, (businessPermitPath IS NOT NULL AND businessPermitPath <> '') AS hasBusinessPermit, isActive, isApproved, approvalStatus, createdAt, CASE WHEN role = 'supplier' AND isActive = 1 AND approvalStatus = 'approved' AND passwordSetupCompletedAt IS NULL THEN 1 ELSE 0 END AS canResendSetup FROM users WHERE role != 'admin' ORDER BY createdAt DESC")->fetchAll();
    $stats = [
        'userCount' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn(),
        'productCount' => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
        'orderTotal' => (float) $pdo->query("SELECT COALESCE(SUM(totalAmount), 0) FROM orders WHERE status != 'cancelled'")->fetchColumn()
    ];
    echo json_encode(['users' => $users, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('VendLink administration error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to complete the administration request.']);
}
