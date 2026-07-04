<?php
/**
 * SGL Advocacia - Conexão com Banco de Dados
 * Correção emergencial Login / usuários_sistema
 */

declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'sistema_sgl';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

if (!defined('DB_HOST')) define('DB_HOST', $DB_HOST);
if (!defined('DB_NAME')) define('DB_NAME', $DB_NAME);
if (!defined('DB_USER')) define('DB_USER', $DB_USER);
if (!defined('DB_PASS')) define('DB_PASS', $DB_PASS);
if (!defined('DB_CHARSET')) define('DB_CHARSET', $DB_CHARSET);

function sgl_conectar(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        die('Erro ao conectar ao banco de dados. Verifique o XAMPP/MySQL e o arquivo config/database.php. Detalhe: ' . $e->getMessage());
    }
}

$pdo = sgl_conectar();
$conn = $pdo; // compatibilidade com arquivos antigos do sistema
$conexao = $pdo; // compatibilidade adicional
