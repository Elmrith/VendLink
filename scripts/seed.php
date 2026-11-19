<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$ensureColumns = [
    'users' => [
        'username' => 'VARCHAR(50) NULL',
        'role' => "ENUM('admin', 'vendor', 'supplier') NOT NULL DEFAULT 'vendor'",
        'isActive' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'isApproved' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'approvalStatus' => "ENUM('pending', 'approved', 'disapproved') NOT NULL DEFAULT 'pending'",
        'notificationsEnabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ],
];

foreach ($ensureColumns as $table => $columns) {
    $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

$accounts = [];

$adminPassword = getenv('VENDLINK_ADMIN_PASSWORD') ?: '';
if ($adminPassword !== '') {
    $adminEmail = strtolower(trim(getenv('VENDLINK_ADMIN_EMAIL') ?: 'admin@vendlink.com'));
    $adminPasswordLength = preg_match_all('/./us', $adminPassword);
    if ($adminPasswordLength === false || $adminPasswordLength < 12 || strlen($adminPassword) > 72 || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Set VENDLINK_ADMIN_PASSWORD to 12-72 characters and VENDLINK_ADMIN_EMAIL to a valid email.\n");
        exit(1);
    }
    array_unshift($accounts, [
        'id' => 'admin-01',
        'email' => $adminEmail,
        'password' => $adminPassword,
        'name' => 'System Admin',
        'role' => 'admin',
        'username' => 'admin'
    ]);
} else {
    echo "Skipping admin seed. Set VENDLINK_ADMIN_PASSWORD to create the admin account.\n";
}

$insert = $pdo->prepare("INSERT INTO users (id, email, password, name, role, username, isActive, isApproved, approvalStatus, createdAt) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 'approved', NOW()) ON DUPLICATE KEY UPDATE email = VALUES(email)");

foreach ($accounts as $a) {
    // check if exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$a['email']]);
    if ($stmt->fetch()) {
        echo "Skipping existing: {$a['email']}\n";
        continue;
    }
    $hash = password_hash($a['password'], PASSWORD_BCRYPT);
    $insert->execute([$a['id'], $a['email'], $hash, $a['name'], $a['role'], $a['username']]);
    echo $a['role'] === 'admin'
        ? "Created admin account: {$a['email']}\n"
        : "Created account: {$a['email']} (password: {$a['password']})\n";
}

echo "Seeding complete.\n";
