<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/integracoes.php';
require_once __DIR__ . '/../core/Empresa.php';

iniciarSessaoSegura();

$empresa = Empresa::criar();
date_default_timezone_set($empresa->timezone());

if (usuarioLogado()) {
    header('Location: ../index.php');
    exit();
}

$mensagem_erro = '';

if (!function_exists('rojexLoginColunaExiste')) {
    function rojexLoginColunaExiste(mysqli $conn, string $tabela, string $coluna): bool
    {
        if (
            !preg_match('/^[A-Za-z0-9_]+$/', $tabela)
            || !preg_match('/^[A-Za-z0-9_]+$/', $coluna)
        ) {
            return false;
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $tabela, $coluna);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['total'] ?? 0) > 0;
    }
}

if (!function_exists('rojexNormalizarPerfil')) {
    function rojexNormalizarPerfil(string $perfil): string
    {
        $perfil = trim($perfil);
        $normalizado = mb_strtolower($perfil, 'UTF-8');

        return match ($normalizado) {
            'administrador master', 'master' => 'Administrador Master',
            'administrador', 'admin' => 'Administrador',
            'advogado' => 'Advogado',
            'financeiro' => 'Financeiro',
            'secretária', 'secretaria' => 'Secretária',
            'estagiário', 'estagiario' => 'Estagiário',
            'usuário', 'usuario' => 'Usuário',
            default => $perfil !== '' ? $perfil : 'Usuário',
        };
    }
}

if (!function_exists('rojexValidarSenhaCompatível')) {
    /**
     * @return array{ok:bool,legado:bool,tipo:string}
     */
    function rojexValidarSenhaCompatível(string $senhaInformada, string $senhaArmazenada): array
    {
        if ($senhaArmazenada === '') {
            return ['ok' => false, 'legado' => false, 'tipo' => 'ausente'];
        }

        $info = password_get_info($senhaArmazenada);

        if (($info['algo'] ?? null) !== null && (int)($info['algo'] ?? 0) !== 0) {
            return [
                'ok' => password_verify($senhaInformada, $senhaArmazenada),
                'legado' => false,
                'tipo' => 'password_hash',
            ];
        }

        if (
            preg_match('/^[a-f0-9]{32}$/i', $senhaArmazenada)
            && hash_equals(mb_strtolower($senhaArmazenada, 'UTF-8'), md5($senhaInformada))
        ) {
            return ['ok' => true, 'legado' => true, 'tipo' => 'md5'];
        }

        if (hash_equals($senhaArmazenada, $senhaInformada)) {
            return ['ok' => true, 'legado' => true, 'tipo' => 'texto_puro'];
        }

        return ['ok' => false, 'legado' => false, 'tipo' => 'invalida'];
    }
}

if (!function_exists('rojexMigrarSenhaLegada')) {
    function rojexMigrarSenhaLegada(
        mysqli $conn,
        string $tabelaOrigem,
        int $usuarioId,
        string $senha
    ): bool {
        $tabelasPermitidas = ['usuarios', 'usuarios_sistema'];

        if (!in_array($tabelaOrigem, $tabelasPermitidas, true)) {
            return false;
        }

        $novoHash = password_hash($senha, PASSWORD_DEFAULT);

        if (!is_string($novoHash) || $novoHash === '') {
            return false;
        }

        $tabelaSql = '`' . $tabelaOrigem . '`';
        $sql = "UPDATE {$tabelaSql} SET senha = ?";

        if (rojexLoginColunaExiste($conn, $tabelaOrigem, 'atualizado_em')) {
            $sql .= ", atualizado_em = NOW()";
        }

        $sql .= " WHERE id = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $novoHash, $usuarioId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('rojexLoginAplicarAtrasoSeguro')) {
    function rojexLoginAplicarAtrasoSeguro(int $tentativas): void
    {
        if ($tentativas <= 0) {
            return;
        }

        $microssegundos = min(1500000, 150000 * $tentativas);
        usleep($microssegundos);
    }
}

if (!function_exists('rojexLoginBloqueadoTemporariamente')) {
    function rojexLoginBloqueadoTemporariamente(): bool
    {
        $bloqueadoAte = (int)($_SESSION['login_bloqueado_ate'] ?? 0);

        if ($bloqueadoAte <= time()) {
            unset($_SESSION['login_bloqueado_ate']);
            return false;
        }

        return true;
    }
}

if (!function_exists('rojexLoginRegistrarFalhaLocal')) {
    function rojexLoginRegistrarFalhaLocal(): void
    {
        $agora = time();
        $janela = 900;
        $limite = 5;

        $inicioJanela = (int)($_SESSION['login_falhas_inicio'] ?? 0);

        if ($inicioJanela <= 0 || ($agora - $inicioJanela) > $janela) {
            $_SESSION['login_falhas_inicio'] = $agora;
            $_SESSION['login_falhas_total'] = 0;
        }

        $_SESSION['login_falhas_total'] = (int)($_SESSION['login_falhas_total'] ?? 0) + 1;

        if ($_SESSION['login_falhas_total'] >= $limite) {
            $_SESSION['login_bloqueado_ate'] = $agora + 300;
        }
    }
}

if (!function_exists('rojexLoginLimparFalhasLocais')) {
    function rojexLoginLimparFalhasLocais(): void
    {
        unset(
            $_SESSION['login_falhas_inicio'],
            $_SESSION['login_falhas_total'],
            $_SESSION['login_bloqueado_ate']
        );
    }
}

if (!function_exists('rojexRegistrarEventoLogin')) {
    function rojexRegistrarEventoLogin(
        ?mysqli $conn,
        string $acao,
        string $resultado,
        string $nivel,
        string $usuarioInformado,
        ?array $usuarioEncontrado = null,
        ?string $detalhes = null
    ): void {
        if (!$conn || !function_exists('sgl_registrar_log')) {
            return;
        }

        try {
            $registroId = isset($usuarioEncontrado['id'])
                ? (string)$usuarioEncontrado['id']
                : null;

            sgl_registrar_log(
                $conn,
                $acao,
                'usuarios',
                $registroId,
                $detalhes,
                [
                    'tipo_acao' => 'LOGIN',
                    'modulo' => 'Autenticação',
                    'origem' => 'Tela de login',
                    'resultado' => $resultado,
                    'nivel' => $nivel,
                    'dados_novos' => [
                        'usuario_informado' => mb_substr(
                            $usuarioInformado,
                            0,
                            80,
                            'UTF-8'
                        ),
                        'usuario_localizado' => $usuarioEncontrado !== null,
                    ],
                ]
            );
        } catch (Throwable $e) {
            error_log('ROJEX LOGIN LOG: ' . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = mb_substr(
        trim((string)($_POST['usuario'] ?? '')),
        0,
        80,
        'UTF-8'
    );
    $senha = (string)($_POST['senha'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $conn = null;

    if (rojexLoginBloqueadoTemporariamente()) {
        rojexLoginAplicarAtrasoSeguro(
            (int)($_SESSION['login_falhas_total'] ?? 5)
        );
        $mensagem_erro =
            'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.';
    } elseif (!validarTokenCsrf($csrf)) {
        rojexLoginRegistrarFalhaLocal();

        try {
            $conn = conectar();
            rojexRegistrarEventoLogin(
                $conn,
                'Tentativa de login com token inválido',
                'NEGADO',
                'AVISO',
                $usuario,
                null,
                'Token CSRF inválido ou expirado.'
            );
            $conn->close();
        } catch (Throwable $eLog) {
            error_log('ROJEX LOGIN CSRF LOG: ' . $eLog->getMessage());
        }

        $mensagem_erro =
            'Sessão expirada ou formulário inválido. Atualize a página e tente novamente.';
    } elseif ($usuario === '' || $senha === '') {
        rojexLoginRegistrarFalhaLocal();

        try {
            $conn = conectar();
            rojexRegistrarEventoLogin(
                $conn,
                'Tentativa de login com campos incompletos',
                'NEGADO',
                'AVISO',
                $usuario,
                null,
                'Usuário ou senha não informado.'
            );
            $conn->close();
        } catch (Throwable $eLog) {
            error_log('ROJEX LOGIN CAMPOS LOG: ' . $eLog->getMessage());
        }

        $mensagem_erro = 'Por favor, preencha todos os campos.';
    } elseif (strlen($senha) > 1024) {
        rojexLoginRegistrarFalhaLocal();
        $mensagem_erro = 'Usuário ou senha inválidos.';
    } else {
        try {
            $conn = conectar();
            $user = null;
            $usuarioLocalizado = null;
            $usuarioInativo = null;
            $tabelaOrigem = null;
            $validacaoSenha = [
                'ok' => false,
                'legado' => false,
                'tipo' => 'ausente',
            ];
            $candidatosAtivosValidos = [
                'usuarios' => [],
                'usuarios_sistema' => [],
            ];
            $candidatosInativosValidos = [
                'usuarios' => [],
                'usuarios_sistema' => [],
            ];
            $totalRegistrosLocalizados = 0;

            $consultasLogin = [
                'usuarios' =>
                    "SELECT id, nome, usuario, senha, perfil, status
                     FROM usuarios
                     WHERE usuario = ?",
                'usuarios_sistema' =>
                    "SELECT id, nome, usuario, senha, perfil, status
                     FROM usuarios_sistema
                     WHERE usuario = ?",
            ];

            foreach ($consultasLogin as $nomeTabela => $sql) {
                try {
                    $stmt = $conn->prepare($sql);

                    if (!$stmt) {
                        continue;
                    }

                    $stmt->bind_param('s', $usuario);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    while ($result && ($registro = $result->fetch_assoc())) {
                        $totalRegistrosLocalizados++;

                        if ($usuarioLocalizado === null) {
                            $usuarioLocalizado = $registro;
                        }

                        $validacaoCandidato = rojexValidarSenhaCompatível(
                            $senha,
                            (string)($registro['senha'] ?? '')
                        );

                        if (!$validacaoCandidato['ok']) {
                            continue;
                        }

                        $status = trim((string)($registro['status'] ?? 'Ativo'));
                        $candidato = [
                            'registro' => $registro,
                            'tabela' => $nomeTabela,
                            'validacao' => $validacaoCandidato,
                        ];

                        if (
                            $status === ''
                            || strcasecmp($status, 'Ativo') === 0
                            || $status === '1'
                        ) {
                            $candidatosAtivosValidos[$nomeTabela][] = $candidato;
                        } else {
                            $candidatosInativosValidos[$nomeTabela][] = $candidato;
                        }
                    }

                    if ($result instanceof mysqli_result) {
                        $result->free();
                    }

                    $stmt->close();
                } catch (Throwable $eTabela) {
                    error_log(
                        'ROJEX LOGIN consulta ignorada em ' .
                        $nomeTabela . ': ' . $eTabela->getMessage()
                    );
                }
            }

            $ativosUsuarios = $candidatosAtivosValidos['usuarios'];
            $ativosSistema = $candidatosAtivosValidos['usuarios_sistema'];
            $inativosUsuarios = $candidatosInativosValidos['usuarios'];
            $inativosSistema = $candidatosInativosValidos['usuarios_sistema'];

            /*
             * Um mesmo usuário pode estar legitimamente espelhado nas duas
             * tabelas oficiais. Isso não é duplicidade: a fonte principal é
             * `usuarios` e `usuarios_sistema` funciona como alternativa.
             *
             * O acesso só é ambíguo quando a mesma fonte possui mais de um
             * cadastro ativo que aceita a credencial ou quando os dois
             * espelhos válidos discordam sobre o perfil de autorização.
             */
            $duplicidadeMesmaFonte =
                count($ativosUsuarios) > 1
                || count($ativosSistema) > 1;
            $conflitoPerfil = false;

            if (
                count($ativosUsuarios) === 1
                && count($ativosSistema) === 1
            ) {
                $perfilUsuarios = rojexNormalizarPerfil(
                    (string)($ativosUsuarios[0]['registro']['perfil'] ?? '')
                );
                $perfilSistema = rojexNormalizarPerfil(
                    (string)($ativosSistema[0]['registro']['perfil'] ?? '')
                );
                $conflitoPerfil = $perfilUsuarios !== $perfilSistema;
            }

            $loginAmbiguo = $duplicidadeMesmaFonte || $conflitoPerfil;
            $candidatoSelecionado = null;

            if (!$loginAmbiguo && count($ativosUsuarios) === 1) {
                $candidatoSelecionado = $ativosUsuarios[0];
            } elseif (!$loginAmbiguo && count($ativosSistema) === 1) {
                $candidatoSelecionado = $ativosSistema[0];
            }

            if ($candidatoSelecionado !== null) {
                $user = $candidatoSelecionado['registro'];
                $tabelaOrigem = $candidatoSelecionado['tabela'];
                $validacaoSenha = $candidatoSelecionado['validacao'];
                $usuarioLocalizado = $user;
            } elseif (
                !$loginAmbiguo
                && count($ativosUsuarios) === 0
                && count($ativosSistema) === 0
                && (count($inativosUsuarios) > 0 || count($inativosSistema) > 0)
            ) {
                $candidatoInativo = count($inativosUsuarios) > 0
                    ? $inativosUsuarios[0]
                    : $inativosSistema[0];
                $usuarioInativo = $candidatoInativo['registro'];
                $usuarioLocalizado = $usuarioInativo;
            }

            if ($loginAmbiguo) {
                rojexLoginRegistrarFalhaLocal();
                rojexLoginAplicarAtrasoSeguro(
                    (int)($_SESSION['login_falhas_total'] ?? 1)
                );

                rojexRegistrarEventoLogin(
                    $conn,
                    'Login recusado por duplicidade de credencial',
                    'NEGADO',
                    'ERRO',
                    $usuario,
                    $usuarioLocalizado,
                    'Foi encontrada duplicidade ativa na mesma tabela ou conflito de perfil entre os cadastros oficiais. Total de registros localizados: ' .
                    $totalRegistrosLocalizados . '.'
                );

                error_log(
                    '[ROJEX LOGIN AMBIGUIDADE] Duplicidade na mesma fonte ou conflito de perfil para o usuário informado.'
                );

                $mensagem_erro = 'Usuário ou senha inválidos.';
                $conn->close();
            } elseif ($usuarioInativo) {
                rojexLoginRegistrarFalhaLocal();
                rojexLoginAplicarAtrasoSeguro(
                    (int)($_SESSION['login_falhas_total'] ?? 1)
                );

                rojexRegistrarEventoLogin(
                    $conn,
                    'Tentativa de login de usuário inativo',
                    'NEGADO',
                    'AVISO',
                    $usuario,
                    $usuarioInativo,
                    'Acesso recusado porque o cadastro está inativo.'
                );

                $mensagem_erro = 'Usuário ou senha inválidos.';
                $conn->close();
            } else {
                if (!$user && $totalRegistrosLocalizados === 0) {
                    /*
                     * Equaliza parcialmente o custo temporal para usuário inexistente.
                     * O hash é estático e não representa nenhuma credencial real.
                     */
                    password_verify(
                        $senha,
                        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
                    );
                }

                if ($user && $validacaoSenha['ok']) {
                    $senhaMigrada = false;

                    if (
                        $validacaoSenha['legado']
                        && is_string($tabelaOrigem)
                    ) {
                        try {
                            $senhaMigrada = rojexMigrarSenhaLegada(
                                $conn,
                                $tabelaOrigem,
                                (int)$user['id'],
                                $senha
                            );
                        } catch (Throwable $eMigracao) {
                            error_log(
                                'ROJEX MIGRAÇÃO SENHA: ' .
                                $eMigracao->getMessage()
                            );
                        }
                    }

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = (string)$user['usuario'];
                    $_SESSION['nome'] = (string)$user['nome'];
                    $_SESSION['perfil'] = rojexNormalizarPerfil(
                        (string)$user['perfil']
                    );

                    registrarInicioSessaoAutenticada();

                    /*
                     * Sprint 4.6 — Camada Multi-Tenant Enterprise.
                     *
                     * O contexto é carregado somente depois da autenticação e da
                     * regeneração da sessão. O MASTER entra no Modo Plataforma;
                     * administradores e usuários entram no escritório vinculado.
                     */
                    try {
                        $contextoTenant = rojexCarregarContextoTenant(
                            $conn,
                            (int)$user['id'],
                            (string)$_SESSION['perfil']
                        );
                    } catch (Throwable $eTenant) {
                        rojexRegistrarEventoLogin(
                            $conn,
                            'Login recusado por contexto Multi-Tenant inválido',
                            'NEGADO',
                            'ERRO',
                            $usuario,
                            $user,
                            'Autenticação válida, porém o contexto do escritório não pôde ser carregado.'
                        );

                        error_log(
                            '[ROJEX LOGIN TENANT] ' . $eTenant->getMessage()
                        );

                        rojexEncerrarSessaoLocal();

                        $mensagemTenant = trim($eTenant->getMessage());
                        $mensagem_erro = str_contains(
                            mb_strtolower($mensagemTenant, 'UTF-8'),
                            'escritório está encerrado'
                        )
                            ? 'Acesso bloqueado: este escritório está encerrado. Entre em contato com o administrador do sistema.'
                            : 'Seu acesso não possui um escritório ativo ou válido. Contate o administrador da plataforma.';

                        $conn->close();
                        $conn = null;

                        throw new RuntimeException(
                            $mensagem_erro,
                            0,
                            $eTenant
                        );
                    }

                    rojexLoginLimparFalhasLocais();

                    rojexRegistrarEventoLogin(
                        $conn,
                        'Login realizado com sucesso',
                        'SUCESSO',
                        'INFO',
                        $usuario,
                        $user,
                        $senhaMigrada
                            ? 'Sessão autenticada, contexto Multi-Tenant carregado e senha legada migrada automaticamente para hash seguro.'
                            : 'Sessão autenticada e contexto Multi-Tenant carregado com sucesso.'
                    );

                    if ($senhaMigrada) {
                        rojexRegistrarEventoLogin(
                            $conn,
                            'Senha legada migrada automaticamente',
                            'SUCESSO',
                            'INFO',
                            $usuario,
                            $user,
                            'Formato anterior: ' . $validacaoSenha['tipo'] .
                            '. Novo formato: password_hash.'
                        );
                    }

                    try {
                        if (
                            $tabelaOrigem === 'usuarios'
                            && rojexLoginColunaExiste(
                                $conn,
                                'usuarios',
                                'ultimo_login'
                            )
                        ) {
                            $idUsuario = (int)$user['id'];
                            $stmtLogin = $conn->prepare(
                                "UPDATE usuarios
                                 SET ultimo_login = NOW()
                                 WHERE id = ?"
                            );

                            if ($stmtLogin) {
                                $stmtLogin->bind_param('i', $idUsuario);
                                $stmtLogin->execute();
                                $stmtLogin->close();
                            }
                        }
                    } catch (Throwable $eUltimoLogin) {
                        error_log(
                            'ROJEX ULTIMO LOGIN: ' .
                            $eUltimoLogin->getMessage()
                        );
                    }

                    $conn->close();
                    header('Location: ../index.php');
                    exit();
                }

                rojexLoginRegistrarFalhaLocal();
                rojexLoginAplicarAtrasoSeguro(
                    (int)($_SESSION['login_falhas_total'] ?? 1)
                );

                rojexRegistrarEventoLogin(
                    $conn,
                    $usuarioLocalizado
                        ? 'Tentativa de login com senha inválida'
                        : 'Tentativa de login com usuário inexistente',
                    'NEGADO',
                    'AVISO',
                    $usuario,
                    $usuarioLocalizado,
                    $usuarioLocalizado
                        ? 'Credencial recusada por senha inválida.'
                        : 'Credencial recusada porque o usuário não foi localizado.'
                );

                $mensagem_erro = 'Usuário ou senha inválidos.';
                $conn->close();
            }
        } catch (Throwable $e) {
            error_log('ROJEX LOGIN ERRO GERAL: ' . $e->getMessage());

            try {
                if ($conn instanceof mysqli) {
                    rojexRegistrarEventoLogin(
                        $conn,
                        'Falha técnica durante autenticação',
                        'FALHA',
                        'ERRO',
                        $usuario,
                        null,
                        'Ocorreu uma falha técnica durante o processo de login.'
                    );
                    $conn->close();
                }
            } catch (Throwable $eLog) {
                error_log('ROJEX LOGIN ERRO LOG: ' . $eLog->getMessage());
            }

            $mensagemExcecao = trim($e->getMessage());

            $mensagem_erro = str_starts_with(
                $mensagemExcecao,
                'Acesso bloqueado: este escritório está encerrado.'
            )
                ? $mensagemExcecao
                : 'Não foi possível concluir o acesso. Tente novamente em instantes.';
        }
    }
}

$csrfToken = gerarTokenCsrf();
$logoLogin = '../' . $empresa->logoOficial();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($empresa->nomeSistema(), ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: <?= htmlspecialchars($empresa->corPrimaria(), ENT_QUOTES, 'UTF-8') ?>; --secondary: <?= htmlspecialchars($empresa->corSecundaria(), ENT_QUOTES, 'UTF-8') ?>; --accent: <?= htmlspecialchars($empresa->corAccent(), ENT_QUOTES, 'UTF-8') ?>; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; font-family: Arial, sans-serif; display:flex; align-items:center; justify-content:center; background: radial-gradient(circle at top left, rgba(13,110,253,.30), transparent 30%), linear-gradient(135deg, #06141f, #102a3d 48%, #070b12); padding:24px; }
        .login-shell { width:100%; max-width:980px; display:grid; grid-template-columns: 1.05fr .95fr; background:rgba(255,255,255,.96); border-radius:26px; overflow:hidden; box-shadow:0 28px 90px rgba(0,0,0,.35); }
        .brand-panel { padding:44px; color:#fff; background: linear-gradient(160deg, var(--primary), #07111a); position:relative; overflow:hidden; }
        .brand-panel:after { content:""; position:absolute; width:340px; height:340px; border-radius:50%; right:-120px; top:-120px; background:rgba(255,255,255,.08); }
        .brand-logo { max-width:240px; max-height:160px; object-fit:contain; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.15); border-radius:18px; padding:14px; margin-bottom:28px; }
        .brand-panel h1 { margin:0 0 12px; font-size:36px; letter-spacing:.5px; }
        .brand-panel p { margin:0; color:rgba(255,255,255,.78); line-height:1.6; }
        .badge { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:rgba(212,175,55,.14); color:var(--accent); margin-top:28px; font-weight:700; font-size:13px; }
        .form-panel { padding:48px 44px; display:flex; flex-direction:column; justify-content:center; }
        .form-panel h2 { margin:0 0 8px; color:#102a3d; font-size:28px; }
        .subtitle { color:#667085; margin:0 0 28px; }
        .form-group { margin-bottom:16px; }
        label { display:block; margin-bottom:7px; color:#344054; font-weight:700; }
        input[type="text"], input[type="password"] { width:100%; padding:13px 14px; border:1px solid #d0d5dd; border-radius:12px; font-size:15px; outline:none; transition:.2s; }
        input:focus { border-color:var(--secondary); box-shadow:0 0 0 4px rgba(13,110,253,.12); }
        button { width:100%; padding:13px; background:linear-gradient(135deg, var(--secondary), var(--primary)); color:white; border:none; border-radius:12px; cursor:pointer; font-size:16px; font-weight:800; margin-top:8px; }
        .mensagem-erro { color:#842029; background:#f8d7da; border:1px solid #f5c2c7; padding:12px; border-radius:12px; margin-bottom:18px; text-align:center; }
        .powered { margin-top:24px; color:#667085; font-size:12px; text-align:center; }
        @media (max-width: 800px) { .login-shell { grid-template-columns:1fr; } .brand-panel { display:none; } .form-panel { padding:34px 24px; } }
    </style>
</head>
<body>
    <div class="login-shell">
        <section class="brand-panel">
            <img src="<?= htmlspecialchars($logoLogin, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" alt="Logo" class="brand-logo">
            <h1><?= htmlspecialchars($empresa->nomeSistema(), ENT_QUOTES, 'UTF-8') ?></h1>
            <p>ERP Jurídico Inteligente para gestão profissional de escritórios, processos, clientes, agenda, financeiro e relatórios.</p>
            <div class="badge"><i class="bi bi-shield-check"></i> Ambiente seguro e profissional</div>
        </section>
        <section class="form-panel">
            <h2>Acessar sistema</h2>
            <p class="subtitle">ERP Jurídico Enterprise</p>
            <?php if ($mensagem_erro): ?>
                <div class="mensagem-erro"><?= htmlspecialchars($mensagem_erro, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form action="login.php" method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="usuario">Usuário</label>
                    <input type="text" id="usuario" name="usuario" maxlength="80" autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" maxlength="1024" autocomplete="current-password" required>
                </div>
                <button type="submit"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
            </form>
            <div class="powered"><?= htmlspecialchars($empresa->poweredBy(), ENT_QUOTES, 'UTF-8') ?></div>
        </section>
    </div>
</body>
</html>