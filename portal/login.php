<?php
/**
 * portal/login.php
 * Login exclusivo do Portal do Cliente ROJEX.AI.
 *
 * Sprint 4.7.3 - Portal do Cliente Multi-Tenant
 * Compatibilidade: PHP 8+, MySQL 5.7+/8+, MariaDB 10.4+, XAMPP e Hostinger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/portal_auth.php';

rojexPortalIniciarSessao();

/** Escapa qualquer valor exibido no HTML. */
function rojexPortalLoginH(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Aceita somente slugs simples de escritorio. */
function rojexPortalLoginNormalizarSlug(string $slug): string
{
    $slug = mb_strtolower(trim($slug), 'UTF-8');
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) ? $slug : '';
}

/**
 * No XAMPP, usa ?escritorio=slug.
 * Em producao, usa o primeiro segmento de slug.dominio.com.br.
 */
function rojexPortalLoginResolverSlug(): string
{
    $informado = (string) ($_POST['escritorio'] ?? $_GET['escritorio'] ?? '');
    $slug = rojexPortalLoginNormalizarSlug($informado);

    if ($slug !== '') {
        return $slug;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $host = mb_strtolower($host, 'UTF-8');

    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return '';
    }

    $partes = explode('.', $host);
    if (count($partes) < 3) {
        return '';
    }

    $candidato = rojexPortalLoginNormalizarSlug((string) $partes[0]);
    $reservados = ['www', 'app', 'portal', 'admin', 'api'];

    return in_array($candidato, $reservados, true) ? '' : $candidato;
}

/** Carrega somente escritorios aptos a utilizar o Portal do Cliente. */
function rojexPortalLoginBuscarEscritorio(mysqli $conn, string $slug): ?array
{
    if ($slug === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT e.id, e.tenant_id, e.nome, e.subdominio, e.status
           FROM escritorios_saas e
           INNER JOIN escritorios_modulos_saas em
                   ON em.escritorio_id = e.id
                  AND em.ativo = 1
           INNER JOIN modulos_saas m
                   ON m.id = em.modulo_id
                  AND m.codigo = 'portal_cliente'
                  AND m.ativo = 1
                  AND m.status_lancamento = 'producao'
          WHERE e.subdominio = ?
            AND e.status = 'ativo'
          LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Nao foi possivel identificar o escritorio.');
    }

    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $escritorio = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $escritorio;
}

/** Carrega apenas as configuracoes visuais do contexto confirmado. */
function rojexPortalLoginCarregarMarca(mysqli $conn, array $escritorio): array
{
    $escritorioId = (int) $escritorio['id'];
    $tenantId = (string) $escritorio['tenant_id'];
    $configuracoes = [];

    $stmt = $conn->prepare(
        "SELECT chave, valor
           FROM escritorios_configuracoes_saas
          WHERE escritorio_id = ?
            AND tenant_id = ?
            AND chave IN (
                'nome_escritorio', 'logo_arquivo', 'cor_primaria',
                'cor_secundaria', 'cor_accent', 'telefone', 'email'
            )"
    );

    if ($stmt) {
        $stmt->bind_param('is', $escritorioId, $tenantId);
        $stmt->execute();
        $resultado = $stmt->get_result();

        while ($row = $resultado->fetch_assoc()) {
            $configuracoes[(string) $row['chave']] = trim((string) ($row['valor'] ?? ''));
        }

        $stmt->close();
    }

    $corValida = static function (string $cor, string $padrao): string {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) ? $cor : $padrao;
    };

    $logo = (string) ($configuracoes['logo_arquivo'] ?? '');
    if (
        $logo !== ''
        && (
            str_contains($logo, '..')
            || str_starts_with($logo, '/')
            || str_starts_with($logo, '\\')
            || !preg_match('#^[A-Za-z0-9_./-]+$#', $logo)
            || !preg_match('/\.(?:png|jpe?g|gif|webp|svg)$/i', $logo)
        )
    ) {
        $logo = '';
    }

    return [
        'nome' => (string) ($configuracoes['nome_escritorio'] ?: $escritorio['nome']),
        'logo' => $logo,
        'cor_primaria' => $corValida((string) ($configuracoes['cor_primaria'] ?? ''), '#163a5f'),
        'cor_secundaria' => $corValida((string) ($configuracoes['cor_secundaria'] ?? ''), '#2c6fad'),
        'cor_accent' => $corValida((string) ($configuracoes['cor_accent'] ?? ''), '#f0a500'),
        'telefone' => (string) ($configuracoes['telefone'] ?? ''),
        'email' => (string) ($configuracoes['email'] ?? ''),
    ];
}

/** Rate limit persistente por email e IP dentro do escritorio. */
function rojexPortalLoginExcedeuLimite(
    mysqli $conn,
    string $tenantId,
    int $escritorioId,
    string $emailNormalizado,
    string $ip
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
           FROM portal_tentativas_login
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND criado_em >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            AND resultado <> 'SUCESSO'
            AND (email_normalizado = ? OR ip = ?)"
    );

    if (!$stmt) {
        return true;
    }

    $stmt->bind_param('siss', $tenantId, $escritorioId, $emailNormalizado, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) >= 10;
}

/** Incrementa falhas e bloqueia a conta por 15 minutos na quinta falha. */
function rojexPortalLoginRegistrarFalhaConta(mysqli $conn, array $conta): void
{
    $contaId = (int) $conta['id'];
    $tenantId = (string) $conta['tenant_id'];
    $escritorioId = (int) $conta['escritorio_id'];
    $clienteId = (string) $conta['cliente_id'];

    $stmt = $conn->prepare(
        "UPDATE portal_clientes_contas
            SET falhas_consecutivas = LEAST(falhas_consecutivas + 1, 65535),
                bloqueado_ate = CASE
                    WHEN falhas_consecutivas + 1 >= 5
                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                    ELSE bloqueado_ate
                END
          WHERE id = ?
            AND tenant_id = ?
            AND escritorio_id = ?
            AND cliente_id = ?"
    );

    if ($stmt) {
        $stmt->bind_param('isis', $contaId, $tenantId, $escritorioId, $clienteId);
        $stmt->execute();
        $stmt->close();
    }
}

function rojexPortalLoginAtrasoSeguro(int $nivel = 1): void
{
    usleep(min(1200000, max(150000, $nivel * 150000)));
}

$slug = rojexPortalLoginResolverSlug();
$mensagemErro = '';
$mensagemAviso = '';
$escritorio = null;
$marca = [
    'nome' => 'Portal do Cliente',
    'logo' => '',
    'cor_primaria' => '#163a5f',
    'cor_secundaria' => '#2c6fad',
    'cor_accent' => '#f0a500',
    'telefone' => '',
    'email' => '',
];
$conn = null;

try {
    $conn = conectar();

    if (rojexPortalContaLogada() && rojexPortalValidarSessao($conn)) {
        $conn->close();
        header('Location: index.php', true, 302);
        exit();
    }

    if ($slug !== '') {
        $escritorio = rojexPortalLoginBuscarEscritorio($conn, $slug);
    }

    if ($escritorio) {
        $marca = rojexPortalLoginCarregarMarca($conn, $escritorio);
    } elseif ($slug === '') {
        $mensagemAviso = 'Informe o endereco de acesso fornecido pelo seu escritorio.';
    } else {
        $mensagemAviso = 'Portal indisponivel para este endereco. Confirme o link com seu escritorio.';
    }

    $contextoTentativa = $escritorio ? [
        'tenant_id' => (string) $escritorio['tenant_id'],
        'escritorio_id' => (int) $escritorio['id'],
    ] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190, 'UTF-8');
        $emailNormalizado = rojexPortalNormalizarEmail($email);
        $senha = (string) ($_POST['senha'] ?? '');
        $csrf = (string) ($_POST['csrf_token'] ?? '');

        if (!$escritorio) {
            rojexPortalLoginAtrasoSeguro();
            $mensagemErro = 'Nao foi possivel concluir o acesso. Verifique o link informado.';
        } elseif (!rojexPortalValidarCsrf($csrf)) {
            rojexPortalRegistrarTentativa(
                $conn,
                'NEGADO',
                'CSRF_INVALIDO',
                $contextoTentativa,
                $emailNormalizado
            );
            rojexPortalLoginAtrasoSeguro();
            $mensagemErro = 'Sessao expirada. Atualize a pagina e tente novamente.';
        } elseif (
            !filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL)
            || $senha === ''
            || strlen($senha) > 1024
        ) {
            rojexPortalRegistrarTentativa(
                $conn,
                'NEGADO',
                'CREDENCIAL_INVALIDA',
                $contextoTentativa,
                $emailNormalizado
            );
            rojexPortalLoginAtrasoSeguro();
            $mensagemErro = 'E-mail ou senha invalidos.';
        } elseif (rojexPortalLoginExcedeuLimite(
            $conn,
            (string) $escritorio['tenant_id'],
            (int) $escritorio['id'],
            $emailNormalizado,
            rojexPortalIpCliente()
        )) {
            rojexPortalRegistrarTentativa(
                $conn,
                'BLOQUEADO',
                'LIMITE_TEMPORARIO',
                $contextoTentativa,
                $emailNormalizado
            );
            rojexPortalLoginAtrasoSeguro(5);
            $mensagemErro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
        } else {
            $conta = rojexPortalBuscarContaPorEmail(
                $conn,
                (string) $escritorio['tenant_id'],
                (int) $escritorio['id'],
                $emailNormalizado
            );

            $senhaHash = is_array($conta) ? (string) ($conta['senha_hash'] ?? '') : '';
            $senhaCorreta = false;

            if ($senhaHash !== '' && password_get_info($senhaHash)['algo'] !== null) {
                $senhaCorreta = password_verify($senha, $senhaHash);
            } else {
                // Equaliza o custo para conta inexistente, sem senha ou convite pendente.
                password_verify(
                    $senha,
                    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
                );
            }

            $statusAtivo = is_array($conta)
                && hash_equals('ATIVA', mb_strtoupper(trim((string) ($conta['status'] ?? '')), 'UTF-8'));
            $bloqueadoAte = is_array($conta) ? strtotime((string) ($conta['bloqueado_ate'] ?? '')) : false;
            $contaBloqueada = $bloqueadoAte !== false && $bloqueadoAte > time();

            if ($conta && $statusAtivo && !$contaBloqueada && $senhaCorreta) {
                rojexPortalRegistrarSessaoAutenticada($conn, $conta);
                rojexPortalRegistrarTentativa(
                    $conn,
                    'SUCESSO',
                    'LOGIN_REALIZADO',
                    $conta,
                    $emailNormalizado
                );

                if (password_needs_rehash($senhaHash, PASSWORD_DEFAULT)) {
                    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                    if (is_string($novoHash) && $novoHash !== '') {
                        $contaId = (int) $conta['id'];
                        $tenantId = (string) $conta['tenant_id'];
                        $escritorioId = (int) $conta['escritorio_id'];
                        $clienteId = (string) $conta['cliente_id'];
                        $stmt = $conn->prepare(
                            "UPDATE portal_clientes_contas
                                SET senha_hash = ?, senha_definida_em = NOW()
                              WHERE id = ? AND tenant_id = ?
                                AND escritorio_id = ? AND cliente_id = ?"
                        );
                        if ($stmt) {
                            $stmt->bind_param('sisis', $novoHash, $contaId, $tenantId, $escritorioId, $clienteId);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }

                $conn->close();
                header('Location: index.php', true, 302);
                exit();
            }

            if ($conta && !$contaBloqueada) {
                rojexPortalLoginRegistrarFalhaConta($conn, $conta);
            }

            rojexPortalRegistrarTentativa(
                $conn,
                $contaBloqueada ? 'BLOQUEADO' : 'NEGADO',
                $contaBloqueada ? 'CONTA_BLOQUEADA' : 'CREDENCIAL_INVALIDA',
                $conta,
                $emailNormalizado
            );
            rojexPortalLoginAtrasoSeguro((int) (($conta['falhas_consecutivas'] ?? 0) + 1));
            $mensagemErro = 'E-mail ou senha invalidos.';
        }
    }
} catch (Throwable $e) {
    error_log('[ROJEX PORTAL][LOGIN] ' . $e->getMessage());
    $mensagemErro = 'Nao foi possivel concluir o acesso. Tente novamente em instantes.';
} finally {
    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $e) {
            error_log('[ROJEX PORTAL][CONEXAO] ' . $e->getMessage());
        }
    }
}

$csrfToken = rojexPortalTokenCsrf();
$acaoFormulario = 'login.php' . ($slug !== '' ? '?escritorio=' . rawurlencode($slug) : '');
$logoUrl = $marca['logo'] !== '' ? '../' . $marca['logo'] : '';
$iniciais = mb_strtoupper(mb_substr(trim((string) $marca['nome']), 0, 2, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Portal do Cliente - <?= rojexPortalLoginH((string) $marca['nome']) ?></title>
    <style>
        :root {
            --office-primary: <?= rojexPortalLoginH((string) $marca['cor_primaria']) ?>;
            --office-secondary: <?= rojexPortalLoginH((string) $marca['cor_secundaria']) ?>;
            --office-accent: <?= rojexPortalLoginH((string) $marca['cor_accent']) ?>;
            --ink: #17212b;
            --muted: #667085;
            --line: #d9e0e7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 12%, color-mix(in srgb, var(--office-secondary) 30%, transparent), transparent 34%),
                linear-gradient(145deg, #07111b 0%, var(--office-primary) 55%, #08121c 100%);
        }
        .shell {
            width: 100%;
            max-width: 1020px;
            min-height: 590px;
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 28px;
            background: #fff;
            box-shadow: 0 34px 90px rgba(0,0,0,.38);
        }
        .brand {
            position: relative;
            overflow: hidden;
            padding: 54px 48px;
            color: #fff;
            background: linear-gradient(155deg, var(--office-primary), #07111b);
        }
        .brand::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            top: -145px;
            right: -135px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--office-accent) 18%, transparent);
        }
        .logo-box {
            position: relative;
            z-index: 1;
            width: 220px;
            min-height: 116px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 38px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 20px;
            background: rgba(255,255,255,.10);
        }
        .logo-box img { max-width: 100%; max-height: 116px; object-fit: contain; }
        .monogram { font-size: 38px; font-weight: 900; color: var(--office-accent); }
        .brand h1 { position: relative; z-index: 1; margin: 0 0 14px; font-size: 36px; line-height: 1.12; }
        .brand p { position: relative; z-index: 1; max-width: 430px; margin: 0; line-height: 1.7; color: rgba(255,255,255,.78); }
        .secure {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-top: 34px;
            padding: 9px 13px;
            border-radius: 999px;
            color: #fff;
            background: rgba(255,255,255,.10);
            font-size: 13px;
            font-weight: 700;
        }
        .form-panel { padding: 52px 46px; display: flex; flex-direction: column; justify-content: center; }
        .eyebrow { margin: 0 0 8px; color: var(--office-secondary); font-size: 13px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
        h2 { margin: 0 0 10px; font-size: 30px; }
        .subtitle { margin: 0 0 28px; color: var(--muted); line-height: 1.5; }
        .message { margin-bottom: 18px; padding: 13px 14px; border-radius: 12px; line-height: 1.45; font-size: 14px; }
        .error { border: 1px solid #f3b7bd; color: #842029; background: #fff0f1; }
        .notice { border: 1px solid #f2d58b; color: #6b4d00; background: #fff8e3; }
        .field { margin-bottom: 17px; }
        label { display: block; margin-bottom: 7px; font-weight: 750; color: #344054; }
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            outline: none;
            font: inherit;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus { border-color: var(--office-secondary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--office-secondary) 15%, transparent); }
        button {
            width: 100%;
            margin-top: 5px;
            padding: 14px;
            border: 0;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(135deg, var(--office-secondary), var(--office-primary));
            font: inherit;
            font-weight: 850;
            cursor: pointer;
            box-shadow: 0 12px 26px color-mix(in srgb, var(--office-primary) 28%, transparent);
        }
        button:disabled { cursor: not-allowed; opacity: .55; }
        .help { margin-top: 22px; text-align: center; color: var(--muted); font-size: 13px; line-height: 1.5; }
        .powered { margin-top: 24px; text-align: center; color: #8a94a3; font-size: 11px; letter-spacing: .04em; }
        @media (max-width: 820px) {
            body { padding: 14px; }
            .shell { grid-template-columns: 1fr; min-height: auto; }
            .brand { padding: 30px 26px; }
            .brand h1 { font-size: 27px; }
            .brand p, .secure { display: none; }
            .logo-box { width: 170px; min-height: 82px; margin-bottom: 20px; }
            .logo-box img { max-height: 82px; }
            .form-panel { padding: 34px 25px 38px; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="brand" aria-label="Identidade do escritorio">
            <div class="logo-box">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= rojexPortalLoginH($logoUrl) ?>" alt="Logomarca de <?= rojexPortalLoginH((string) $marca['nome']) ?>">
                <?php else: ?>
                    <span class="monogram" aria-hidden="true"><?= rojexPortalLoginH($iniciais) ?></span>
                <?php endif; ?>
            </div>
            <h1><?= rojexPortalLoginH((string) $marca['nome']) ?></h1>
            <p>Acompanhe com seguranca as informacoes que seu escritorio disponibilizou para voce.</p>
            <div class="secure"><span aria-hidden="true">&#128274;</span> Ambiente seguro e exclusivo</div>
        </section>

        <section class="form-panel">
            <p class="eyebrow">Portal do Cliente</p>
            <h2>Acesse sua conta</h2>
            <p class="subtitle">Entre com o e-mail e a senha cadastrados pelo seu escritorio.</p>

            <?php if ($mensagemErro !== ''): ?>
                <div class="message error" role="alert"><?= rojexPortalLoginH($mensagemErro) ?></div>
            <?php elseif ($mensagemAviso !== ''): ?>
                <div class="message notice" role="status"><?= rojexPortalLoginH($mensagemAviso) ?></div>
            <?php endif; ?>

            <form action="<?= rojexPortalLoginH($acaoFormulario) ?>" method="post" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= rojexPortalLoginH($csrfToken) ?>">
                <input type="hidden" name="escritorio" value="<?= rojexPortalLoginH($slug) ?>">

                <div class="field">
                    <label for="email">E-mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="190"
                        autocomplete="username"
                        inputmode="email"
                        required
                        autofocus
                        <?= $escritorio ? '' : 'disabled' ?>
                    >
                </div>

                <div class="field">
                    <label for="senha">Senha</label>
                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        maxlength="1024"
                        autocomplete="current-password"
                        required
                        <?= $escritorio ? '' : 'disabled' ?>
                    >
                </div>

                <button type="submit" <?= $escritorio ? '' : 'disabled' ?>>Entrar no portal</button>
            </form>

            <div class="help">Problemas para acessar? Entre em contato diretamente com seu escritorio.</div>
            <div class="powered">Tecnologia ROJEX.AI &middot; Portal juridico Multi-Tenant</div>
        </section>
    </main>
</body>
</html>
