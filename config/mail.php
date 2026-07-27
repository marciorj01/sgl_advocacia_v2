<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/env.php';

$baseUrl = rtrim((string)(getenv('ROJEX_APP_URL') ?: ''), '/');
if ($baseUrl === '' && PHP_SAPI !== 'cli') {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $projectPath = preg_replace('#/(modules|public)/.*$#', '', $script) ?: '';
    if ($host !== '') {
        $baseUrl = $scheme . '://' . $host . rtrim($projectPath, '/');
    }
}

return [
    'host' => trim((string)(getenv('ROJEX_MAIL_HOST') ?: '')),
    'port' => (int)(getenv('ROJEX_MAIL_PORT') ?: 587),
    'username' => trim((string)(getenv('ROJEX_MAIL_USERNAME') ?: '')),
    'password' => (string)(getenv('ROJEX_MAIL_PASSWORD') ?: ''),
    'encryption' => strtolower(trim((string)(getenv('ROJEX_MAIL_ENCRYPTION') ?: 'tls'))),
    'from_address' => trim((string)(getenv('ROJEX_MAIL_FROM_ADDRESS') ?: '')),
    'from_name' => trim((string)(getenv('ROJEX_MAIL_FROM_NAME') ?: 'ROJEX.AI')),
    'base_url' => $baseUrl,
    'timeout' => 20,
    'max_attempts' => 5,
];
