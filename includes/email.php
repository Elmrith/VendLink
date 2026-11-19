<?php

function vendlinkBaseUrl(): string
{
    $configuredUrl = trim((string)(getenv('VENDLINK_BASE_URL') ?: ''));
    if ($configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (!filter_var($configuredUrl, FILTER_VALIDATE_URL)
            || !in_array($scheme, $isLocalHost ? ['http', 'https'] : ['https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('VENDLINK_BASE_URL must be a valid HTTPS application URL.');
        }
        return rtrim($configuredUrl, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!is_string($host) || preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host) !== 1) {
        throw new RuntimeException('The request host is not configured for VendLink email links.');
    }
    $hostName = strtolower((string)parse_url('https://' . $host, PHP_URL_HOST));
    $isLocalHost = in_array($hostName, ['localhost', '127.0.0.1'], true);
    if (!$isLocalHost && ($hostName !== 'vendlink.rf.gd' || str_contains($host, ':'))) {
        throw new RuntimeException('The request host is not configured for VendLink email links.');
    }
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isLocalHost && !$isHttps ? 'http' : 'https';
    $projectPath = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/endpoint.php')));
    $projectPath = $projectPath === '/' || $projectPath === '.' ? '' : '/' . trim($projectPath, '/');

    return $scheme . '://' . $host . $projectPath;
}

function sendVendLinkEmail(string $recipient, string $subject, string $message): bool
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        error_log('VendLink email not sent: PHPMailer is missing. Run composer install.');
        return false;
    }

    $mail = null;
    $smtpUsername = '';
    $smtpPassword = '';

    try {
        $configPath = __DIR__ . '/../config/email_config.php';
        if (!is_file($configPath)) {
            error_log('VendLink email not sent: config/email_config.php is missing.');
            return false;
        }

        $emailConfig = require $configPath;
        if (!is_array($emailConfig)) {
            error_log('VendLink email not sent: email_config.php must return an array.');
            return false;
        }

        $smtpUsername = $emailConfig['username'] ?? '';
        $smtpPassword = $emailConfig['password'] ?? '';
        $sender = $emailConfig['from'] ?? '';
        $smtpHost = $emailConfig['host'] ?? '';
        $smtpPortValue = $emailConfig['port'] ?? '';
        $smtpPort = filter_var($smtpPortValue, FILTER_VALIDATE_INT);

        if (!is_string($smtpUsername) || !is_string($smtpPassword) || !is_string($sender) || !is_string($smtpHost)
            || $smtpUsername === '' || $smtpPassword === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)
            || strtolower($smtpHost) !== 'smtp-relay.brevo.com' || $smtpPort !== 587) {
            error_log('VendLink email not sent: Brevo SMTP configuration is incomplete or invalid.');
            return false;
        }

        require_once $autoloadPath;
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($sender, 'VendLink');
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->isHTML(false);
        $mail->send();
        return true;
    } catch (Throwable $error) {
        $smtpError = is_object($mail) && isset($mail->ErrorInfo) && $mail->ErrorInfo !== ''
            ? $mail->ErrorInfo
            : $error->getMessage();
        if ($smtpUsername !== '') {
            $smtpError = str_replace($smtpUsername, '[SMTP username]', $smtpError);
        }
        if ($smtpPassword !== '') {
            $smtpError = str_replace($smtpPassword, '[SMTP password]', $smtpError);
        }
        $smtpError = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $smtpError);
        error_log('VendLink SMTP delivery failed (' . get_class($error) . ', code ' . (int)$error->getCode() . '): ' . substr($smtpError, 0, 1000));
        return false;
    }
}
