<?php
/**
 * modules/configuracoes.php
 * Fase 2.8 — Configurações profissionais do SGL Advocacia.
 * Mantém arquitetura modular atual, com segurança, CSRF e recursos de administração.
 */

$conn = conectar();
$upload_dir = __DIR__ . '/../assets/img/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

// -----------------------------------------------------------------------------
// Base estrutural mínima
// -----------------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) NOT NULL,
    valor TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS logs_sistema (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    acao VARCHAR(120) NOT NULL,
    tabela VARCHAR(80) NULL,
    registro_id VARCHAR(30) NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_usuario (usuario_id),
    INDEX idx_logs_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// -----------------------------------------------------------------------------
// Funções utilitárias
// -----------------------------------------------------------------------------
function sgl_cfg_get(mysqli $conn, string $chave, string $default = ''): string {
    $stmt = $conn->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
    $stmt->bind_param('s', $chave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['valor'] : $default;
}

function sgl_cfg_set(mysqli $conn, string $chave, string $valor): void {
    $stmt = $conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $stmt->bind_param('ss', $chave, $valor);
    $stmt->execute();
    $stmt->close();
}

function sgl_limpar_texto(string $texto, int $max = 255): string {
    $texto = trim(strip_tags($texto));
    return mb_substr($texto, 0, $max, 'UTF-8');
}

function sgl_validar_hex(string $cor, string $padrao): string {
    $cor = trim($cor);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) ? $cor : $padrao;
}

function sgl_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela) || !preg_match('/^[a-zA-Z0-9_]+$/', $coluna)) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($resultado['total'] ?? 0)) > 0;
}

function sgl_tabela_existe(mysqli $conn, string $tabela): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($resultado['total'] ?? 0)) > 0;
}

function sgl_log(mysqli $conn, string $acao, ?string $tabela = null, ?string $registro = null, ?string $detalhes = null): void {
    try {
        $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $conn->prepare("INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $usuario_id, $acao, $tabela, $registro, $detalhes, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Log nunca deve quebrar a tela de configuração.
    }
}

function sgl_redirect_cfg(string $tab, string $tipo, string $msg): void {
    $url = '?mod=configuracoes&tab=' . urlencode($tab) . '&msg_' . urlencode($tipo) . '=' . urlencode($msg);
    echo "<script>window.location.href = '" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "';</script>";
    exit;
}

function sgl_select_count(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if ($res) {
            $row = $res->fetch_assoc();
            return (int)($row['total'] ?? 0);
        }
    } catch (Throwable $e) {}
    return 0;
}

function sgl_buscar_lixeira(mysqli $conn): array {
    $itens = [];
    $mapa = [
        'advogados' => ['campo' => 'nome', 'cond' => "status='Excluído'"],
        'clientes' => ['campo' => 'nome', 'cond' => sgl_coluna_existe($conn, 'clientes', 'deletado') ? 'deletado = 1' : "status='Excluído'"],
        'processos' => ['campo' => 'numero_processo', 'cond' => "status='Excluído'"],
        'agenda' => ['campo' => 'titulo', 'cond' => sgl_coluna_existe($conn, 'agenda', 'deletado') ? 'deletado = 1' : "status='Cancelado'"],
    ];

    foreach ($mapa as $tabela => $cfg) {
        if (!sgl_tabela_existe($conn, $tabela) || !sgl_coluna_existe($conn, $tabela, $cfg['campo'])) {
            continue;
        }
        try {
            $sql = "SELECT id, `{$cfg['campo']}` AS nome FROM `$tabela` WHERE {$cfg['cond']} ORDER BY id DESC LIMIT 100";
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $itens[] = [
                        'tabela' => $tabela,
                        'id' => (string)$row['id'],
                        'nome' => (string)($row['nome'] ?: 'Registro sem descrição'),
                        'tipo' => ucfirst($tabela),
                    ];
                }
            }
        } catch (Throwable $e) {}
    }
    return $itens;
}

function sgl_backup_resumo(mysqli $conn): array {
    $tabelas = ['usuarios','advogados','clientes','processos','agenda','honorarios','contas_pagar','contas_receber','configuracoes','logs_sistema'];
    $saida = [];
    foreach ($tabelas as $tabela) {
        if (sgl_tabela_existe($conn, $tabela)) {
            $saida[$tabela] = sgl_select_count($conn, "SELECT COUNT(*) AS total FROM `$tabela`");
        }
    }
    return $saida;
}

$msg = '';
$msg_tipo = 'success';
$acao_cfg = $_POST['acao_cfg'] ?? '';
$csrf = gerarTokenCsrf();
$tab_ativa = $_GET['tab'] ?? 'escritorio';

if (isset($_GET['msg_sucesso'])) { $msg = $_GET['msg_sucesso']; $msg_tipo = 'success'; }
if (isset($_GET['msg_aviso'])) { $msg = $_GET['msg_aviso']; $msg_tipo = 'warning'; }
if (isset($_GET['msg_erro'])) { $msg = $_GET['msg_erro']; $msg_tipo = 'danger'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validarTokenCsrf($_POST['csrf_token'] ?? null)) {
    $msg = 'Token de segurança inválido. Atualize a página e tente novamente.';
    $msg_tipo = 'danger';
    $acao_cfg = '';
}

// -----------------------------------------------------------------------------
// Ações POST
// -----------------------------------------------------------------------------
if ($acao_cfg === 'salvar_escritorio') {
    $campos = [
        'nome_escritorio' => 140,
        'responsavel_escritorio' => 140,
        'oab_responsavel' => 60,
        'cpf_cnpj_escritorio' => 30,
        'telefone_escritorio' => 40,
        'whatsapp_escritorio' => 40,
        'email_escritorio' => 140,
        'site_escritorio' => 160,
        'cep_escritorio' => 20,
        'endereco_escritorio' => 180,
        'cidade_escritorio' => 100,
        'uf_escritorio' => 2,
        'rodape_documentos' => 255,
    ];
    foreach ($campos as $campo => $max) {
        $valor = sgl_limpar_texto((string)($_POST[$campo] ?? ''), $max);
        if ($campo === 'email_escritorio' && $valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            sgl_redirect_cfg('escritorio', 'erro', 'E-mail do escritório inválido.');
        }
        if ($campo === 'uf_escritorio') {
            $valor = strtoupper($valor);
        }
        sgl_cfg_set($conn, $campo, $valor);
    }
    sgl_log($conn, 'Atualizou dados do escritório', 'configuracoes', null, 'Aba Escritório');
    sgl_redirect_cfg('escritorio', 'sucesso', 'Dados do escritório salvos com sucesso.');
}

if ($acao_cfg === 'upload_logo' && isset($_FILES['logo'])) {
    $file = $_FILES['logo'];
    $allowed = ['image/jpeg'=>'jpg', 'image/jpg'=>'jpg', 'image/png'=>'png'];
    $tmp = $file['tmp_name'] ?? '';
    $mime = is_uploaded_file($tmp) ? (mime_content_type($tmp) ?: '') : '';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        sgl_redirect_cfg('marca', 'erro', 'Erro no upload da logomarca.');
    } elseif (!isset($allowed[$mime])) {
        sgl_redirect_cfg('marca', 'erro', 'Use apenas imagens JPG ou PNG.');
    } elseif (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        sgl_redirect_cfg('marca', 'erro', 'A logomarca deve ter no máximo 2 MB.');
    } else {
        foreach (glob($upload_dir . 'logo_custom.*') as $f) { @unlink($f); }
        $nome = 'logo_custom.' . $allowed[$mime];
        if (move_uploaded_file($tmp, $upload_dir . $nome)) {
            sgl_cfg_set($conn, 'logo_arquivo', $nome);
            sgl_log($conn, 'Atualizou logomarca', 'configuracoes', null, $nome);
            sgl_redirect_cfg('marca', 'sucesso', 'Logomarca atualizada com sucesso.');
        }
        sgl_redirect_cfg('marca', 'erro', 'Falha ao salvar a imagem. Verifique permissões de assets/img.');
    }
}

if ($acao_cfg === 'remover_logo') {
    foreach (glob($upload_dir . 'logo_custom.*') as $f) { @unlink($f); }
    $stmt = $conn->prepare("DELETE FROM configuracoes WHERE chave = 'logo_arquivo'");
    $stmt->execute();
    $stmt->close();
    sgl_log($conn, 'Removeu logomarca personalizada', 'configuracoes');
    sgl_redirect_cfg('marca', 'aviso', 'Logo personalizada removida.');
}

if ($acao_cfg === 'salvar_tema') {
    sgl_cfg_set($conn, 'cor_primaria', sgl_validar_hex((string)($_POST['cor_primaria'] ?? ''), '#1a3c5e'));
    sgl_cfg_set($conn, 'cor_secundaria', sgl_validar_hex((string)($_POST['cor_secundaria'] ?? ''), '#2c6fad'));
    sgl_cfg_set($conn, 'cor_accent', sgl_validar_hex((string)($_POST['cor_accent'] ?? ''), '#f0a500'));
    sgl_cfg_set($conn, 'tema_modo', in_array(($_POST['tema_modo'] ?? 'claro'), ['claro','escuro'], true) ? $_POST['tema_modo'] : 'claro');
    sgl_log($conn, 'Atualizou identidade visual', 'configuracoes');
    sgl_redirect_cfg('tema', 'sucesso', 'Tema salvo com sucesso.');
}

if ($acao_cfg === 'restaurar_tema') {
    sgl_cfg_set($conn, 'cor_primaria', '#1a3c5e');
    sgl_cfg_set($conn, 'cor_secundaria', '#2c6fad');
    sgl_cfg_set($conn, 'cor_accent', '#f0a500');
    sgl_cfg_set($conn, 'tema_modo', 'claro');
    sgl_log($conn, 'Restaurou tema padrão', 'configuracoes');
    sgl_redirect_cfg('tema', 'aviso', 'Tema padrão restaurado.');
}

if ($acao_cfg === 'novo_usuario') {
    $nome = sgl_limpar_texto((string)($_POST['nome'] ?? ''), 120);
    $usuario = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($_POST['usuario'] ?? ''));
    $email = sgl_limpar_texto((string)($_POST['email'] ?? ''), 120);
    $perfil = (string)($_POST['perfil'] ?? 'Usuário');
    $senha = (string)($_POST['senha'] ?? '');
    $perfis = ['Administrador','Advogado','Atendente','Financeiro','Usuário'];

    if ($nome === '' || $usuario === '' || strlen($senha) < 6 || !in_array($perfil, $perfis, true)) {
        sgl_redirect_cfg('usuarios', 'erro', 'Preencha nome, usuário, perfil e senha com no mínimo 6 caracteres.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sgl_redirect_cfg('usuarios', 'erro', 'E-mail do usuário inválido.');
    }

    try {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO usuarios (nome, usuario, email, senha, perfil, ativo) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->bind_param('sssss', $nome, $usuario, $email, $hash, $perfil);
        $stmt->execute();
        $novoId = (string)$stmt->insert_id;
        $stmt->close();
        sgl_log($conn, 'Criou usuário', 'usuarios', $novoId, $usuario);
        sgl_redirect_cfg('usuarios', 'sucesso', 'Usuário criado com sucesso.');
    } catch (Throwable $e) {
        sgl_redirect_cfg('usuarios', 'erro', 'Não foi possível criar usuário. Verifique se o login já existe.');
    }
}

if ($acao_cfg === 'alterar_status_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];
    $ativo = (int)($_POST['ativo'] ?? 1);
    if ($id === (int)($_SESSION['user_id'] ?? 0) && $ativo === 0) {
        sgl_redirect_cfg('usuarios', 'erro', 'Você não pode desativar o próprio usuário logado.');
    }
    $stmt = $conn->prepare("UPDATE usuarios SET ativo = ?, atualizado_em = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $ativo, $id);
    $stmt->execute();
    $stmt->close();
    sgl_log($conn, $ativo ? 'Ativou usuário' : 'Desativou usuário', 'usuarios', (string)$id);
    sgl_redirect_cfg('usuarios', 'sucesso', 'Status do usuário atualizado.');
}

if ($acao_cfg === 'resetar_senha_usuario' && !empty($_POST['usuario_id'])) {
    $id = (int)$_POST['usuario_id'];
    $nova = (string)($_POST['nova_senha'] ?? '');
    if (strlen($nova) < 6) {
        sgl_redirect_cfg('usuarios', 'erro', 'A nova senha deve ter no mínimo 6 caracteres.');
    }
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE usuarios SET senha = ?, atualizado_em = NOW() WHERE id = ?");
    $stmt->bind_param('si', $hash, $id);
    $stmt->execute();
    $stmt->close();
    sgl_log($conn, 'Redefiniu senha de usuário', 'usuarios', (string)$id);
    sgl_redirect_cfg('usuarios', 'sucesso', 'Senha redefinida com sucesso.');
}

if ($acao_cfg === 'salvar_sistema') {
    $modo_debug = !empty($_POST['modo_debug']) ? '1' : '0';
    $dias_alerta = max(1, min(60, (int)($_POST['dias_alerta_prazos'] ?? 7)));
    $itens_pagina = max(10, min(100, (int)($_POST['itens_por_pagina'] ?? 25)));
    sgl_cfg_set($conn, 'modo_debug', $modo_debug);
    sgl_cfg_set($conn, 'dias_alerta_prazos', (string)$dias_alerta);
    sgl_cfg_set($conn, 'itens_por_pagina', (string)$itens_pagina);
    sgl_log($conn, 'Atualizou parâmetros do sistema', 'configuracoes');
    sgl_redirect_cfg('sistema', 'sucesso', 'Parâmetros do sistema salvos.');
}

if ($acao_cfg === 'restaurar_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $tb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_POST['tabela']);
    $id = (string)$_POST['item_id'];
    $permitidas = ['advogados','clientes','processos','agenda'];
    if (in_array($tb, $permitidas, true) && sgl_tabela_existe($conn, $tb)) {
        if ($tb === 'clientes' && sgl_coluna_existe($conn, $tb, 'deletado')) {
            $stmt = $conn->prepare("UPDATE `$tb` SET deletado = 0 WHERE id = ?");
        } elseif ($tb === 'agenda' && sgl_coluna_existe($conn, $tb, 'deletado')) {
            $stmt = $conn->prepare("UPDATE `$tb` SET deletado = 0 WHERE id = ?");
        } else {
            $status = ($tb === 'processos') ? 'Em Andamento' : 'Ativo';
            $stmt = $conn->prepare("UPDATE `$tb` SET status = ? WHERE id = ?");
            $stmt->bind_param('ss', $status, $id);
            $stmt->execute();
            $stmt->close();
            sgl_log($conn, 'Restaurou item da lixeira', $tb, $id);
            sgl_redirect_cfg('lixeira', 'sucesso', 'Registro restaurado com sucesso.');
        }
        if (isset($stmt)) {
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $stmt->close();
            sgl_log($conn, 'Restaurou item da lixeira', $tb, $id);
        }
    }
    sgl_redirect_cfg('lixeira', 'sucesso', 'Registro restaurado com sucesso.');
}

if ($acao_cfg === 'excluir_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $tb = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_POST['tabela']);
    $id = (string)$_POST['item_id'];
    $permitidas = ['advogados','clientes','processos','agenda'];
    if (in_array($tb, $permitidas, true) && sgl_tabela_existe($conn, $tb)) {
        $stmt = $conn->prepare("DELETE FROM `$tb` WHERE id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
        sgl_log($conn, 'Excluiu definitivamente item da lixeira', $tb, $id);
    }
    sgl_redirect_cfg('lixeira', 'aviso', 'Item excluído permanentemente.');
}

// -----------------------------------------------------------------------------
// Dados para tela
// -----------------------------------------------------------------------------
$config_padrao = [
    'nome_escritorio' => 'SGL Advocacia',
    'responsavel_escritorio' => '',
    'oab_responsavel' => '',
    'cpf_cnpj_escritorio' => '',
    'telefone_escritorio' => '',
    'whatsapp_escritorio' => '',
    'email_escritorio' => '',
    'site_escritorio' => '',
    'cep_escritorio' => '',
    'endereco_escritorio' => '',
    'cidade_escritorio' => '',
    'uf_escritorio' => '',
    'rodape_documentos' => '',
    'cor_primaria' => '#1a3c5e',
    'cor_secundaria' => '#2c6fad',
    'cor_accent' => '#f0a500',
    'tema_modo' => 'claro',
    'modo_debug' => '0',
    'dias_alerta_prazos' => '7',
    'itens_por_pagina' => '25',
    'logo_arquivo' => '',
];

$cfg = [];
foreach ($config_padrao as $chave => $default) {
    $cfg[$chave] = sgl_cfg_get($conn, $chave, $default);
}
$logo_exibir = $cfg['logo_arquivo'] ? 'assets/img/' . htmlspecialchars($cfg['logo_arquivo'], ENT_QUOTES, 'UTF-8') : 'assets/img/logo_custom.png';
$lixeira_itens = sgl_buscar_lixeira($conn);
$backup_resumo = sgl_backup_resumo($conn);

$usuarios = [];
if (sgl_tabela_existe($conn, 'usuarios')) {
    $resUsuarios = $conn->query("SELECT id, nome, usuario, email, perfil, ativo, criado_em, ultimo_login FROM usuarios ORDER BY ativo DESC, nome ASC");
    if ($resUsuarios) {
        while ($u = $resUsuarios->fetch_assoc()) {
            $usuarios[] = $u;
        }
    }
}

$logs = [];
if (sgl_tabela_existe($conn, 'logs_sistema')) {
    try {
        $resLogs = $conn->query("SELECT l.*, u.nome AS usuario_nome FROM logs_sistema l LEFT JOIN usuarios u ON u.id = l.usuario_id ORDER BY l.id DESC LIMIT 20");
        if ($resLogs) {
            while ($l = $resLogs->fetch_assoc()) { $logs[] = $l; }
        }
    } catch (Throwable $e) {}
}

$totalUsuarios = count($usuarios);
$totalAtivos = count(array_filter($usuarios, fn($u) => (int)$u['ativo'] === 1));
$totalLogs = sgl_select_count($conn, "SELECT COUNT(*) AS total FROM logs_sistema");
$totalLixeira = count($lixeira_itens);

$tabs_validas = ['escritorio','marca','tema','usuarios','sistema','lixeira','logs'];
if (!in_array($tab_ativa, $tabs_validas, true)) { $tab_ativa = 'escritorio'; }
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h3 class="mb-1 text-primary"><i class="bi bi-gear-fill me-2"></i>Configurações</h3>
        <p class="text-muted mb-0">Administração do escritório, usuários, identidade visual, segurança e manutenção do sistema.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?mod=dashboard" class="btn btn-outline-secondary"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?=htmlspecialchars($msg_tipo)?> alert-dismissible fade show shadow-sm">
        <?=htmlspecialchars($msg)?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">USUÁRIOS</small><h3 class="mb-0"><?= $totalUsuarios ?></h3><small class="text-success"><?= $totalAtivos ?> ativo(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">LIXEIRA</small><h3 class="mb-0 text-danger"><?= $totalLixeira ?></h3><small class="text-muted">registro(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">AUDITORIA</small><h3 class="mb-0 text-primary"><?= $totalLogs ?></h3><small class="text-muted">evento(s)</small></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">BANCO</small><h3 class="mb-0 text-success"><i class="bi bi-check-circle"></i></h3><small class="text-success">conectado</small></div></div></div>
</div>

<ul class="nav nav-tabs mb-4" id="cfgTabs" role="tablist">
    <?php
    $tabDefs = [
        'escritorio' => ['Escritório','bi-building'],
        'marca' => ['Marca','bi-image'],
        'tema' => ['Tema','bi-palette'],
        'usuarios' => ['Usuários','bi-people'],
        'sistema' => ['Sistema','bi-sliders'],
        'lixeira' => ['Lixeira','bi-trash3'],
        'logs' => ['Logs','bi-clock-history'],
    ];
    foreach ($tabDefs as $id => $tab) :
        $active = $tab_ativa === $id ? 'active' : '';
        $badge = ($id === 'lixeira' && $totalLixeira > 0) ? '<span class="badge bg-danger ms-1">' . $totalLixeira . '</span>' : '';
    ?>
    <li class="nav-item" role="presentation">
        <a class="nav-link <?=$active?>" href="?mod=configuracoes&tab=<?=$id?>"><i class="bi <?=$tab[1]?> me-1"></i><?=$tab[0]?><?=$badge?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
<?php if ($tab_ativa === 'escritorio'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white"><i class="bi bi-building me-1"></i> Dados do Escritório</div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_escritorio">
            <div class="col-md-6"><label class="form-label">Nome do escritório</label><input name="nome_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['nome_escritorio'])?>"></div>
            <div class="col-md-6"><label class="form-label">Responsável técnico</label><input name="responsavel_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['responsavel_escritorio'])?>"></div>
            <div class="col-md-3"><label class="form-label">OAB</label><input name="oab_responsavel" class="form-control" value="<?=htmlspecialchars($cfg['oab_responsavel'])?>"></div>
            <div class="col-md-3"><label class="form-label">CPF/CNPJ</label><input name="cpf_cnpj_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cpf_cnpj_escritorio'])?>"></div>
            <div class="col-md-3"><label class="form-label">Telefone</label><input name="telefone_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['telefone_escritorio'])?>"></div>
            <div class="col-md-3"><label class="form-label">WhatsApp</label><input name="whatsapp_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['whatsapp_escritorio'])?>"></div>
            <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['email_escritorio'])?>"></div>
            <div class="col-md-6"><label class="form-label">Site</label><input name="site_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['site_escritorio'])?>"></div>
            <div class="col-md-2"><label class="form-label">CEP</label><input name="cep_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cep_escritorio'])?>"></div>
            <div class="col-md-5"><label class="form-label">Endereço</label><input name="endereco_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['endereco_escritorio'])?>"></div>
            <div class="col-md-4"><label class="form-label">Cidade</label><input name="cidade_escritorio" class="form-control" value="<?=htmlspecialchars($cfg['cidade_escritorio'])?>"></div>
            <div class="col-md-1"><label class="form-label">UF</label><input name="uf_escritorio" maxlength="2" class="form-control text-uppercase" value="<?=htmlspecialchars($cfg['uf_escritorio'])?>"></div>
            <div class="col-12"><label class="form-label">Rodapé padrão para documentos e recibos</label><input name="rodape_documentos" class="form-control" value="<?=htmlspecialchars($cfg['rodape_documentos'])?>" placeholder="Ex.: Documento emitido pelo Sistema SGL Advocacia"></div>
            <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar dados do escritório</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'marca'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white"><i class="bi bi-image me-1"></i> Marca e Logomarca</div>
    <div class="card-body">
        <div class="row g-4 align-items-start">
            <div class="col-md-4 text-center">
                <p class="text-muted small mb-2">Logo atual</p>
                <img src="<?=$logo_exibir?>?v=<?=time()?>" class="img-thumbnail bg-light" style="max-width:240px;max-height:240px;object-fit:contain;" alt="Logo atual">
                <?php if ($cfg['logo_arquivo']): ?>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="remover_logo">
                    <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Remover logo personalizada?')"><i class="bi bi-x-circle me-1"></i>Remover logo</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="upload_logo">
                    <label class="form-label fw-semibold">Enviar nova logomarca</label>
                    <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png" required onchange="prevLogo(this)">
                    <div class="form-text">JPG ou PNG. Tamanho máximo: 2 MB. O sistema usará esta logo no menu e futuramente em PDFs/recibos.</div>
                    <div id="prev_wrap" style="display:none;" class="mt-3"><img id="prev_img" src="#" class="img-thumbnail" style="max-width:220px;max-height:140px;object-fit:contain;" alt="Prévia"></div>
                    <button class="btn btn-primary mt-3"><i class="bi bi-upload me-1"></i>Enviar logomarca</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'tema'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white"><i class="bi bi-palette me-1"></i> Identidade Visual</div>
    <div class="card-body">
        <form method="POST" class="row g-4">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="acao_cfg" value="salvar_tema">
            <?php foreach ([['cor_primaria','Cor primária / menu'], ['cor_secundaria','Cor secundária / ativo'], ['cor_accent','Cor de destaque / dourado']] as $c): ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold"><?=$c[1]?></label>
                <div class="input-group">
                    <input type="color" name="<?=$c[0]?>" id="<?=$c[0]?>" class="form-control form-control-color" value="<?=htmlspecialchars($cfg[$c[0]])?>" oninput="syncCor('<?=$c[0]?>')">
                    <input type="text" id="<?=$c[0]?>_txt" class="form-control" value="<?=htmlspecialchars($cfg[$c[0]])?>" maxlength="7" style="font-family:monospace" oninput="syncTxt('<?=$c[0]?>')">
                </div>
                <div class="mt-2 rounded p-2 text-white text-center small" id="prev_<?=$c[0]?>" style="background:<?=htmlspecialchars($cfg[$c[0]])?>;">Prévia</div>
            </div>
            <?php endforeach; ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Modo visual</label>
                <select name="tema_modo" class="form-select">
                    <option value="claro" <?=$cfg['tema_modo']==='claro'?'selected':''?>>Claro</option>
                    <option value="escuro" <?=$cfg['tema_modo']==='escuro'?'selected':''?>>Escuro / Premium</option>
                </select>
                <div class="form-text">O modo escuro será ampliado nas próximas etapas.</div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar tema</button>
        </form>
                <form method="POST" onsubmit="return confirm('Restaurar tema padrão?')">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="restaurar_tema">
                    <button class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar padrão</button>
                </form>
            </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'usuarios'): ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-person-plus me-1"></i> Novo Usuário</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="novo_usuario">
                    <div class="col-12"><label class="form-label">Nome</label><input name="nome" class="form-control" required></div>
                    <div class="col-12"><label class="form-label">Usuário</label><input name="usuario" class="form-control" required></div>
                    <div class="col-12"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Perfil</label><select name="perfil" class="form-select"><option>Administrador</option><option>Advogado</option><option>Atendente</option><option>Financeiro</option><option>Usuário</option></select></div>
                    <div class="col-12"><label class="form-label">Senha inicial</label><input type="password" name="senha" class="form-control" minlength="6" required></div>
                    <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Criar usuário</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white d-flex justify-content-between"><span><i class="bi bi-people me-1"></i> Usuários do Sistema</span><span><?=count($usuarios)?> registro(s)</span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php if(empty($usuarios)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Nenhum usuário encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach($usuarios as $u): ?>
                    <tr>
                        <td><strong><?=htmlspecialchars($u['nome'])?></strong><br><small class="text-muted"><?=htmlspecialchars($u['email'] ?? '')?></small></td>
                        <td><code><?=htmlspecialchars($u['usuario'])?></code></td>
                        <td><span class="badge bg-secondary"><?=htmlspecialchars($u['perfil'])?></span></td>
                        <td><?=((int)$u['ativo']===1) ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-danger">Inativo</span>'?></td>
                        <td class="text-end">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="acao_cfg" value="alterar_status_usuario">
                                <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="ativo" value="<?=((int)$u['ativo']===1)?0:1?>">
                                <button class="btn btn-sm <?=((int)$u['ativo']===1)?'btn-outline-danger':'btn-outline-success'?>" onclick="return confirm('Alterar status deste usuário?')"><?=((int)$u['ativo']===1)?'Desativar':'Ativar'?></button>
                            </form>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#senha<?= (int)$u['id'] ?>">Senha</button>
                        </td>
                    </tr>
                    <tr class="collapse" id="senha<?= (int)$u['id'] ?>"><td colspan="5">
                        <form method="POST" class="d-flex gap-2 justify-content-end">
                            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                            <input type="hidden" name="acao_cfg" value="resetar_senha_usuario">
                            <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <input type="password" name="nova_senha" class="form-control" style="max-width:260px" minlength="6" placeholder="Nova senha" required>
                            <button class="btn btn-primary btn-sm">Redefinir</button>
                        </form>
                    </td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'sistema'): ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-sliders me-1"></i> Parâmetros Gerais</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="acao_cfg" value="salvar_sistema">
                    <div class="col-md-6"><label class="form-label">Alertar prazos em até X dias</label><input type="number" min="1" max="60" name="dias_alerta_prazos" class="form-control" value="<?=htmlspecialchars($cfg['dias_alerta_prazos'])?>"></div>
                    <div class="col-md-6"><label class="form-label">Itens por página</label><input type="number" min="10" max="100" name="itens_por_pagina" class="form-control" value="<?=htmlspecialchars($cfg['itens_por_pagina'])?>"></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modo_debug" value="1" <?=$cfg['modo_debug']==='1'?'checked':''?>><label class="form-check-label">Modo debug controlado</label></div><div class="form-text">Em produção, mantenha desativado para não exibir detalhes técnicos ao usuário.</div></div>
                    <div class="col-12"><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar parâmetros</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white"><i class="bi bi-database-check me-1"></i> Resumo do Banco</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach($backup_resumo as $tb => $qt): ?>
                    <div class="col-md-6"><div class="border rounded p-2 bg-light d-flex justify-content-between"><span><?=htmlspecialchars($tb)?></span><strong><?=$qt?></strong></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Backup automático e exportação completa serão integrados na próxima fase de produção/DevOps.</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'lixeira'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center"><span><i class="bi bi-trash3 me-1"></i> Lixeira Central</span><span><?=count($lixeira_itens)?> item(ns)</span></div>
    <div class="card-body">
        <?php if(empty($lixeira_itens)): ?>
            <div class="text-center py-5 text-muted"><i class="bi bi-trash3 fs-1 d-block mb-3 opacity-25"></i><p class="mb-0">A lixeira está vazia.</p></div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>ID</th><th>Módulo</th><th>Descrição</th><th class="text-end">Ações</th></tr></thead><tbody>
        <?php foreach($lixeira_itens as $item): ?>
            <tr><td><code><?=htmlspecialchars($item['id'])?></code></td><td><span class="badge bg-secondary"><?=htmlspecialchars($item['tipo'])?></span></td><td><strong><?=htmlspecialchars($item['nome'])?></strong></td><td class="text-end">
                <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="restaurar_item_lixeira"><input type="hidden" name="tabela" value="<?=htmlspecialchars($item['tabela'])?>"><input type="hidden" name="item_id" value="<?=htmlspecialchars($item['id'])?>"><button class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button></form>
                <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="acao_cfg" value="excluir_item_lixeira"><input type="hidden" name="tabela" value="<?=htmlspecialchars($item['tabela'])?>"><input type="hidden" name="item_id" value="<?=htmlspecialchars($item['id'])?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir este item permanentemente?')"><i class="bi bi-trash3"></i> Excluir</button></form>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tab_ativa === 'logs'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white"><i class="bi bi-clock-history me-1"></i> Últimos Logs do Sistema</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Módulo</th><th>Detalhes</th></tr></thead><tbody>
    <?php if(empty($logs)): ?><tr><td colspan="5" class="text-center py-4 text-muted">Nenhum log registrado ainda.</td></tr><?php endif; ?>
    <?php foreach($logs as $log): ?><tr><td><?=date('d/m/Y H:i', strtotime($log['criado_em']))?></td><td><?=htmlspecialchars($log['usuario_nome'] ?? 'Sistema')?></td><td><strong><?=htmlspecialchars($log['acao'])?></strong></td><td><?=htmlspecialchars($log['tabela'] ?? '-')?></td><td><?=htmlspecialchars($log['detalhes'] ?? '-')?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
</div>

<script>
function prevLogo(input){const f=input.files[0];if(!f)return;const r=new FileReader();r.onload=e=>{document.getElementById('prev_img').src=e.target.result;document.getElementById('prev_wrap').style.display='block';};r.readAsDataURL(f);}
function syncCor(id){document.getElementById(id+'_txt').value=document.getElementById(id).value;document.getElementById('prev_'+id).style.background=document.getElementById(id).value;}
function syncTxt(id){const v=document.getElementById(id+'_txt').value;if(/^#[0-9A-Fa-f]{6}$/.test(v)){document.getElementById(id).value=v;document.getElementById('prev_'+id).style.background=v;}}
</script>
