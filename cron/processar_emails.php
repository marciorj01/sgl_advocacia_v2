<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida somente por CLI.');
}

require_once __DIR__ . '/../bootstrap/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/EmailService.php';

$conn = conectar();
$resultado = EmailService::fromProject($conn)->processarFila(20);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
