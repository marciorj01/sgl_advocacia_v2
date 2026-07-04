<?php
/**
 * SGL Advocacia - Controle de Autenticação
 * Compatível com sessões antigas e novas.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sgl_usuario_logado(): bool
{
    return !empty($_SESSION['usuario_id']) || !empty($_SESSION['logado']);
}

function verificarLogin(): bool
{
    return sgl_usuario_logado();
}

function estaLogado(): bool
{
    return sgl_usuario_logado();
}

function protegerPagina(): void
{
    if (!sgl_usuario_logado()) {
        header('Location: ' . sgl_login_url());
        exit;
    }
}

function requireLogin(): void
{
    protegerPagina();
}

function sgl_login_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/modules/') !== false) {
        return '../auth/login.php';
    }
    if (strpos($script, '/auth/') !== false) {
        return 'login.php';
    }
    return 'auth/login.php';
}

function usuarioAtual(): array
{
    return [
        'id' => $_SESSION['usuario_id'] ?? null,
        'nome' => $_SESSION['usuario_nome'] ?? ($_SESSION['nome'] ?? ''),
        'usuario' => $_SESSION['usuario_login'] ?? ($_SESSION['usuario'] ?? ''),
        'perfil' => $_SESSION['usuario_perfil'] ?? ($_SESSION['perfil'] ?? ''),
    ];
}
