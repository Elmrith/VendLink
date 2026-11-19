<?php
require_once __DIR__ . '/../includes/auth_check.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

checkAuth('admin');
require_once __DIR__ . '/../config/database.php';

try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tableStructures = [];
    foreach ($tables as $table) {
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            continue;
        }
        $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $tableStructures[$table] = array_map(static fn(array $column): array => [
            'name' => $column['Field'],
            'type' => $column['Type'],
            'nullable' => $column['Null'] === 'YES',
            'key' => $column['Key'],
        ], $columns);
    }

    $foreignKeys = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'tables' => $tableStructures,
        'foreignKeys' => array_map(static fn(array $key): array => [
            'table' => $key['TABLE_NAME'],
            'column' => $key['COLUMN_NAME'],
            'referencesTable' => $key['REFERENCED_TABLE_NAME'],
            'referencesColumn' => $key['REFERENCED_COLUMN_NAME'],
        ], $foreignKeys),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('VendLink database diagnostic failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to inspect database structure.']);
}
