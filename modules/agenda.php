<?php
$conn = conectar();
$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

// Captura mensagens vindas por redirecionamento seguro
if (isset($_GET['msg_sucesso'])) {
    $msg = "<div class='alert alert-success'>✅ " . htmlspecialchars($_GET['msg_sucesso']) . "</div>";
}
if (isset($_GET['msg_erro'])) {
    $msg = "<div class='alert alert-danger'>❌ " . htmlspecialchars($_GET['msg_erro']) . "</div>";
}

// Gera ID sequencial de forma segura: AGE001, AGE002...
function gerarIdAgenda(mysqli $conn): string
{
    $res = $conn->query("SELECT id FROM agenda ORDER BY id DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'AGE001';
    $ultimo = $res->fetch_assoc()['id'];
    $num    = (int) substr($ultimo, 3) + 1;
    return 'AGE' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

/* -----------------------------------------------------------
   AJUSTE SGL: LIXEIRA SEGURA ACTIONS (Agenda)
   (mover para lixeira / restaurar / excluir definitivo)
----------------------------------------------------------- */
if (isset($_GET['excluir'])) {
    $id = trim($_GET['excluir']);

    try {
        $stmt = $conn->prepare("UPDATE agenda SET deletado = 1 WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $stmt->close();

        echo "<script>window.location.href='?mod=agenda&msg_sucesso=Compromisso movido para a lixeira com sucesso!';</script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        $erro_msg = "Erro ao mover para lixeira: " . $e->getMessage();
        echo "<script>window.location.href='?mod=agenda&msg_erro=" . urlencode($erro_msg) . "';</script>";
        exit;
    }
}

if (isset($_GET['restaurar'])) {
    $id = trim($_GET['restaurar']);

    try {
        $stmt = $conn->prepare("UPDATE agenda SET deletado = 0 WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $stmt->close();

        echo "<script>window.location.href='?mod=agenda&msg_sucesso=Compromisso restaurado com sucesso!';</script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        $erro_msg = "Erro ao restaurar: " . $e->getMessage();
        echo "<script>window.location.href='?mod=agenda&msg_erro=" . urlencode($erro_msg) . "';</script>";
        exit;
    }
}

if (isset($_GET['excluir_permanente'])) {
    $id = trim($_GET['excluir_permanente']);

    try {
        $stmt = $conn->prepare("DELETE FROM agenda WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $stmt->close();

        echo "<script>window.location.href='?mod=agenda&acao=lixeira&msg_sucesso=Compromisso excluído permanentemente!';</script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        // Trata o erro de integridade referencial (chave estrangeira travando a exclusão)
        if ($e->getCode() === 1451) {
            $erro_msg = "Não é possível excluir este compromisso porque ele está sendo usado ou vinculado em outra tabela do sistema.";
        } else {
            $erro_msg = "Erro ao excluir: " . $e->getMessage();
        }
        echo "<script>window.location.href='?mod=agenda&acao=lixeira&msg_erro=" . urlencode($erro_msg) . "';</script>";
        exit;
    }
}

/* -----------------------------------------------------------
   SALVAR NOVO (Tratamento de NULL e Redirecionamento de Sucesso)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_evento'])) {
    $id               = gerarIdAgenda($conn);
    $data_evento      = trim($_POST['data_evento']      ?? '');
    $horario          = trim($_POST['horario']          ?? '');
    $tipo_compromisso = trim($_POST['tipo_compromisso'] ?? 'Audiência');
    $cliente_id       = trim($_POST['cliente_id']       ?? '');
    $nome_cliente     = trim($_POST['nome_cliente']     ?? '');
    $numero_processo  = trim($_POST['numero_processo']  ?? '');
    $local            = trim($_POST['local']            ?? '');
    $advogado_id      = trim($_POST['advogado_id']      ?? '');
    $status           = trim($_POST['status']           ?? 'Pendente');
    $prazo_fatal      = trim($_POST['prazo_fatal']      ?? 'Não');
    $observacoes      = trim($_POST['observacoes']      ?? '');

    if ($data_evento === '') {
        $msg  = '<div class="alert alert-danger">❌ Informe a data do compromisso.</div>';
        $acao = 'novo';
    } else {
        $cliente_id_val  = ($cliente_id === '') ? null : $cliente_id;
        $advogado_id_val = ($advogado_id === '') ? null : $advogado_id;

        try {
            $sql = "INSERT INTO agenda
                        (id, data_evento, horario, tipo_compromisso,
                         cliente_id, nome_cliente, numero_processo,
                         `local`, advogado_id, status, prazo_fatal, observacoes, deletado)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssssssssssss",
                $id,
                $data_evento,
                $horario,
                $tipo_compromisso,
                $cliente_id_val,
                $nome_cliente,
                $numero_processo,
                $local,
                $advogado_id_val,
                $status,
                $prazo_fatal,
                $observacoes
            );

            $stmt->execute();
            $stmt->close();

            echo "<script>window.location.href='?mod=agenda&msg_sucesso=Compromisso " . $id . " cadastrado com sucesso!';</script>";
            exit;
        } catch (mysqli_sql_exception $e) {
            $msg  = "<div class='alert alert-danger'>❌ Erro ao salvar: " . htmlspecialchars($e->getMessage()) . "</div>";
            $acao = 'novo';
        }
    }
}

/* -----------------------------------------------------------
   ATUALIZAR (Tratamento de NULL e Redirecionamento de Sucesso)
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_evento'])) {
    $id               = trim($_POST['id']               ?? '');
    $data_evento      = trim($_POST['data_evento']      ?? '');
    $horario          = trim($_POST['horario']          ?? '');
    $tipo_compromisso = trim($_POST['tipo_compromisso'] ?? 'Audiência');
    $cliente_id       = trim($_POST['cliente_id']       ?? '');
    $nome_cliente     = trim($_POST['nome_cliente']     ?? '');
    $numero_processo  = trim($_POST['numero_processo']  ?? '');
    $local            = trim($_POST['local']            ?? '');
    $advogado_id      = trim($_POST['advogado_id']      ?? '');
    $status           = trim($_POST['status']           ?? 'Pendente');
    $prazo_fatal      = trim($_POST['prazo_fatal']      ?? 'Não');
    $observacoes      = trim($_POST['observacoes']      ?? '');

    $cliente_id_val  = ($cliente_id === '') ? null : $cliente_id;
    $advogado_id_val = ($advogado_id === '') ? null : $advogado_id;

    try {
        $sql = "UPDATE agenda SET
                    data_evento      = ?,
                    horario          = ?,
                    tipo_compromisso = ?,
                    cliente_id       = ?,
                    nome_cliente     = ?,
                    numero_processo  = ?,
                    `local`          = ?,
                    advogado_id      = ?,
                    status           = ?,
                    prazo_fatal      = ?,
                    observacoes      = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssssssss",
            $data_evento,
            $horario,
            $tipo_compromisso,
            $cliente_id_val,
            $nome_cliente,
            $numero_processo,
            $local,
            $advogado_id_val,
            $status,
            $prazo_fatal,
            $observacoes,
            $id
        );

        $stmt->execute();
        $stmt->close();

        echo "<script>window.location.href='?mod=agenda&msg_sucesso=Compromisso " . $id . " atualizado com sucesso!';</script>";
        exit;
    } catch (mysqli_sql_exception $e) {
        $msg  = "<div class='alert alert-danger'>❌ Erro ao atualizar: " . htmlspecialchars($e->getMessage()) . "</div>";
        $acao = 'editar';
    }
}

/* -----------------------------------------------------------
   CARREGAR PARA EDIÇÃO
----------------------------------------------------------- */
$evento_editar = null;
if ($acao === 'editar' && isset($_GET['id'])) {
    $id_editar = trim($_GET['id']);

    $stmt = $conn->prepare("SELECT * FROM agenda WHERE id = ?");
    $stmt->bind_param("s", $id_editar);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $evento_editar = $res->fetch_assoc();
    } else {
        $msg  = "<div class='alert alert-danger'>Compromisso não encontrado.</div>";
        $acao = 'listar';
    }
    $stmt->close();
}

/* -----------------------------------------------------------
   DADOS PARA FORMULÁRIO
----------------------------------------------------------- */
$f = [
    'data_evento'      => $evento_editar['data_evento']      ?? '',
    'horario'          => $evento_editar['horario']          ?? '',
    'tipo_compromisso' => $evento_editar['tipo_compromisso'] ?? 'Audiência',
    'cliente_id'       => $evento_editar['cliente_id']       ?? '',
    'nome_cliente'     => $evento_editar['nome_cliente']     ?? '',
    'numero_processo'  => $evento_editar['numero_processo']  ?? '',
    'local'            => $evento_editar['local']            ?? '',
    'advogado_id'      => $evento_editar['advogado_id']      ?? '',
    'status'           => $evento_editar['status']           ?? 'Pendente',
    'prazo_fatal'      => $evento_editar['prazo_fatal']      ?? 'Não',
    'observacoes'      => $evento_editar['observacoes']      ?? '',
];

$tipos    = ['Audiência','Reunião','Prazo','Atendimento','Lembrete','Outro'];
$statuses = ['Pendente','Confirmado','Realizado','Cancelado'];
$simnao   = ['Não','Sim'];

// Clientes e advogados para selects
$clientes  = $conn->query("SELECT id, nome FROM clientes ORDER BY nome");
$advogados = $conn->query("SELECT id, nome FROM advogados WHERE status='Ativo' ORDER BY nome");

// Processos agrupados por cliente (PHP puro, sem AJAX)
$processos_por_cliente = [];
$resProc = $conn->query("
    SELECT DISTINCT cliente_id, numero_processo
    FROM processos
    WHERE cliente_id <> '' AND numero_processo <> ''
    ORDER BY cliente_id, numero_processo
");
if ($resProc) {
    while ($p = $resProc->fetch_assoc()) {
        $cid = $p['cliente_id'];
        if (!isset($processos_por_cliente[$cid])) {
            $processos_por_cliente[$cid] = [];
        }
        $processos_por_cliente[$cid][] = $p['numero_processo'];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calendar-event"></i> Agenda <?= $acao === 'lixeira' ? '<span class="text-danger">(Lixeira)</span>' : '' ?></h2>
    <div class="d-flex gap-2">
        <?php if ($acao === 'lixeira'): ?>
            <a href="?mod=agenda&acao=listar" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left"></i> Voltar à Listagem
            </a>
        <?php else: ?>
            <a href="?mod=agenda&acao=lixeira" class="btn btn-outline-danger">
                <i class="bi bi-trash"></i> Ver Lixeira
            </a>
            <a href="?mod=agenda&acao=novo" class="btn btn-primary">
                <i class="bi bi-plus"></i> Novo Compromisso
            </a>
        <?php endif; ?>
    </div>
</div>

<?= $msg ?>

<script>
const processosPorCliente = <?= json_encode($processos_por_cliente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function preencherNomeCliente(select) {
    const opt       = select.options[select.selectedIndex];
    const clienteId = select.value;
    const inputNome = document.getElementById('nome_cliente');

    if (inputNome) {
        inputNome.value = (opt && opt.dataset && opt.dataset.nome) ? opt.dataset.nome : '';
    }

    // Preenche processos
    const selProc = document.getElementById('numero_processo');
    if (!selProc) return;

    const processoAtual = selProc.dataset.selected || '';
    selProc.innerHTML   = '';

    if (!clienteId || !processosPorCliente[clienteId] || processosPorCliente[clienteId].length === 0) {
        const opt0 = document.createElement('option');
        opt0.value       = '';
        opt0.textContent = 'Nenhum processo encontrado';
        selProc.appendChild(opt0);
        return;
    }

    // Opção vazia padrão
    const optVazio = document.createElement('option');
    optVazio.value       = '';
    optVazio.textContent = '-- Selecione --';
    selProc.appendChild(optVazio);

    processosPorCliente[clienteId].forEach(function(numero) {
        const o       = document.createElement('option');
        o.value       = numero;
        o.textContent = numero;
        if (numero === processoAtual) o.selected = true;
        selProc.appendChild(o);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const sel = document.querySelector('select[name="cliente_id"]');
    if (sel && sel.value) preencherNomeCliente(sel);
});
</script>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>

<div class="card mb-4">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
        <?= $acao === 'editar'
            ? '✏️ Editar Compromisso — ' . htmlspecialchars($evento_editar['id'] ?? '')
            : '📅 Cadastrar Novo Compromisso' ?>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off">

            <?php if ($acao === 'editar'): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($evento_editar['id'] ?? '') ?>">
            <?php endif; ?>

            <div class="row g-3">

                <div class="col-md-2">
                    <label class="form-label">Data *</label>
                    <input type="date" name="data_evento" class="form-control"
                           value="<?= htmlspecialchars($f['data_evento']) ?>" required>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Horário</label>
                    <input type="time" name="horario" class="form-control"
                           value="<?= htmlspecialchars($f['horario']) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tipo Compromisso</label>
                    <select name="tipo_compromisso" class="form-select">
                        <?php foreach ($tipos as $tp): ?>
                            <option value="<?= $tp ?>"
                                <?= $f['tipo_compromisso'] === $tp ? 'selected' : '' ?>>
                                <?= $tp ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?= $st ?>"
                                <?= $f['status'] === $st ? 'selected' : '' ?>>
                                <?= $st ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Prazo Fatal</label>
                    <select name="prazo_fatal" class="form-select">
                        <?php foreach ($simnao as $op): ?>
                            <option value="<?= $op ?>"
                                <?= $f['prazo_fatal'] === $op ? 'selected' : '' ?>>
                                <?= $op ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">ID Cliente</label>
                    <select name="cliente_id" class="form-select"
                            onchange="preencherNomeCliente(this)">
                        <option value="">-- Selecione --</option>
                        <?php if ($clientes): while ($c = $clientes->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>"
                                    data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>"
                                    <?= $f['cliente_id'] === $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['id']) ?> — <?= htmlspecialchars($c['nome']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Nome Cliente</label>
                    <input type="text" id="nome_cliente" name="nome_cliente"
                           class="form-control"
                           list="clientes_list"
                           value="<?= htmlspecialchars($f['nome_cliente']) ?>"
                           placeholder="Digite nome ou selecione...">
                    <datalist id="clientes_list">
                        <?php
                            $clientes_list = $conn->query("SELECT DISTINCT nome FROM clientes ORDER BY nome ASC");
                            if ($clientes_list):
                                while ($c = $clientes_list->fetch_assoc()):
                        ?>
                            <option value="<?= htmlspecialchars($c['nome']) ?>">
                        <?php endwhile; endif; ?>
                    </datalist>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Nº Processo</label>
                    <select id="numero_processo" name="numero_processo" class="form-select"
                            data-selected="<?= htmlspecialchars($f['numero_processo']) ?>">
                        <option value="">-- Selecione o cliente primeiro --</option>
                        <?php if (!empty($f['numero_processo'])): ?>
                            <option value="<?= htmlspecialchars($f['numero_processo']) ?>" selected>
                                <?= htmlspecialchars($f['numero_processo']) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Local</label>
                    <input type="text" name="local" class="form-control"
                           value="<?= htmlspecialchars($f['local']) ?>"
                           placeholder="Ex.: 2ª Vara Cível, Fórum Central...">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Advogado Responsável</label>
                    <select name="advogado_id" class="form-select">
                        <option value="">-- Selecione --</option>
                        <?php if ($advogados): while ($a = $advogados->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($a['id']) ?>"
                                <?= $f['advogado_id'] === $a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['nome']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control"
                              rows="3"><?= htmlspecialchars($f['observacoes']) ?></textarea>
                </div>

            </div><div class="mt-4 d-flex gap-2">
                <?php if ($acao === 'editar'): ?>
                    <button type="submit" name="atualizar_evento" class="btn btn-warning">
                        💾 Salvar Alterações
                    </button>
                <?php else: ?>
                    <button type="submit" name="salvar_evento" class="btn btn-success">
                        💾 Salvar Compromisso
                    </button>
                <?php endif; ?>
                <a href="?mod=agenda" class="btn btn-secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>

<?php else: ?>

<?php
$busca = trim($_GET['busca'] ?? '');
$filtro_deletado = ($acao === 'lixeira') ? 1 : 0;

if ($busca !== '') {
    $likeBusca = "%$busca%";
    $sqlLista = "SELECT
                    a.id,
                    a.data_evento,
                    a.horario,
                    a.tipo_compromisso,
                    a.cliente_id,
                    a.nome_cliente,
                    a.numero_processo,
                    a.`local`,
                    adv.nome AS advogado_nome,
                    a.status,
                    a.prazo_fatal
                FROM agenda a
                LEFT JOIN advogados adv ON adv.id = a.advogado_id
                WHERE
                    a.deletado = ? AND (
                        a.nome_cliente     LIKE ? OR
                        a.tipo_compromisso LIKE ? OR
                        a.numero_processo  LIKE ? OR
                        a.status           LIKE ? OR
                        a.prazo_fatal      LIKE ?
                    )
                ORDER BY a.data_evento ASC, a.horario ASC
                LIMIT 200";

    $stmtLista = $conn->prepare($sqlLista);
    $stmtLista->bind_param("isssss", $filtro_deletado, $likeBusca, $likeBusca, $likeBusca, $likeBusca, $likeBusca);
    $stmtLista->execute();
    $lista = $stmtLista->get_result();
} else {
    $sqlLista = "SELECT
                    a.id,
                    a.data_evento,
                    a.horario,
                    a.tipo_compromisso,
                    a.cliente_id,
                    a.nome_cliente,
                    a.numero_processo,
                    a.`local`,
                    adv.nome AS advogado_nome,
                    a.status,
                    a.prazo_fatal
                FROM agenda a
                LEFT JOIN advogados adv ON adv.id = a.advogado_id
                WHERE a.deletado = ?
                ORDER BY a.data_evento ASC, a.horario ASC
                LIMIT 200";
    $stmtLista = $conn->prepare($sqlLista);
    $stmtLista->bind_param("i", $filtro_deletado);
    $stmtLista->execute();
    $lista = $stmtLista->get_result();
}
?>

<form class="d-flex gap-2 mb-3" method="GET">
    <input type="hidden" name="mod" value="agenda">
    <input type="hidden" name="acao" value="<?= htmlspecialchars($acao) ?>">
    <input type="text" name="busca" class="form-control"
           placeholder="Buscar por cliente, tipo, processo ou status..."
           value="<?= htmlspecialchars($busca) ?>">
    <button class="btn btn-outline-primary" type="submit">
        <i class="bi bi-search"></i>
    </button>
    <?php if ($busca): ?>
        <a href="?mod=agenda&acao=<?= htmlspecialchars($acao) ?>" class="btn btn-outline-secondary">Limpar</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID Evento</th>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Tipo</th>
                        <th>Cliente</th>
                        <th>Nº Processo</th>
                        <th>Local</th>
                        <th>Advogado</th>
                        <th>Status</th>
                        <th>Prazo Final</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($lista && $lista->num_rows > 0): ?>
                    <?php while ($row = $lista->fetch_assoc()):
                        $badge = match($row['status']) {
                            'Confirmado' => 'primary',
                            'Realizado'  => 'success',
                            'Cancelado'  => 'secondary',
                            default      => 'warning'
                        };
                        $fatal = $row['prazo_fatal'] === 'Sim'
                            ? '<span class="badge bg-danger">Sim</span>'
                            : '<span class="badge bg-light text-dark border">Não</span>';
                    ?>
                    <tr <?= $row['prazo_fatal'] === 'Sim' ? 'class="table-danger"' : '' ?>>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td>
                            <?= !empty($row['data_evento'])
                                ? date('d/m/Y', strtotime($row['data_evento']))
                                : '-' ?>
                        </td>
                        <td><?= htmlspecialchars($row['horario']) ?></td>
                        <td><?= htmlspecialchars($row['tipo_compromisso']) ?></td>
                        <td><?= htmlspecialchars($row['nome_cliente'] ?? $row['cliente_id'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['numero_processo'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['local'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['advogado_nome'] ?? '-') ?></td>
                        <td>
                            <span class="badge bg-<?= $badge ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                        <td><?= $fatal ?></td>
                        <td class="text-nowrap">
                            <?php if ($acao === 'lixeira'): ?>
                                <a href="?mod=agenda&restaurar=<?= urlencode($row['id']) ?>"
                                   class="btn btn-sm btn-outline-success" title="Restaurar">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </a>
                                <a href="?mod=agenda&excluir_permanente=<?= urlencode($row['id']) ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('ATENÇÃO: Excluir PERMANENTEMENTE este compromisso? Esta ação não pode ser desfeita.')"
                                   title="Excluir do Banco">
                                    <i class="bi bi-fire"></i>
                                </a>
                            <?php else: ?>
                                <a href="?mod=agenda&acao=editar&id=<?= urlencode($row['id']) ?>"
                                   class="btn btn-sm btn-warning">✏️ Editar</a>
                                <a href="?mod=agenda&excluir=<?= urlencode($row['id']) ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Deseja mover este compromisso para a lixeira?')">🗑️</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">
                            <?= $acao === 'lixeira' ? 'A lixeira está vazia.' : 'Nenhum compromisso encontrado.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
if (isset($stmtLista)) { $stmtLista->close(); }
endif;
?>

<?php $conn->close(); ?>