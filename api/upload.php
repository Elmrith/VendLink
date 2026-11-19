<?php
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();
$purpose = (string)($_POST['purpose'] ?? 'product');
if ($purpose === 'product') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? null) !== 'supplier') {
        http_response_code(403);
        echo json_encode(['error' => 'Only suppliers may upload product images.']);
        exit;
    }
} elseif ($purpose === 'profile') {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['vendor', 'supplier', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in to upload a profile picture.']);
        exit;
    }
} elseif ($purpose === 'registration') {
    $attempts = $_SESSION['registration_profile_uploads'] ?? ['startedAt' => time(), 'count' => 0];
    if (!is_array($attempts) || time() - (int)($attempts['startedAt'] ?? 0) >= 3600) {
        $attempts = ['startedAt' => time(), 'count' => 0];
    }
    if ((int)($attempts['count'] ?? 0) >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many image uploads. Try again later.']);
        exit;
    }
    $attempts['count'] = (int)$attempts['count'] + 1;
    $_SESSION['registration_profile_uploads'] = $attempts;
} elseif ($purpose === 'business_permit') {
    $attempts = $_SESSION['registration_permit_uploads'] ?? ['startedAt' => time(), 'count' => 0];
    if (!is_array($attempts) || time() - (int)($attempts['startedAt'] ?? 0) >= 3600) {
        $attempts = ['startedAt' => time(), 'count' => 0];
    }
    if ((int)($attempts['count'] ?? 0) >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many permit uploads. Try again later.']);
        exit;
    }
    $attempts['count'] = (int)$attempts['count'] + 1;
    $_SESSION['registration_permit_uploads'] = $attempts;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image upload purpose.']);
    exit;
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Please select an image to upload.']);
    exit;
}

$file = $_FILES['image'];
$requiredKeys = ['error', 'size', 'tmp_name', 'name'];
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $file) || !is_scalar($file[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid image upload.']);
        exit;
    }
}
$file['tmp_name'] = (string)$file['tmp_name'];
$file['name'] = (string)$file['name'];
if (!is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image upload.']);
    exit;
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = match ((int)$file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image must be 5MB or smaller.',
        UPLOAD_ERR_NO_FILE => 'Please select an image to upload.',
        default => 'The image upload failed.'
    };
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit;
}

if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'The image must be 5MB or smaller.']);
    exit;
}

try {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
} catch (Throwable $e) {
    error_log('VendLink image MIME detection error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Image validation is temporarily unavailable.']);
    exit;
}
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
];
if (!isset($extensions[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Only JPEG, PNG, and WebP images are allowed.']);
    exit;
}

$imageInfo = @getimagesize($file['tmp_name']);
if (!is_array($imageInfo) || !in_array($imageInfo[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'The selected file is not a valid JPEG, PNG, or WebP image.']);
    exit;
}

$randomName = bin2hex(random_bytes(16));
if ($purpose === 'profile') {
    $userHash = substr(hash('sha256', (string)$_SESSION['user_id']), 0, 12);
    $filename = 'profile_' . $userHash . '_' . $randomName . '.' . $extensions[$mime];
    $relativeDirectory = 'uploads/profiles/';
} elseif ($purpose === 'registration') {
    $filename = 'registration_' . $randomName . '.' . $extensions[$mime];
    $relativeDirectory = 'uploads/profiles/';
} elseif ($purpose === 'business_permit') {
    $filename = 'permit_' . $randomName . '.' . $extensions[$mime];
    $relativeDirectory = 'uploads/business-permits/';
} else {
    $originalName = pathinfo((string)($file['name'] ?? 'product'), PATHINFO_FILENAME);
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $originalName), '-'));
    $slug = substr($slug ?: 'product', 0, 40);
    $filename = 'prod_' . bin2hex(random_bytes(6)) . '_' . $slug . '.' . $extensions[$mime];
    $relativeDirectory = 'uploads/products/';
}
$directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    error_log('VendLink upload directory could not be created: ' . $directory);
    http_response_code(500);
    echo json_encode(['error' => 'Image upload is temporarily unavailable.']);
    exit;
}

$target = $directory . $filename;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    error_log('VendLink uploaded image could not be moved to: ' . $target);
    http_response_code(500);
    echo json_encode(['error' => 'Image upload is temporarily unavailable.']);
    exit;
}

@chmod($target, 0644);
$url = $relativeDirectory . $filename;
if ($purpose === 'registration') {
    $_SESSION['registration_profile_image'] = $url;
} elseif ($purpose === 'business_permit') {
    $_SESSION['registration_business_permit'] = $url;
    echo json_encode(['success' => true]);
    exit;
}
echo json_encode(['success' => true, 'url' => $url]);
