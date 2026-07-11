<?php
/**
 * auth/logout.php
 * Encerramento seguro de sessão com auditoria Enterprise.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/integracoes.php';

iniciarSessaoSegura();

// O registro deve ocorrer antes da limpeza da sessão.
try {
    if (function_exists('sgl_registrar_log') && !empty($_SESSION['user_id'])) {
        $conn = conectar();

        sgl_registrar_log(
            $conn,
            'Logout realizado com sucesso',
            'usuarios',
            (string)$_SESSION['user_id'],
            'Sessão encerrada normalmente pelo usuário.',
            [
                'tipo_acao' => 'LOGOUT',
                'modulo' => 'Autenticação',
                'origem' => 'Encerramento de sessão',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
                'dados_anteriores' => [
                    'usuario_id' => (int)($_SESSION['user_id'] ?? 0),
                    'usuario' => (string)($_SESSION['username'] ?? ''),
                    'nome' => (string)($_SESSION['nome'] ?? ''),
                    'perfil' => (string)($_SESSION['perfil'] ?? ''),
                    'ultimo_acesso' => $_SESSION['ultimo_acesso'] ?? null,
                ],
                'dados_novos' => [
                    'sessao_encerrada' => true,
                ],
            ]
        );

        $conn->close();
    }
} catch (Throwable $e) {
    // O usuário deve conseguir sair mesmo se a auditoria falhar.
    error_log('ROJEX LOGOUT LOG: ' . $e->getMessage());
}

// Limpa todas as variáveis da sessão.
$_SESSION = [];

// Remove também o cookie da sessão.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}

// Encerra definitivamente a sessão.
session_destroy();

header('Location: login.php');
exit();