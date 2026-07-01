<?php
/**
 * config/conexao.php
 * Compatibilidade para arquivos antigos que usam PDO.
 * A configuração oficial fica em config/database.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Erro na conexão PDO com o banco de dados. Verifique config/database.php.');
}
