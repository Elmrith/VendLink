<?php

function vendlink_env(string $key, string $default = ''): string
{
    static $envLoaded = false;

    if (!$envLoaded) {
        $envFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFilePath) && strtolower((string)($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '')) !== 'production') {
            $lines = @file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    if (!str_contains($trimmed, '=')) {
                        continue;
                    }
                    [$name, $value] = array_map('trim', explode('=', $trimmed, 2));
                    if ($name === '') {
                        continue;
                    }
                    $value = preg_replace('/^(["\'])|(["\'])$/', '', $value) ?? $value;
                    if (!array_key_exists($name, $_ENV)) {
                        $_ENV[$name] = $value;
                    }
                    if (!array_key_exists($name, $_SERVER)) {
                        $_SERVER[$name] = $value;
                    }
                    putenv($name . '=' . $value);
                }
            }
        }
        $envLoaded = true;
    }

    foreach ([$_ENV, $_SERVER, $_GET, $_POST] as $scope) {
        if (is_array($scope) && array_key_exists($key, $scope) && (string)$scope[$key] !== '') {
            return (string)$scope[$key];
        }
    }

    $hostValue = getenv($key);
    if (is_string($hostValue) && $hostValue !== '') {
        return $hostValue;
    }

    return $default;
}
