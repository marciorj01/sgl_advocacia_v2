<?php
/**
 * config/portal_auth.php
 * Autenticacao e sessao exclusivas do Portal do Cliente ROJEX.AI.
 *
 * Sprint 4.7.2 - Portal do Cliente Multi-Tenant
 * Compatibilidade: PHP 8+, MySQL 5.7+/8+, MariaDB 10.4+, XAMPP e Hostinger.
 *
 * Este arquivo nao substitui config/auth.php e nao utiliza a sessao interna
 * ROJEXSESSID. As paginas do portal devem carregar primeiro database.php e,
 * em seguida, este arquivo.
 */

declare(strict_types=1);

if (!defined('ROJEX_PORTAL_SESSION_NAME')) {
    define('ROJEX_PORTAL_SESSION_NAME', 'ROJEXPORTALSESSID');
}

if (!defined('ROJEX_PORTAL_IDLE_TIMEOUT')) {
    define('ROJEX_PORTAL_IDLE_TIMEOUT', 1800); // 30 minutos
}

if (!defined('ROJEX_PORTAL_ABSOLUTE_TIMEOUT')) {
    define('ROJEX_PORTAL_ABSOLUTE_TIMEOUT', 28800); // 8 horas
}

if (!defined('ROJEX_PORTAL_REGENERATE_INTERVAL')) {
    define('ROJEX_PORTAL_REGENERATE_INTERVAL', 900); // 15 minutos
}

if (!defined('ROJEX_PORTAL_LOGIN_PATH')) {
    define('ROJEX_PORTAL_LOGIN_PATH', 'login.php');
}

/** Detecta HTTPS sem confiar no cabecalho Host. */
function rojexPortalRequisicaoHttps(): bool
{
    if (
        defined('ROJEX_APP_URL')
        && (string) ROJEX_APP_URL !== ''
        && str_starts_with(strtolower((string) ROJEX_APP_URL), 'https://')
    ) {
        return true;
    }

    return (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Retorna o IP em formato seguro para armazenamento. */
function rojexPortalIpCliente(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/** Retorna o User-Agent limitado ao tamanho da coluna. */
function rojexPortalUserAgent(): string
{
    return mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255, 'UTF-8');
}

/** Normaliza email sem modificar o valor de exibicao da conta. */
function rojexPortalNormalizarEmail(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

/** Garante que somente um destino relativo interno seja utilizado. */
function rojexPortalDestinoSeguro(string $destino, string $padrao = ROJEX_PORTAL_LOGIN_PATH): string
{
    $destino = trim($destino);

    if (
        $destino === ''
        || str_contains($destino, "\r")
        || str_contains($destino, "\n")
        || preg_match('#^(?:https?:)?//#i', $destino)
        || str_starts_with($destino, '\\')
    ) {
        return $padrao;
    }

    return $destino;
}

/** Inicia exclusivamente a sessao do Portal do Cliente. */
function rojexPortalIniciarSessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === ROJEX_PORTAL_SESSION_NAME) {
            return;
        }

        // Preserva a outra sessao no disco antes de alternar para o portal.
        session_write_close();
    }

    $https = rojexPortalRequisicaoHttps();

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.cookie_secure', $https ? '1' : '0');

    session_name(ROJEX_PORTAL_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Nao foi possivel iniciar a sessao do Portal do Cliente.');
    }

    if (!isset($_SESSION['portal_sessao_criada_em'])) {
        $_SESSION['portal_sessao_criada_em'] = time();
    }
}

/** Apaga somente a sessao local do portal e seu cookie. */
function rojexPortalEncerrarSessaoLocal(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== ROJEX_PORTAL_SESSION_NAME) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(ROJEX_PORTAL_SESSION_NAME, '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/** Token CSRF independente do sistema interno. */
function rojexPortalTokenCsrf(): string
{
    rojexPortalIniciarSessao();

    if (
        empty($_SESSION['portal_csrf_token'])
        || !is_string($_SESSION['portal_csrf_token'])
        || strlen($_SESSION['portal_csrf_token']) !== 64
    ) {
        $_SESSION['portal_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['portal_csrf_token'];
}

function rojexPortalValidarCsrf(?string $token): bool
{
    rojexPortalIniciarSessao();
    $salvo = $_SESSION['portal_csrf_token'] ?? '';

    return is_string($token)
        && is_string($salvo)
        && strlen($salvo) === 64
        && hash_equals($salvo, $token);
}

function rojexPortalRotacionarCsrf(): string
{
    rojexPortalIniciarSessao();
    $_SESSION['portal_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['portal_csrf_token'];
}

function rojexPortalContaLogada(): bool
{
    return session_status() === PHP_SESSION_ACTIVE
        && session_name() === ROJEX_PORTAL_SESSION_NAME
        && (int) ($_SESSION['portal_conta_id'] ?? 0) > 0
        && trim((string) ($_SESSION['portal_tenant_id'] ?? '')) !== ''
        && (int) ($_SESSION['portal_escritorio_id'] ?? 0) > 0
        && trim((string) ($_SESSION['portal_cliente_id'] ?? '')) !== '';
}

/** Registra uma tentativa sem revelar ao cliente se a conta existe. */
function rojexPortalRegistrarTentativa(
    mysqli $conn,
    string $resultado,
    string $motivo = '',
    ?array $conta = null,
    string $emailNormalizado = ''
): void {
    try {
        $tenantId = isset($conta['tenant_id']) ? (string) $conta['tenant_id'] : null;
        $escritorioId = isset($conta['escritorio_id']) ? (int) $conta['escritorio_id'] : null;
        $contaId = isset($conta['id']) ? (int) $conta['id'] : null;
        $clienteId = isset($conta['cliente_id']) ? (string) $conta['cliente_id'] : null;
        $emailNormalizado = mb_substr(rojexPortalNormalizarEmail($emailNormalizado), 0, 190, 'UTF-8');
        $resultado = mb_substr(trim($resultado), 0, 30, 'UTF-8');
        $motivo = mb_substr(trim($motivo), 0, 100, 'UTF-8');
        $ip = rojexPortalIpCliente();
        $userAgent = rojexPortalUserAgent();

        $stmt = $conn->prepare(
            "INSERT INTO portal_tentativas_login
                (tenant_id, escritorio_id, conta_id, cliente_id, email_normalizado,
                 ip, user_agent, resultado, motivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt) {
            $stmt->bind_param(
                'siissssss',
                $tenantId,
                $escritorioId,
                $contaId,
                $clienteId,
                $emailNormalizado,
                $ip,
                $userAgent,
                $resultado,
                $motivo
            );
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('[ROJEX PORTAL][TENTATIVA] ' . $e->getMessage());
    }
}

/**
 * Consulta a conta com todas as fronteiras Multi-Tenant obrigatorias.
 * O portal so aceita escritorio ativo, cliente ativo e modulo liberado.
 */
function rojexPortalBuscarContaPorEmail(
    mysqli $conn,
    string $tenantId,
    int $escritorioId,
    string $email
): ?array {
    $tenantId = trim($tenantId);
    $emailNormalizado = rojexPortalNormalizarEmail($email);

    if ($tenantId === '' || $escritorioId <= 0 || !filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT pc.*, c.nome AS cliente_nome, c.status AS cliente_status,
                e.nome AS escritorio_nome, e.status AS escritorio_status,
                pp.ver_processos, pp.ver_documentos, pp.enviar_documentos,
                pp.ver_honorarios, pp.ver_recibos, pp.ver_agenda,
                pp.receber_notificacoes
           FROM portal_clientes_contas pc
           INNER JOIN clientes c
                   ON c.id = pc.cliente_id
                  AND c.tenant_id = pc.tenant_id
                  AND c.escritorio_id = pc.escritorio_id
                  AND c.deletado = 0
           INNER JOIN escritorios_saas e
                   ON e.id = pc.escritorio_id
                  AND e.tenant_id = pc.tenant_id
           INNER JOIN escritorios_modulos_saas em
                   ON em.escritorio_id = e.id
                  AND em.ativo = 1
           INNER JOIN modulos_saas m
                   ON m.id = em.modulo_id
                  AND m.codigo = 'portal_cliente'
                  AND m.ativo = 1
                  AND m.status_lancamento = 'producao'
           LEFT JOIN portal_clientes_permissoes pp
                  ON pp.conta_id = pc.id
                 AND pp.tenant_id = pc.tenant_id
                 AND pp.escritorio_id = pc.escritorio_id
                 AND pp.cliente_id = pc.cliente_id
          WHERE pc.tenant_id = ?
            AND pc.escritorio_id = ?
            AND pc.email_normalizado = ?
            AND e.status = 'ativo'
            AND c.status = 'Ativo'
          LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Nao foi possivel consultar a conta do portal.');
    }

    $stmt->bind_param('sis', $tenantId, $escritorioId, $emailNormalizado);
    $stmt->execute();
    $conta = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $conta;
}

/** Cria o vinculo entre a sessao PHP e a sessao persistida no banco. */
function rojexPortalRegistrarSessaoAutenticada(mysqli $conn, array $conta): void
{
    rojexPortalIniciarSessao();

    $contaId = (int) ($conta['id'] ?? 0);
    $tenantId = trim((string) ($conta['tenant_id'] ?? ''));
    $escritorioId = (int) ($conta['escritorio_id'] ?? 0);
    $clienteId = trim((string) ($conta['cliente_id'] ?? ''));

    if ($contaId <= 0 || $tenantId === '' || $escritorioId <= 0 || $clienteId === '') {
        throw new InvalidArgumentException('Contexto invalido para iniciar a sessao do portal.');
    }

    session_regenerate_id(true);
    $agora = time();
    $expiraEm = date('Y-m-d H:i:s', $agora + ROJEX_PORTAL_ABSOLUTE_TIMEOUT);
    $sessaoHash = hash('sha256', session_id());
    $ip = rojexPortalIpCliente();
    $userAgent = rojexPortalUserAgent();

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO portal_clientes_sessoes
                (conta_id, tenant_id, escritorio_id, cliente_id, sessao_hash,
                 ip, user_agent, iniciada_em, ultima_atividade_em, expira_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
        );

        if (!$stmt) {
            throw new RuntimeException('Nao foi possivel preparar a sessao persistida.');
        }

        $stmt->bind_param(
            'isisssss',
            $contaId,
            $tenantId,
            $escritorioId,
            $clienteId,
            $sessaoHash,
            $ip,
            $userAgent,
            $expiraEm
        );
        $stmt->execute();
        $sessaoBancoId = (int) $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE portal_clientes_contas
                SET ultimo_login_em = NOW(), ultimo_login_ip = ?,
                    falhas_consecutivas = 0, bloqueado_ate = NULL
              WHERE id = ? AND tenant_id = ? AND escritorio_id = ? AND cliente_id = ?"
        );

        if (!$stmt) {
            throw new RuntimeException('Nao foi possivel atualizar a conta do portal.');
        }

        $stmt->bind_param('sisis', $ip, $contaId, $tenantId, $escritorioId, $clienteId);
        $stmt->execute();

        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new RuntimeException('A conta nao pertence ao contexto informado.');
        }

        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        rojexPortalEncerrarSessaoLocal();
        throw $e;
    }

    $_SESSION['portal_conta_id'] = $contaId;
    $_SESSION['portal_tenant_id'] = $tenantId;
    $_SESSION['portal_escritorio_id'] = $escritorioId;
    $_SESSION['portal_cliente_id'] = $clienteId;
    $_SESSION['portal_cliente_nome'] = (string) ($conta['cliente_nome'] ?? '');
    $_SESSION['portal_escritorio_nome'] = (string) ($conta['escritorio_nome'] ?? '');
    $_SESSION['portal_sessao_banco_id'] = $sessaoBancoId;
    $_SESSION['portal_sessao_iniciada_em'] = $agora;
    $_SESSION['portal_ultima_atividade'] = $agora;
    $_SESSION['portal_sessao_regenerada_em'] = $agora;
    $_SESSION['portal_permissoes'] = [
        'ver_processos' => (bool) ($conta['ver_processos'] ?? false),
        'ver_documentos' => (bool) ($conta['ver_documentos'] ?? false),
        'enviar_documentos' => (bool) ($conta['enviar_documentos'] ?? false),
        'ver_honorarios' => (bool) ($conta['ver_honorarios'] ?? false),
        'ver_recibos' => (bool) ($conta['ver_recibos'] ?? false),
        'ver_agenda' => (bool) ($conta['ver_agenda'] ?? false),
        'receber_notificacoes' => (bool) ($conta['receber_notificacoes'] ?? false),
    ];

    rojexPortalRotacionarCsrf();
}

/**
 * Revalida a sessao, a conta e todo o contexto no banco a cada requisicao.
 * Retorna false depois de encerrar qualquer sessao inconsistente ou expirada.
 */
function rojexPortalValidarSessao(mysqli $conn): bool
{
    rojexPortalIniciarSessao();

    if (!rojexPortalContaLogada()) {
        return false;
    }

    $agora = time();
    $iniciadaEm = (int) ($_SESSION['portal_sessao_iniciada_em'] ?? 0);
    $ultimaAtividade = (int) ($_SESSION['portal_ultima_atividade'] ?? 0);
    $regeneradaEm = (int) ($_SESSION['portal_sessao_regenerada_em'] ?? 0);

    $motivo = null;
    if ($iniciadaEm <= 0 || ($agora - $iniciadaEm) > ROJEX_PORTAL_ABSOLUTE_TIMEOUT) {
        $motivo = 'EXPIRADA_DURACAO';
    } elseif ($ultimaAtividade <= 0 || ($agora - $ultimaAtividade) > ROJEX_PORTAL_IDLE_TIMEOUT) {
        $motivo = 'EXPIRADA_INATIVIDADE';
    }

    if ($motivo !== null) {
        rojexPortalEncerrarSessao($conn, $motivo);
        return false;
    }

    $contaId = (int) $_SESSION['portal_conta_id'];
    $tenantId = (string) $_SESSION['portal_tenant_id'];
    $escritorioId = (int) $_SESSION['portal_escritorio_id'];
    $clienteId = (string) $_SESSION['portal_cliente_id'];
    $sessaoBancoId = (int) ($_SESSION['portal_sessao_banco_id'] ?? 0);
    $sessaoHash = hash('sha256', session_id());

    $stmt = $conn->prepare(
        "SELECT ps.id,
                pp.ver_processos, pp.ver_documentos, pp.enviar_documentos,
                pp.ver_honorarios, pp.ver_recibos, pp.ver_agenda,
                pp.receber_notificacoes
           FROM portal_clientes_sessoes ps
           INNER JOIN portal_clientes_contas pc
                   ON pc.id = ps.conta_id
                  AND pc.tenant_id = ps.tenant_id
                  AND pc.escritorio_id = ps.escritorio_id
                  AND pc.cliente_id = ps.cliente_id
                  AND pc.status = 'ATIVA'
                  AND (pc.bloqueado_ate IS NULL OR pc.bloqueado_ate <= NOW())
           INNER JOIN clientes c
                   ON c.id = pc.cliente_id
                  AND c.tenant_id = pc.tenant_id
                  AND c.escritorio_id = pc.escritorio_id
                  AND c.deletado = 0
                  AND c.status = 'Ativo'
           INNER JOIN escritorios_saas e
                   ON e.id = pc.escritorio_id
                  AND e.tenant_id = pc.tenant_id
                  AND e.status = 'ativo'
           INNER JOIN escritorios_modulos_saas em
                   ON em.escritorio_id = e.id
                  AND em.ativo = 1
           INNER JOIN modulos_saas m
                   ON m.id = em.modulo_id
                  AND m.codigo = 'portal_cliente'
                  AND m.ativo = 1
                  AND m.status_lancamento = 'producao'
           LEFT JOIN portal_clientes_permissoes pp
                  ON pp.conta_id = pc.id
                 AND pp.tenant_id = pc.tenant_id
                 AND pp.escritorio_id = pc.escritorio_id
                 AND pp.cliente_id = pc.cliente_id
          WHERE ps.id = ?
            AND ps.conta_id = ?
            AND ps.tenant_id = ?
            AND ps.escritorio_id = ?
            AND ps.cliente_id = ?
            AND ps.sessao_hash = ?
            AND ps.encerrada_em IS NULL
            AND ps.expira_em > NOW()
          LIMIT 1"
    );

    if (!$stmt) {
        rojexPortalEncerrarSessaoLocal();
        return false;
    }

    $stmt->bind_param(
        'iisiss',
        $sessaoBancoId,
        $contaId,
        $tenantId,
        $escritorioId,
        $clienteId,
        $sessaoHash
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        rojexPortalEncerrarSessaoLocal();
        return false;
    }

    $novoHash = $sessaoHash;
    if (($agora - $regeneradaEm) >= ROJEX_PORTAL_REGENERATE_INTERVAL) {
        session_regenerate_id(true);
        $novoHash = hash('sha256', session_id());
        $_SESSION['portal_sessao_regenerada_em'] = $agora;
    }

    $stmt = $conn->prepare(
        "UPDATE portal_clientes_sessoes
            SET sessao_hash = ?, ultima_atividade_em = NOW()
          WHERE id = ? AND conta_id = ? AND tenant_id = ?
            AND escritorio_id = ? AND cliente_id = ?
            AND encerrada_em IS NULL"
    );

    if (!$stmt) {
        rojexPortalEncerrarSessaoLocal();
        return false;
    }

    $stmt->bind_param('siisis', $novoHash, $sessaoBancoId, $contaId, $tenantId, $escritorioId, $clienteId);
    $stmt->execute();
    $validaAtualizacao = $stmt->affected_rows >= 0;
    $stmt->close();

    if (!$validaAtualizacao) {
        rojexPortalEncerrarSessaoLocal();
        return false;
    }

    $_SESSION['portal_ultima_atividade'] = $agora;
    $_SESSION['portal_permissoes'] = [
        'ver_processos' => (bool) ($row['ver_processos'] ?? false),
        'ver_documentos' => (bool) ($row['ver_documentos'] ?? false),
        'enviar_documentos' => (bool) ($row['enviar_documentos'] ?? false),
        'ver_honorarios' => (bool) ($row['ver_honorarios'] ?? false),
        'ver_recibos' => (bool) ($row['ver_recibos'] ?? false),
        'ver_agenda' => (bool) ($row['ver_agenda'] ?? false),
        'receber_notificacoes' => (bool) ($row['receber_notificacoes'] ?? false),
    ];

    return true;
}

/** Encerra no banco e localmente somente a sessao atual do portal. */
function rojexPortalEncerrarSessao(mysqli $conn, string $motivo = 'LOGOUT'): void
{
    rojexPortalIniciarSessao();

    $sessaoBancoId = (int) ($_SESSION['portal_sessao_banco_id'] ?? 0);
    $contaId = (int) ($_SESSION['portal_conta_id'] ?? 0);
    $tenantId = trim((string) ($_SESSION['portal_tenant_id'] ?? ''));
    $escritorioId = (int) ($_SESSION['portal_escritorio_id'] ?? 0);
    $clienteId = trim((string) ($_SESSION['portal_cliente_id'] ?? ''));
    $motivo = mb_substr(trim($motivo), 0, 80, 'UTF-8');

    if ($sessaoBancoId > 0 && $contaId > 0 && $tenantId !== '' && $escritorioId > 0 && $clienteId !== '') {
        try {
            $stmt = $conn->prepare(
                "UPDATE portal_clientes_sessoes
                    SET encerrada_em = NOW(), motivo_encerramento = ?
                  WHERE id = ? AND conta_id = ? AND tenant_id = ?
                    AND escritorio_id = ? AND cliente_id = ?
                    AND encerrada_em IS NULL"
            );

            if ($stmt) {
                $stmt->bind_param('siisis', $motivo, $sessaoBancoId, $contaId, $tenantId, $escritorioId, $clienteId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[ROJEX PORTAL][LOGOUT] ' . $e->getMessage());
        }
    }

    rojexPortalEncerrarSessaoLocal();
}

function rojexPortalExigirLogin(mysqli $conn, string $redirect = ROJEX_PORTAL_LOGIN_PATH): void
{
    if (rojexPortalValidarSessao($conn)) {
        return;
    }

    header('Location: ' . rojexPortalDestinoSeguro($redirect), true, 302);
    exit();
}

function rojexPortalTenantId(): ?string
{
    $tenantId = trim((string) ($_SESSION['portal_tenant_id'] ?? ''));
    return $tenantId !== '' ? $tenantId : null;
}

function rojexPortalEscritorioId(): ?int
{
    $id = (int) ($_SESSION['portal_escritorio_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function rojexPortalClienteId(): ?string
{
    $id = trim((string) ($_SESSION['portal_cliente_id'] ?? ''));
    return $id !== '' ? $id : null;
}

function rojexPortalContaId(): ?int
{
    $id = (int) ($_SESSION['portal_conta_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function rojexPortalTemPermissao(string $permissao): bool
{
    $permitidas = [
        'ver_processos',
        'ver_documentos',
        'enviar_documentos',
        'ver_honorarios',
        'ver_recibos',
        'ver_agenda',
        'receber_notificacoes',
    ];

    if (!in_array($permissao, $permitidas, true)) {
        return false;
    }

    $permissoes = $_SESSION['portal_permissoes'] ?? [];
    return is_array($permissoes) && !empty($permissoes[$permissao]);
}
