<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/env.php';

if (!function_exists('rojex_mail_env')) {
    function rojex_mail_env(string $nome, string $padrao = '', bool $trim = true): string
    {
        $candidatos = [
            getenv($nome),
            $_ENV[$nome] ?? false,
            $_SERVER[$nome] ?? false,
        ];

        foreach ($candidatos as $valor) {
            if ($valor === false) {
                continue;
            }

            $valor = (string) $valor;
            if (trim($valor) === '') {
                continue;
            }

            return $trim ? trim($valor) : $valor;
        }

        return $padrao;
    }
}

$baseUrl = rtrim(rojex_mail_env('ROJEX_APP_URL'), '/');

if ($baseUrl === '' && PHP_SAPI !== 'cli') {
    $https = !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $projectPath = preg_replace('#/(modules|public)/.*$#', '', $script) ?: '';

    if ($host !== '') {
        $baseUrl = $scheme . '://' . $host . rtrim($projectPath, '/');
    }
}

$encryption = strtolower(rojex_mail_env('ROJEX_MAIL_ENCRYPTION', 'tls'));
if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
    $encryption = 'tls';
}

$portDefault = $encryption === 'ssl' ? '465' : '587';
$port = (int) rojex_mail_env('ROJEX_MAIL_PORT', $portDefault);
if ($port < 1 || $port > 65535) {
    $port = (int) $portDefault;
}

return [
    'host' => rojex_mail_env('ROJEX_MAIL_HOST', 'smtp.gmail.com'),
    'port' => $port,
    'username' => rojex_mail_env('ROJEX_MAIL_USERNAME'),
    // Não aplica trim automático para não alterar uma senha legítima.
    'password' => rojex_mail_env('ROJEX_MAIL_PASSWORD', '', false),
    'encryption' => $encryption,
    'from_address' => rojex_mail_env('ROJEX_MAIL_FROM_ADDRESS'),
    'from_name' => rojex_mail_env('ROJEX_MAIL_FROM_NAME', 'ROJEX.AI'),
    'base_url' => $baseUrl,
    'ehlo_domain' => rojex_mail_env('ROJEX_MAIL_EHLO_DOMAIN', 'rojex.ai'),
    'timeout' => max(5, (int) rojex_mail_env('ROJEX_MAIL_TIMEOUT', '30')),
    'max_attempts' => max(1, (int) rojex_mail_env('ROJEX_MAIL_MAX_ATTEMPTS', '5')),
    'processing_timeout_minutes' => max(5, (int) rojex_mail_env('ROJEX_MAIL_PROCESSING_TIMEOUT_MINUTES', '15')),
];