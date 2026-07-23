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
            /*
             * Revalida o escritório no banco em toda entrada protegida.
             * Assim, uma sessão criada antes do encerramento também perde o
             * acesso na requisição seguinte. O MASTER permanece isento.
             */
            global $conn;

            if (
                $conn instanceof mysqli
                && !rojexRevalidarAcessoEscritorioSessao($conn)
            ) {
                $mensagem = 'Acesso bloqueado: este escritório está encerrado.';

                rojexEncerrarSessaoLocal();
                iniciarSessaoSegura();
                $_SESSION['erro_login'] = $mensagem;
                $_SESSION['mensagem_login'] = $mensagem;

                $redirect = trim($redirect);
                if (
                    $redirect === ''
                    || str_contains($redirect, "\r")
                    || str_contains($redirect, "\n")
                    || preg_match('#^(?:https?:)?//#i', $redirect)
                ) {
                    $redirect = 'auth/login.php';
                }

                $separador = str_contains($redirect, '?') ? '&' : '?';
                header(
                    'Location: ' . $redirect . $separador
                    . 'erro=' . rawurlencode($mensagem),
                    true,
                    302
                );
                exit();
            }

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

/**
 * -----------------------------------------------------------------------------
 * CONTEXTO MULTI-TENANT ENTERPRISE — SPRINT 4.6
 * -----------------------------------------------------------------------------
 *
 * Esta camada não autentica credenciais. Ela organiza, valida e disponibiliza
 * o contexto SaaS após o login, preservando todas as chaves antigas da sessão.
 */

if (!function_exists('rojexAuthTabelaExiste')) {
    function rojexAuthTabelaExiste(mysqli $conn, string $tabela): bool
    {
        static $cache = [];

        if (!preg_match('/^[A-Za-z0-9_]+$/', $tabela)) {
            return false;
        }

        $chave = spl_object_id($conn) . ':' . $tabela;

        if (array_key_exists($chave, $cache)) {
            return $cache[$chave];
        }

        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?"
            );

            if (!$stmt) {
                return $cache[$chave] = false;
            }

            $stmt->bind_param('s', $tabela);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $cache[$chave] = ((int)($row['total'] ?? 0) > 0);
        } catch (Throwable $e) {
            error_log('[ROJEX TENANT][TABELA] ' . $e->getMessage());
            return $cache[$chave] = false;
        }
    }
}

if (!function_exists('rojexAuthColunaExiste')) {
    function rojexAuthColunaExiste(mysqli $conn, string $tabela, string $coluna): bool
    {
        static $cache = [];

        if (
            !preg_match('/^[A-Za-z0-9_]+$/', $tabela)
            || !preg_match('/^[A-Za-z0-9_]+$/', $coluna)
        ) {
            return false;
        }

        $chave = spl_object_id($conn) . ':' . $tabela . ':' . $coluna;

        if (array_key_exists($chave, $cache)) {
            return $cache[$chave];
        }

        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?"
            );

            if (!$stmt) {
                return $cache[$chave] = false;
            }

            $stmt->bind_param('ss', $tabela, $coluna);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $cache[$chave] = ((int)($row['total'] ?? 0) > 0);
        } catch (Throwable $e) {
            error_log('[ROJEX AUTH][COLUNA] ' . $e->getMessage());
            return $cache[$chave] = false;
        }
    }
}

if (!function_exists('rojexEscritorioEncerrado')) {
    function rojexEscritorioEncerrado(?string $status): bool
    {
        return mb_strtolower(trim((string)$status), 'UTF-8') === 'encerrado';
    }
}

if (!function_exists('rojexRegistrarBloqueioEscritorioEncerrado')) {
    /**
     * Registra a tentativa negada sem permitir que uma falha de LOG libere
     * acesso ou quebre a autenticação.
     */
    function rojexRegistrarBloqueioEscritorioEncerrado(
        mysqli $conn,
        int $escritorioId,
        string $tenantId,
        string $origem
    ): void {
        try {
            if (!rojexAuthTabelaExiste($conn, 'logs_sistema')) {
                return;
            }

            $usuarioId = (int)($_SESSION['user_id'] ?? 0);
            $usuarioIdLog = $usuarioId > 0 ? $usuarioId : null;
            $usuarioNome = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário');
            $usuarioLogin = (string)($_SESSION['username'] ?? '');
            $usuarioPerfil = (string)($_SESSION['perfil'] ?? 'Perfil não informado');
            $acao = 'Acesso bloqueado: escritório encerrado';
            $tabela = 'escritorios_saas';
            $registroId = (string)$escritorioId;
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $detalhes = sprintf(
                'Tentativa negada no %s. Tenant: %s; escritório_id: %d.',
                $origem,
                $tenantId,
                $escritorioId
            );

            $possuiTenant = rojexAuthColunaExiste($conn, 'logs_sistema', 'tenant_id');
            $possuiEscritorio = rojexAuthColunaExiste($conn, 'logs_sistema', 'escritorio_id');

            if ($possuiTenant && $possuiEscritorio) {
                $stmt = $conn->prepare(
                    "INSERT INTO logs_sistema
                        (usuario_id, usuario_nome, usuario_login, usuario_perfil,
                         acao, tabela, registro_id, detalhes, ip, tenant_id, escritorio_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if ($stmt) {
                    $stmt->bind_param(
                        'isssssssssi',
                        $usuarioIdLog,
                        $usuarioNome,
                        $usuarioLogin,
                        $usuarioPerfil,
                        $acao,
                        $tabela,
                        $registroId,
                        $detalhes,
                        $ip,
                        $tenantId,
                        $escritorioId
                    );
                }
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO logs_sistema
                        (usuario_id, usuario_nome, usuario_login, usuario_perfil,
                         acao, tabela, registro_id, detalhes, ip)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if ($stmt) {
                    $stmt->bind_param(
                        'issssssss',
                        $usuarioIdLog,
                        $usuarioNome,
                        $usuarioLogin,
                        $usuarioPerfil,
                        $acao,
                        $tabela,
                        $registroId,
                        $detalhes,
                        $ip
                    );
                }
            }

            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[ROJEX AUTH][LOG BLOQUEIO] ' . $e->getMessage());
        }
    }
}

if (!function_exists('rojexRevalidarAcessoEscritorioSessao')) {
    /**
     * Confere no banco o estado atual do escritório da sessão.
     *
     * Retorna false somente quando um usuário de tenant aponta para escritório
     * encerrado ou para um vínculo que deixou de existir. O MASTER principal
     * conserva o acesso à plataforma e ao suporte para fins de administração.
     */
    function rojexRevalidarAcessoEscritorioSessao(mysqli $conn): bool
    {
        if (!usuarioLogado()) {
            return true;
        }

        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $perfil = (string)($_SESSION['perfil'] ?? '');

        if (rojexUsuarioEhMasterSaas($conn, $usuarioId, $perfil)) {
            return true;
        }

        $contexto = isset($_SESSION['tenant']) && is_array($_SESSION['tenant'])
            ? $_SESSION['tenant']
            : [];

        if (($contexto['tipo_contexto'] ?? null) !== 'tenant') {
            return true;
        }

        $escritorioId = (int)($_SESSION['escritorio_id'] ?? 0);
        $tenantId = trim((string)($_SESSION['tenant_id'] ?? ''));

        if (
            $escritorioId <= 0
            || $tenantId === ''
            || !rojexAuthTabelaExiste($conn, 'escritorios_saas')
        ) {
            return false;
        }

        try {
            $stmt = $conn->prepare(
                "SELECT status
                   FROM escritorios_saas
                  WHERE id = ?
                    AND tenant_id = ?
                  LIMIT 1"
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('is', $escritorioId, $tenantId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                rojexRegistrarBloqueioEscritorioEncerrado(
                    $conn,
                    $escritorioId,
                    $tenantId,
                    'revalidação de sessão com vínculo inexistente'
                );
                return false;
            }

            $statusAtual = (string)($row['status'] ?? '');
            $_SESSION['tenant']['escritorio_status'] = $statusAtual;

            if (!rojexEscritorioEncerrado($statusAtual)) {
                return true;
            }

            rojexRegistrarBloqueioEscritorioEncerrado(
                $conn,
                $escritorioId,
                $tenantId,
                'revalidação de sessão'
            );
            return false;
        } catch (Throwable $e) {
            error_log('[ROJEX AUTH][REVALIDACAO] ' . $e->getMessage());

            // Falha segura para uma sessão tenant que não pôde ser revalidada.
            return false;
        }
    }
}

if (!function_exists('rojexAuthConfiguracao')) {
    function rojexAuthConfiguracao(mysqli $conn, string $chave, string $padrao = ''): string
    {
        if (!rojexAuthTabelaExiste($conn, 'configuracoes')) {
            return $padrao;
        }

        try {
            $stmt = $conn->prepare(
                "SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1"
            );

            if (!$stmt) {
                return $padrao;
            }

            $stmt->bind_param('s', $chave);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ? (string)$row['valor'] : $padrao;
        } catch (Throwable $e) {
            error_log('[ROJEX TENANT][CONFIG] ' . $e->getMessage());
            return $padrao;
        }
    }
}

if (!function_exists('rojexUsuarioEhMasterSaas')) {
    function rojexUsuarioEhMasterSaas(mysqli $conn, int $usuarioId, ?string $perfil = null): bool
    {
        if ($usuarioId <= 0) {
            return false;
        }

        $perfil = trim((string)($perfil ?? ($_SESSION['perfil'] ?? '')));

        if (strcasecmp($perfil, 'Administrador Master') === 0) {
            return true;
        }

        return (int)rojexAuthConfiguracao($conn, 'usuario_master_id', '0') === $usuarioId;
    }
}

/**
 * Retorna o nome canônico de um perfil interno da plataforma.
 *
 * Esses perfis pertencem à operação da ROJEX.AI, não a um escritório cliente,
 * e jamais equivalem ao MASTER principal.
 */
if (!function_exists('rojexPerfilEquipeInterna')) {
    function rojexPerfilEquipeInterna(?string $perfil = null): ?string
    {
        $perfil = trim((string)($perfil ?? ($_SESSION['perfil'] ?? '')));

        if ($perfil === '') {
            return null;
        }

        $perfis = [
            'suporte rojex' => 'Suporte ROJEX',
            'comercial rojex' => 'Comercial ROJEX',
            'financeiro rojex' => 'Financeiro ROJEX',
            'operador rojex' => 'Operador ROJEX',
            'auditor rojex' => 'Auditor ROJEX',
        ];

        return $perfis[mb_strtolower($perfil, 'UTF-8')] ?? null;
    }
}

if (!function_exists('rojexLimparContextoTenant')) {
    function rojexLimparContextoTenant(bool $preservarModoPlataforma = false): void
    {
        unset(
            $_SESSION['tenant'],
            $_SESSION['tenant_id'],
            $_SESSION['escritorio_id'],
            $_SESSION['licenca_id'],
            $_SESSION['licenca_chave'],
            $_SESSION['licenca_status'],
            $_SESSION['plano_id'],
            $_SESSION['plano'],
            $_SESSION['papel_tenant'],
            $_SESSION['permissoes_tenant'],
            $_SESSION['modulos_tenant']
        );

        if (!$preservarModoPlataforma) {
            unset($_SESSION['modo_plataforma']);
        }

        $_SESSION['modo_suporte'] = false;
    }
}

if (!function_exists('rojexDefinirContextoPlataforma')) {
    function rojexDefinirContextoPlataforma(): array
    {
        rojexLimparContextoTenant(true);

        $contexto = [
            'tipo_contexto' => 'plataforma',
            'tenant_id' => null,
            'escritorio_id' => null,
            'escritorio_nome' => null,
            'escritorio_status' => null,
            'licenca_id' => null,
            'licenca_chave' => null,
            'licenca_status' => null,
            'plano_id' => null,
            'plano' => null,
            'assinatura_status' => null,
            'papel' => 'master_saas',
            'principal' => true,
            'modo_suporte' => false,
            'permissoes' => ['plataforma_total'],
            'modulos' => [],
            'carregado_em' => date('c'),
        ];

        $_SESSION['tenant'] = $contexto;
        $_SESSION['modo_plataforma'] = true;
        $_SESSION['modo_suporte'] = false;
        $_SESSION['papel_tenant'] = 'master_saas';
        $_SESSION['permissoes_tenant'] = $contexto['permissoes'];
        $_SESSION['modulos_tenant'] = [];

        return $contexto;
    }
}

if (!function_exists('rojexDefinirContextoEquipeInterna')) {
    /**
     * Cria um contexto de plataforma limitado para a equipe interna ROJEX.AI.
     *
     * O contexto não recebe tenant, escritório, módulos de clientes nem a
     * permissão plataforma_total, que permanece exclusiva do MASTER.
     */
    function rojexDefinirContextoEquipeInterna(string $perfil): array
    {
        $perfilCanonico = rojexPerfilEquipeInterna($perfil);

        if ($perfilCanonico === null) {
            throw new RuntimeException('Perfil interno ROJEX.AI inválido.');
        }

        $permissoesPorPerfil = [
            'Suporte ROJEX' => ['plataforma_suporte'],
            'Comercial ROJEX' => ['plataforma_comercial'],
            'Financeiro ROJEX' => ['plataforma_financeiro'],
            'Operador ROJEX' => ['plataforma_operador'],
            'Auditor ROJEX' => ['plataforma_auditoria'],
        ];

        $permissoes = $permissoesPorPerfil[$perfilCanonico] ?? [];

        if ($permissoes === [] || in_array('plataforma_total', $permissoes, true)) {
            throw new RuntimeException('Permissões internas ROJEX.AI inválidas.');
        }

        rojexLimparContextoTenant(true);

        $contexto = [
            'tipo_contexto' => 'plataforma',
            'tenant_id' => null,
            'escritorio_id' => null,
            'escritorio_nome' => null,
            'escritorio_status' => null,
            'licenca_id' => null,
            'licenca_chave' => null,
            'licenca_status' => null,
            'plano_id' => null,
            'plano' => null,
            'assinatura_status' => null,
            'papel' => 'equipe_interna_rojex',
            'perfil_interno' => $perfilCanonico,
            'principal' => false,
            'modo_suporte' => false,
            'permissoes' => $permissoes,
            'modulos' => [],
            'carregado_em' => date('c'),
        ];

        $_SESSION['tenant'] = $contexto;
        $_SESSION['modo_plataforma'] = true;
        $_SESSION['modo_suporte'] = false;
        $_SESSION['papel_tenant'] = 'equipe_interna_rojex';
        $_SESSION['permissoes_tenant'] = $permissoes;
        $_SESSION['modulos_tenant'] = [];

        return $contexto;
    }
}

if (!function_exists('rojexCarregarModulosTenant')) {
    function rojexCarregarModulosTenant(mysqli $conn, int $escritorioId): array
    {
        if (
            $escritorioId <= 0
            || !rojexAuthTabelaExiste($conn, 'escritorios_modulos_saas')
            || !rojexAuthTabelaExiste($conn, 'modulos_saas')
        ) {
            return [];
        }

        try {
            $stmt = $conn->prepare(
                "SELECT m.id, m.slug, m.nome, m.status,
                        em.origem, em.valor_ajuste
                   FROM escritorios_modulos_saas em
                   INNER JOIN modulos_saas m ON m.id = em.modulo_id
                  WHERE em.escritorio_id = ?
                    AND em.ativo = 1
                  ORDER BY m.ordem ASC, m.nome ASC"
            );

            if (!$stmt) {
                return [];
            }

            $stmt->bind_param('i', $escritorioId);
            $stmt->execute();
            $res = $stmt->get_result();
            $modulos = [];

            while ($row = $res->fetch_assoc()) {
                $slug = trim((string)($row['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $modulos[$slug] = [
                    'id' => (int)$row['id'],
                    'slug' => $slug,
                    'nome' => (string)($row['nome'] ?? $slug),
                    'status' => (string)($row['status'] ?? 'ativo'),
                    'origem' => (string)($row['origem'] ?? 'plano'),
                    'valor_ajuste' => (float)($row['valor_ajuste'] ?? 0),
                ];
            }

            $stmt->close();
            return $modulos;
        } catch (Throwable $e) {
            error_log('[ROJEX TENANT][MODULOS] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rojexCarregarContextoTenant')) {
    /**
     * Carrega o contexto SaaS do usuário autenticado.
     *
     * MASTER entra por padrão no modo plataforma com acesso total. A equipe
     * interna ROJEX.AI entra em contexto de plataforma limitado. Um escritório
     * específico só pode ser carregado pelo MASTER.
     */
    function rojexCarregarContextoTenant(
        mysqli $conn,
        int $usuarioId,
        ?string $perfil = null,
        ?int $escritorioForcadoId = null,
        bool $modoSuporte = false
    ): array {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('Usuário inválido para o contexto tenant.');
        }

        $ehMaster = rojexUsuarioEhMasterSaas($conn, $usuarioId, $perfil);
        $perfilInterno = rojexPerfilEquipeInterna($perfil);

        if ($ehMaster && (!$escritorioForcadoId || $escritorioForcadoId <= 0)) {
            return rojexDefinirContextoPlataforma();
        }

        if ($perfilInterno !== null) {
            if ($escritorioForcadoId && $escritorioForcadoId > 0) {
                rojexLimparContextoTenant();
                throw new RuntimeException(
                    'A equipe interna ROJEX.AI não pode assumir o contexto de um escritório.'
                );
            }

            return rojexDefinirContextoEquipeInterna($perfilInterno);
        }

        if (
            !rojexAuthTabelaExiste($conn, 'usuarios_escritorios_saas')
            || !rojexAuthTabelaExiste($conn, 'escritorios_saas')
        ) {
            throw new RuntimeException('Estrutura Multi-Tenant ainda não está disponível no banco.');
        }

        $sql = "SELECT
                    ue.escritorio_id,
                    ue.tenant_id,
                    ue.papel,
                    ue.principal,
                    ue.ativo AS vinculo_ativo,
                    e.nome AS escritorio_nome,
                    e.status AS escritorio_status,
                    e.plano AS escritorio_plano,
                    l.id AS licenca_id,
                    l.chave_licenca,
                    l.plano AS licenca_plano,
                    l.status AS licenca_status,
                    l.limite_usuarios,
                    l.limite_armazenamento_gb,
                    l.ativada_em,
                    l.renovacao_em,
                    a.plano_id,
                    a.status AS assinatura_status,
                    a.periodicidade,
                    a.trial_inicio,
                    a.trial_fim,
                    a.inicio_vigencia,
                    a.proximo_vencimento
                FROM usuarios_escritorios_saas ue
                INNER JOIN escritorios_saas e
                        ON e.id = ue.escritorio_id
                       AND e.tenant_id = ue.tenant_id
                LEFT JOIN licencas_saas l
                       ON l.escritorio_id = e.id
                LEFT JOIN assinaturas_saas a
                       ON a.escritorio_id = e.id
               WHERE ue.usuario_id = ?
                 AND ue.ativo = 1";

        if ($escritorioForcadoId && $escritorioForcadoId > 0) {
            $sql .= " AND ue.escritorio_id = ?";
        }

        $sql .= " ORDER BY ue.principal DESC, ue.id ASC LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar o contexto do tenant.');
        }

        if ($escritorioForcadoId && $escritorioForcadoId > 0) {
            $stmt->bind_param('ii', $usuarioId, $escritorioForcadoId);
        } else {
            $stmt->bind_param('i', $usuarioId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /*
         * O MASTER pode acessar um escritório em modo suporte mesmo sem vínculo
         * permanente na tabela usuarios_escritorios_saas.
         */
        if (!$row && $ehMaster && $escritorioForcadoId && $escritorioForcadoId > 0) {
            $stmt = $conn->prepare(
                "SELECT
                    e.id AS escritorio_id,
                    e.tenant_id,
                    'master_suporte' AS papel,
                    0 AS principal,
                    1 AS vinculo_ativo,
                    e.nome AS escritorio_nome,
                    e.status AS escritorio_status,
                    e.plano AS escritorio_plano,
                    l.id AS licenca_id,
                    l.chave_licenca,
                    l.plano AS licenca_plano,
                    l.status AS licenca_status,
                    l.limite_usuarios,
                    l.limite_armazenamento_gb,
                    l.ativada_em,
                    l.renovacao_em,
                    a.plano_id,
                    a.status AS assinatura_status,
                    a.periodicidade,
                    a.trial_inicio,
                    a.trial_fim,
                    a.inicio_vigencia,
                    a.proximo_vencimento
                 FROM escritorios_saas e
                 LEFT JOIN licencas_saas l ON l.escritorio_id = e.id
                 LEFT JOIN assinaturas_saas a ON a.escritorio_id = e.id
                 WHERE e.id = ?
                 LIMIT 1"
            );

            if ($stmt) {
                $stmt->bind_param('i', $escritorioForcadoId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }

        if (!$row) {
            rojexLimparContextoTenant();
            throw new RuntimeException('Nenhum escritório ativo está vinculado a este usuário.');
        }

        $escritorioId = (int)$row['escritorio_id'];
        $tenantId = trim((string)$row['tenant_id']);

        if ($escritorioId <= 0 || $tenantId === '') {
            rojexLimparContextoTenant();
            throw new RuntimeException('O vínculo do usuário não possui tenant válido.');
        }

        /*
         * Bloqueio no próprio carregamento do contexto impede um novo login.
         * O MASTER continua autorizado a entrar em suporte para administrar e
         * auditar escritórios encerrados.
         */
        if (!$ehMaster && rojexEscritorioEncerrado((string)($row['escritorio_status'] ?? ''))) {
            rojexRegistrarBloqueioEscritorioEncerrado(
                $conn,
                $escritorioId,
                $tenantId,
                'carregamento do contexto após autenticação'
            );
            rojexLimparContextoTenant();
            throw new RuntimeException('Acesso bloqueado: este escritório está encerrado.');
        }

        $modulos = rojexCarregarModulosTenant($conn, $escritorioId);
        $papel = trim((string)($row['papel'] ?? 'usuario')) ?: 'usuario';
        $plano = trim((string)($row['licenca_plano'] ?? $row['escritorio_plano'] ?? ''));

        $permissoes = match (mb_strtolower($papel, 'UTF-8')) {
            'administrador', 'admin' => ['tenant_administrar'],
            'master_suporte' => ['tenant_suporte'],
            default => ['tenant_utilizar'],
        };

        $contexto = [
            'tipo_contexto' => 'tenant',
            'tenant_id' => $tenantId,
            'escritorio_id' => $escritorioId,
            'escritorio_nome' => (string)($row['escritorio_nome'] ?? ''),
            'escritorio_status' => (string)($row['escritorio_status'] ?? ''),
            'licenca_id' => isset($row['licenca_id']) ? (int)$row['licenca_id'] : null,
            'licenca_chave' => $row['chave_licenca'] ?? null,
            'licenca_status' => $row['licenca_status'] ?? null,
            'limite_usuarios' => isset($row['limite_usuarios']) ? (int)$row['limite_usuarios'] : null,
            'limite_armazenamento_gb' => isset($row['limite_armazenamento_gb']) ? (int)$row['limite_armazenamento_gb'] : null,
            'plano_id' => isset($row['plano_id']) ? (int)$row['plano_id'] : null,
            'plano' => $plano !== '' ? $plano : null,
            'assinatura_status' => $row['assinatura_status'] ?? null,
            'periodicidade' => $row['periodicidade'] ?? null,
            'trial_inicio' => $row['trial_inicio'] ?? null,
            'trial_fim' => $row['trial_fim'] ?? null,
            'inicio_vigencia' => $row['inicio_vigencia'] ?? null,
            'proximo_vencimento' => $row['proximo_vencimento'] ?? null,
            'papel' => $papel,
            'principal' => (bool)($row['principal'] ?? false),
            'modo_suporte' => $ehMaster && ($modoSuporte || $papel === 'master_suporte'),
            'permissoes' => $permissoes,
            'modulos' => $modulos,
            'carregado_em' => date('c'),
        ];

        rojexLimparContextoTenant();

        $_SESSION['tenant'] = $contexto;
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['escritorio_id'] = $escritorioId;
        $_SESSION['licenca_id'] = $contexto['licenca_id'];
        $_SESSION['licenca_chave'] = $contexto['licenca_chave'];
        $_SESSION['licenca_status'] = $contexto['licenca_status'];
        $_SESSION['plano_id'] = $contexto['plano_id'];
        $_SESSION['plano'] = $contexto['plano'];
        $_SESSION['papel_tenant'] = $papel;
        $_SESSION['modo_plataforma'] = false;
        $_SESSION['modo_suporte'] = $contexto['modo_suporte'];
        $_SESSION['permissoes_tenant'] = $permissoes;
        $_SESSION['modulos_tenant'] = array_keys($modulos);

        return $contexto;
    }
}

if (!function_exists('rojexContextoTenant')) {
    function rojexContextoTenant(): array
    {
        return isset($_SESSION['tenant']) && is_array($_SESSION['tenant'])
            ? $_SESSION['tenant']
            : [];
    }
}

if (!function_exists('rojexTenantId')) {
    function rojexTenantId(): ?string
    {
        $tenantId = trim((string)($_SESSION['tenant_id'] ?? ''));
        return $tenantId !== '' ? $tenantId : null;
    }
}

if (!function_exists('rojexEscritorioId')) {
    function rojexEscritorioId(): ?int
    {
        $id = (int)($_SESSION['escritorio_id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('rojexModoPlataforma')) {
    function rojexModoPlataforma(): bool
    {
        return !empty($_SESSION['modo_plataforma'])
            && (rojexContextoTenant()['tipo_contexto'] ?? null) === 'plataforma';
    }
}

if (!function_exists('rojexModoSuporte')) {
    function rojexModoSuporte(): bool
    {
        $contexto = rojexContextoTenant();
        $tenantSessao = rojexTenantId();
        $escritorioSessao = rojexEscritorioId();
        $perfil = trim((string)($_SESSION['perfil'] ?? ''));

        return !empty($_SESSION['modo_suporte'])
            && !empty($contexto['modo_suporte'])
            && ($contexto['tipo_contexto'] ?? null) === 'tenant'
            && $perfil === 'Administrador Master'
            && $tenantSessao !== null
            && $escritorioSessao !== null
            && hash_equals(
                trim((string)($contexto['tenant_id'] ?? '')),
                $tenantSessao
            )
            && (int)($contexto['escritorio_id'] ?? 0) === $escritorioSessao;
    }
}

if (!function_exists('rojexContextoTenantValido')) {
    function rojexContextoTenantValido(): bool
    {
        $contexto = rojexContextoTenant();

        if (($contexto['tipo_contexto'] ?? null) === 'plataforma') {
            return rojexModoPlataforma()
                && rojexTenantId() === null
                && rojexEscritorioId() === null;
        }

        $tenantSessao = rojexTenantId();
        $escritorioSessao = rojexEscritorioId();
        $tenantContexto = trim((string)($contexto['tenant_id'] ?? ''));
        $escritorioContexto = (int)($contexto['escritorio_id'] ?? 0);

        return ($contexto['tipo_contexto'] ?? null) === 'tenant'
            && $tenantSessao !== null
            && $escritorioSessao !== null
            && $tenantContexto !== ''
            && hash_equals($tenantContexto, $tenantSessao)
            && $escritorioContexto > 0
            && $escritorioContexto === $escritorioSessao
            && empty($_SESSION['modo_plataforma']);
    }
}

if (!function_exists('rojexExigirContextoTenant')) {
    function rojexExigirContextoTenant(): void
    {
        if (rojexContextoTenantValido() && !rojexModoPlataforma()) {
            return;
        }

        throw new RuntimeException('Nenhum escritório está ativo nesta sessão.');
    }
}

if (!function_exists('rojexTenantTemModulo')) {
    function rojexTenantTemModulo(string $slug): bool
    {
        $slug = trim($slug);

        if ($slug === '') {
            return false;
        }

        if (rojexModoPlataforma()) {
            return false;
        }

        $modulos = $_SESSION['modulos_tenant'] ?? [];
        return is_array($modulos) && in_array($slug, $modulos, true);
    }
}

/**
 * -----------------------------------------------------------------------------
 * AUTORIZAÇÃO POR AÇÃO NO SERVIDOR — RA-10
 * -----------------------------------------------------------------------------
 *
 * Regras desta camada:
 * - toda ação precisa existir no catálogo fechado abaixo;
 * - ação desconhecida é sempre negada;
 * - permissões da interface nunca substituem esta validação no servidor;
 * - ações tenant exigem contexto válido de tenant_id + escritorio_id;
 * - MASTER em modo plataforma supervisiona, habilita e audita globalmente;
 * - administrador do escritório gerencia somente o próprio contexto tenant;
 * - MASTER em suporte possui somente as ações explicitamente de leitura.
 *
 * IMPORTANTE: os módulos que executam as ações devem continuar filtrando suas
 * consultas por rojexTenantId() + rojexEscritorioId(). Esta camada autoriza a
 * operação, mas não substitui o isolamento dos registros no SQL.
 */

if (!function_exists('rojexCatalogoAcoes')) {
    function rojexCatalogoAcoes(): array
    {
        return [
            // Administração global da plataforma — exclusivamente MASTER.
            'plataforma.portal.habilitar' => [
                'contexto' => 'plataforma',
                'permissoes' => ['plataforma_total'],
            ],
            'plataforma.portal.supervisionar' => [
                'contexto' => 'plataforma',
                'permissoes' => ['plataforma_total'],
            ],
            'plataforma.portal.auditar' => [
                'contexto' => 'plataforma',
                'permissoes' => ['plataforma_total'],
            ],

            // Convites do Portal — administração descentralizada por escritório.
            'tenant.portal.convite.listar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar', 'tenant_suporte'],
            ],
            'tenant.portal.convite.criar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],
            'tenant.portal.convite.reenviar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],
            'tenant.portal.convite.revogar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],

            // Contas do Portal — restritas ao administrador do próprio escritório.
            'tenant.portal.conta.listar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar', 'tenant_suporte'],
            ],
            'tenant.portal.conta.ativar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],
            'tenant.portal.conta.desativar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],
            'tenant.portal.permissoes.alterar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar'],
            ],
            'tenant.portal.auditoria.visualizar' => [
                'contexto' => 'tenant',
                'permissoes' => ['tenant_administrar', 'tenant_suporte'],
            ],
        ];
    }
}

if (!function_exists('rojexPermissoesAtuais')) {
    function rojexPermissoesAtuais(): array
    {
        $permissoes = $_SESSION['permissoes_tenant'] ?? [];

        if (!is_array($permissoes)) {
            return [];
        }

        $normalizadas = [];

        foreach ($permissoes as $permissao) {
            if (!is_string($permissao)) {
                continue;
            }

            $permissao = trim($permissao);

            if ($permissao !== '') {
                $normalizadas[$permissao] = true;
            }
        }

        return array_keys($normalizadas);
    }
}

if (!function_exists('rojexPodeExecutarAcao')) {
    function rojexPodeExecutarAcao(string $acao): bool
    {
        if (!usuarioLogado()) {
            return false;
        }

        $acao = trim($acao);

        if ($acao === '') {
            return false;
        }

        $catalogo = rojexCatalogoAcoes();

        // Fail closed: ações ausentes do catálogo jamais são autorizadas.
        if (!isset($catalogo[$acao]) || !is_array($catalogo[$acao])) {
            return false;
        }

        $regra = $catalogo[$acao];
        $contextoExigido = (string)($regra['contexto'] ?? '');
        $permissoesExigidas = $regra['permissoes'] ?? [];

        if ($contextoExigido === 'plataforma') {
            if (!rojexModoPlataforma()) {
                return false;
            }
        } elseif ($contextoExigido === 'tenant') {
            if (!rojexContextoTenantValido() || rojexModoPlataforma()) {
                return false;
            }
        } else {
            return false;
        }

        if (!is_array($permissoesExigidas) || $permissoesExigidas === []) {
            return false;
        }

        $permissoesAtuais = rojexPermissoesAtuais();

        foreach ($permissoesExigidas as $permissao) {
            if (
                is_string($permissao)
                && in_array($permissao, $permissoesAtuais, true)
            ) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('rojexNegarAcao')) {
    function rojexNegarAcao(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        exit('Acesso negado.');
    }
}

if (!function_exists('rojexExigirAcao')) {
    function rojexExigirAcao(string $acao): void
    {
        if (!rojexPodeExecutarAcao($acao)) {
            rojexNegarAcao();
        }
    }
}
