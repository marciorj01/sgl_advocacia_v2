<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/portal_auth.php';

rojexPortalIniciarSessao();

$slug = '';
$conn = null;

try {
    $conn = conectar();

    $tenantId = rojexPortalTenantId();
    $escritorioId = rojexPortalEscritorioId();

    if ($tenantId !== null && $escritorioId !== null) {
        $stmt = $conn->prepare(
            "SELECT subdominio
               FROM escritorios_saas
              WHERE tenant_id=? AND id=?
              LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('si', $tenantId, $escritorioId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $slug = trim((string)($row['subdominio'] ?? ''));
        }
    }

    rojexPortalEncerrarSessao($conn, 'LOGOUT_USUARIO');
} catch (Throwable $e) {
    error_log('[ROJEX PORTAL][LOGOUT] ' . $e->getMessage());
    rojexPortalEncerrarSessaoLocal();
} finally {
    if ($conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
}

$destino = 'login.php?logout=1';
if ($slug !== '') {
    $destino .= '&escritorio=' . rawurlencode($slug);
}

header('Location: ' . $destino, true, 302);
exit();
