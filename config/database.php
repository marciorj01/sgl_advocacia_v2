<?php
/**
 * config/database.php
 * Conexão principal do Sistema SGL Advocacia.
 *
 * Ambiente local padrão: XAMPP + MySQL/MariaDB.
 * Ambiente produção: configurar variáveis de ambiente na Hostinger, quando possível.
 */

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

$hostAtual = $_SERVER['HTTP_HOST'] ?? 'localhost';
$ambienteLocal = in_array($hostAtual, ['localhost', '127.0.0.1'], true)
    || str_starts_with($hostAtual, 'localhost:')
    || str_starts_with($hostAtual, '127.0.0.1:');

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('SGL_DB_HOST') ?: 'localhost');
}

if (!defined('DB_USER')) {
    define('DB_USER', getenv('SGL_DB_USER') ?: ($ambienteLocal ? 'root' : 'ALTERE_USUARIO_HOSTINGER'));
}

if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('SGL_DB_PASS') ?: ($ambienteLocal ? '' : 'ALTERE_SENHA_HOSTINGER'));
}

if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('SGL_DB_NAME') ?: ($ambienteLocal ? 'sistema_sgl_novo' : 'ALTERE_BANCO_HOSTINGER'));
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function conectar(): mysqli
{
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        return $conn;
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        die('<div style="font-family:Arial,sans-serif;padding:30px;color:#842029;background:#f8d7da;border:1px solid #f5c2c7;border-radius:8px;max-width:720px;margin:40px auto;">
            <h3>Erro de conexão com o banco de dados</h3>
            <p>Verifique as configurações em <strong>config/database.php</strong> e confirme se o banco <strong>' . htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8') . '</strong> existe.</p>
            <p><strong>Detalhe técnico:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>
        </div>');
    }
}

function getBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    return $scheme . '://' . $host . ($scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir);
}
