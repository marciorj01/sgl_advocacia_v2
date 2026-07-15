<?php
/**
 * config/auth.php
 * Núcleo de sessão e autenticação do ROJEX.AI ERP Jurídico Enterprise.
 *
 * Compatibilidade preservada:
 * - iniciarSessaoSegura()
 * - usuarioLogado()
 * - exigirLogin()
 * - gerarTokenCsrf()
 * - validarTokenCsrf()
 */

declare(strict_types=1);

if (!defined('ROJEX_SESSION_NAME')) {
    define('ROJEX_SESSION_NAME', 'ROJEXSESSID');
}

if (!defined('ROJEX_SESSION_IDLE_TIMEOUT')) {
    // 60 minutos sem atividade.
    define('ROJEX_SESSION_IDLE_TIMEOUT', 3600);
}

if (!defined('ROJEX_SESSION_ABSOLUTE_TIMEOUT')) {
    // 12 horas desde o início da sessão autenticada.
    define('ROJEX_SESSION_ABSOLUTE_TIMEOUT', 43200);
}

if (!defined('ROJEX_SESSION_REGENERATE_INTERVAL')) {
    // Renova o identificador da sessão a cada 15 minutos.
    define('ROJEX_SESSION_REGENERATE_INTERVAL', 900);
}

/**
 * Detecta HTTPS sem usar HTTP_HOST para definir o ambiente.
 *
 * Em produção, ROJEX_APP_URL deve ser a fonte oficial.
 */
if (!function_exists('rojexRequisicaoHttps')) {
    function rojexRequisicaoHttps(): bool
    {
        if (
            defined('ROJEX_APP_URL')
            && ROJEX_APP_URL !== ''
            && str_starts_with(strtolower((string)ROJEX_APP_URL), 'https://')
        ) {
            return true;
        }

        return (
            !empty($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off'
        ) || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }
}

/**
 * Remove completamente a sessão atual e seu cookie.
 */
if (!function_exists('rojexEncerrarSessaoLocal')) {
    function rojexEncerrarSessaoLocal(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

/**
 * Valida limites temporais e renova periodicamente o ID da sessão.
 *
 * A checagem só é aplicada a sessões autenticadas, preservando a tela de login.
 */
if (!function_exists('rojexValidarCicloSessao')) {
    function rojexValidarCicloSessao(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
            return;
        }

        $agora = time();
        $iniciadaEm = (int)($_SESSION['sessao_iniciada_em'] ?? $agora);
        $ultimaAtividade = (int)($_SESSION['ultima_atividade'] ?? $agora);
        $regeneradaEm = (int)($_SESSION['sessao_regenerada_em'] ?? $agora);

        $_SESSION['sessao_iniciada_em'] = $iniciadaEm;

        $expiradaPorInatividade =
            ROJEX_SESSION_IDLE_TIMEOUT > 0
            && ($agora - $ultimaAtividade) > ROJEX_SESSION_IDLE_TIMEOUT;

        $expiradaPorDuracao =
            ROJEX_SESSION_ABSOLUTE_TIMEOUT > 0
            && ($agora - $iniciadaEm) > ROJEX_SESSION_ABSOLUTE_TIMEOUT;

        if ($expiradaPorInatividade || $expiradaPorDuracao) {
            rojexEncerrarSessaoLocal();
            return;
        }

        if (
            ROJEX_SESSION_REGENERATE_INTERVAL > 0
            && ($agora - $regeneradaEm) >= ROJEX_SESSION_REGENERATE_INTERVAL
        ) {
            session_regenerate_id(true);
            $_SESSION['sessao_regenerada_em'] = $agora;
        }

        $_SESSION['ultima_atividade'] = $agora;

        // Mantém compatibilidade com módulos que já utilizam este campo.
        $_SESSION['ultimo_acesso'] = $agora;
    }
}

if (!function_exists('iniciarSessaoSegura')) {
    function iniciarSessaoSegura(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            rojexValidarCicloSessao();
            return;
        }

        $https = rojexRequisicaoHttps();

        /*
         * Configurações explícitas de segurança antes de session_start().
         * As chamadas com @ preservam compatibilidade em hospedagens que
         * bloqueiem alguma diretiva no nível da aplicação.
         */
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        @ini_set('session.cookie_secure', $https ? '1' : '0');

        session_name(ROJEX_SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Não foi possível iniciar a sessão.');
        }

        if (!isset($_SESSION['sessao_criada_em'])) {
            $_SESSION['sessao_criada_em'] = time();
        }

        rojexValidarCicloSessao();
    }
}

if (!function_exists('usuarioLogado')) {
    function usuarioLogado(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('exigirLogin')) {
    function exigirLogin(string $redirect = 'auth/login.php'): void
    {
        if (usuarioLogado()) {
            return;
        }

        /*
         * Aceita somente destino interno relativo.
         * Evita que entradas externas sejam utilizadas como redirecionamento.
         */
        $redirect = trim($redirect);

        if (
            $redirect === ''
            || str_contains($redirect, "\r")
            || str_contains($redirect, "\n")
            || preg_match('#^(?:https?:)?//#i', $redirect)
        ) {
            $redirect = 'auth/login.php';
        }

        header('Location: ' . $redirect, true, 302);
        exit();
    }
}

if (!function_exists('gerarTokenCsrf')) {
    function gerarTokenCsrf(): string
    {
        if (
            empty($_SESSION['csrf_token'])
            || !is_string($_SESSION['csrf_token'])
            || strlen($_SESSION['csrf_token']) !== 64
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validarTokenCsrf')) {
    function validarTokenCsrf(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Rotaciona o token CSRF após eventos sensíveis, como login ou troca de senha.
 */
if (!function_exists('rotacionarTokenCsrf')) {
    function rotacionarTokenCsrf(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}

/**
 * Inicializa os metadados de uma sessão recém-autenticada.
 *
 * Deve ser chamada após session_regenerate_id(true) no login.
 */
if (!function_exists('registrarInicioSessaoAutenticada')) {
    function registrarInicioSessaoAutenticada(): void
    {
        $agora = time();

        $_SESSION['sessao_iniciada_em'] = $agora;
        $_SESSION['sessao_regenerada_em'] = $agora;
        $_SESSION['ultima_atividade'] = $agora;
        $_SESSION['ultimo_acesso'] = $agora;

        rotacionarTokenCsrf();
    }
}
