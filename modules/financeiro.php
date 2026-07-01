<?php
// C:\xampp\htdocs\sistema_sgl\modules\financeiro.php

$conn = conectar();

$aba  = $_GET['aba'] ?? 'cp';     // cp = contas a pagar, cr = receber
$acao = $_GET['acao'] ?? 'listar'; // listar | novo_cp | editar_cp | novo_cr | editar_cr | lixeira
$msg  = '';

/* ======================================================
   FUNÇÕES AUXILIARES GERAIS
   ====================================================== */

function gerarIdFin(mysqli $conn, string $prefixo): string
{
    $tabela = $prefixo === 'CP' ? 'contas_pagar' : 'contas_receber';

    $sql = "SELECT id 
            FROM {$tabela} 
            WHERE id LIKE '{$prefixo}%' 
            ORDER BY CAST(SUBSTRING(id, " . (strlen($prefixo) + 1) . ") AS UNSIGNED) DESC 
            LIMIT 1";
    $res = $conn->query($sql);

    if (!$res || $res->num_rows === 0) {
        return $prefixo . '001';
    }

    $ultimo = $res->fetch_assoc()['id'] ?? '';
    if ($ultimo === '') {
        return $prefixo . '001';
    }

    $num = (int) substr($ultimo, strlen($prefixo)) + 1;
    return $prefixo . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

function brlParaFloatFin(string $valor): float
{
    $v = trim($valor);
    if ($v === '') return 0.0;

    $v = str_replace(['R$', ' '], '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function fmtBrlFin($v): string
{
    if ($v === '' || $v === null) return 'R$ 0,00';
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function sqlText(mysqli $conn, string $valor): string
{
    return "'" . $conn->real_escape_string(trim($valor)) . "'";
}

function sqlNullableText(mysqli $conn, string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') return 'NULL';
    return "'" . $conn->real_escape_string($valor) . "'";
}

function sqlNullableDate(mysqli $conn, string $valor): string
{
    $valor = trim($valor);
    if ($valor === '' || $valor === '0000-00-00') return 'NULL';
    return "'" . $conn->real_escape_string($valor) . "'";
}

function sqlMoney(float $valor): string
{
    return number_format($valor, 2, '.', '');
}

function badgeClasseFin(string $status, string $tipo): string
{
    $status = trim($status);

    if ($tipo === 'cp') {          // Contas a Pagar
        return match ($status) {
            'Pago'      => 'success',
            'Parcial'   => 'warning text-dark',
            'Cancelado' => 'secondary',
            default     => 'danger', // Pendente
        };
    }

    if ($tipo === 'cr') {          // Contas a Receber
        return match ($status) {
            'Recebido'  => 'success',
            'Parcial'   => 'warning text-dark',
            'Cancelado' => 'secondary',
            default     => 'danger',
        };
    }

    if ($tipo === 'hp') {          // Parcelas Honorários
        return match ($status) {
            'Pago'      => 'success',
            'Parcial'   => 'warning text-dark',
            'Cancelado' => 'secondary',
            default     => 'danger',
        };
    }

    return 'secondary';
}

/* ========= PARCELAS – CONTAS A PAGAR ========= */

function gerarIdParcelaCP(mysqli $conn): string
{
    $res = $conn->query("SELECT id FROM contas_pagar_parcelas ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'CPA001';
    $ultimo = $res->fetch_assoc()['id'];
    $num    = (int) substr($ultimo, 3) + 1;
    return 'CPA' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function statusVisualParcelaFin($valor_parcela, $valor_pago): array
{
    $valor_parcela = (float)$valor_parcela;
    $valor_pago    = (float)$valor_pago;

    if ($valor_pago <= 0.001) {
        return ['label' => 'Devedor', 'badge' => 'bg-danger', 'dot' => '🔴'];
    }
    if ($valor_pago < $valor_parcela - 0.01) {
        return ['label' => 'Parcial', 'badge' => 'bg-warning text-dark', 'dot' => '🟡'];
    }
    return ['label' => 'Quitada', 'badge' => 'bg-success', 'dot' => '🟢'];
}

function gerarParcelasCP(mysqli $conn, array $conta, bool $gerar30dias = true): void
{
    $conta_id        = $conta['id'];
    $qtd_parcelas    = max(1, (int)$conta['qtd_parcelas']);
    $valor_total     = (float)$conta['valor'];
    $data_vencimento = $conta['data_vencimento']; // Y-m-d
    $forma_pagamento = $conta['forma_pagamento'];
    $observacoes     = $conta['observacoes'] ?? '';

    // apaga parcelas anteriores
    $conn->query("DELETE FROM contas_pagar_parcelas WHERE conta_id = '" . $conn->real_escape_string($conta_id) . "'");

    $valor_parcela_base = round($valor_total / $qtd_parcelas, 2);
    $data_atual         = new DateTime($data_vencimento);

    for ($i = 1; $i <= $qtd_parcelas; $i++) {
        $id_parcela    = gerarIdParcelaCP($conn);
        $valor_parcela = $valor_parcela_base;

        if ($i === $qtd_parcelas) {
            $valor_parcela = $valor_total - (($qtd_parcelas - 1) * $valor_parcela_base);
            $valor_parcela = round($valor_parcela, 2);
        }

        $data_venc_parcela = $data_atual->format('Y-m-d');

        $status_parcela    = 'Pendente';
        $valor_pago_par    = 0.00;
        $saldo_devedor_par = $valor_parcela;

        $stmt = $conn->prepare("INSERT INTO contas_pagar_parcelas
            (id, conta_id, parcela_numero, valor_parcela, data_vencimento, forma_pagamento,
             status_pagamento, valor_pago, saldo_devedor, observacoes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "ssidssssds",
            $id_parcela,
            $conta_id,
            $i,
            $valor_parcela,
            $data_venc_parcela,
            $forma_pagamento,
            $status_parcela,
            $valor_pago_par,
            $saldo_devedor_par,
            $observacoes
        );
        $stmt->execute();
        $stmt->close();

        if ($gerar30dias) {
            $data_atual->modify('+30 days');
        } else {
            break;
        }
    }
}

/** Busca parcelas de uma conta CP */
function getParcelasCP(mysqli $conn, string $conta_id): array
{
    $parcelas = [];
    $conta_id = $conn->real_escape_string($conta_id);
    $res = $conn->query("SELECT * FROM contas_pagar_parcelas WHERE conta_id = '$conta_id' ORDER BY parcela_numero ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $parcelas[] = $row;
        }
    }
    return $parcelas;
}

/** Recalcula conta_pagar a partir das parcelas */
function recalcContaPagar(mysqli $conn, string $conta_id): void
{
    $conta_id = $conn->real_escape_string($conta_id);

    $res = $conn->query("
        SELECT
            COALESCE(SUM(valor_parcela), 0) AS total_parcelas,
            COALESCE(SUM(valor_pago), 0)    AS total_pago,
            COALESCE(SUM(saldo_devedor), 0) AS total_saldo
        FROM contas_pagar_parcelas
        WHERE conta_id = '$conta_id'
    ");

    if (!$res) return;
    $tot = $res->fetch_assoc();

    $total_pago  = (float)($tot['total_pago']  ?? 0);
    $total_saldo = (float)($tot['total_saldo'] ?? 0);

    $status = 'Pendente';
    if ($total_saldo <= 0.01) {
        $status = 'Pago';
    } elseif ($total_pago > 0) {
        $status = 'Parcial';
    }

    $conn->query("
        UPDATE contas_pagar SET
            valor_pago     = " . sqlMoney($total_pago) . ",
            valor_pendente = " . sqlMoney($total_saldo) . ",
            status         = " . sqlText($conn, $status) . "
        WHERE id = " . sqlText($conn, $conta_id) . "
    ");
}

/* ======================================================
   AJUSTE SGL: LIXEIRA SEGURA — CONTAS A PAGAR / RECEBER
   (mesmo padrão usado em honorarios.php)
   ====================================================== */

if (isset($_GET['excluir']) && isset($_GET['tipo'])) {
    $tipo = $_GET['tipo'];
    $id   = $conn->real_escape_string($_GET['excluir']);

    if ($tipo === 'cp') {
        $conn->query("UPDATE contas_pagar SET deletado = 1 WHERE id = '$id'");
        $msg = '<div class="alert alert-warning">🗑️ Conta a Pagar movida para a lixeira com sucesso.</div>';
    } elseif ($tipo === 'cr') {
        $conn->query("UPDATE contas_receber SET deletado = 1 WHERE id = '$id'");
        $msg = '<div class="alert alert-warning">🗑️ Conta a Receber movida para a lixeira com sucesso.</div>';
    }

    $aba  = $tipo;
    $acao = 'listar';
}

if (isset($_GET['restaurar']) && isset($_GET['tipo'])) {
    $tipo = $_GET['tipo'];
    $id   = $conn->real_escape_string($_GET['restaurar']);

    if ($tipo === 'cp') {
        $conn->query("UPDATE contas_pagar SET deletado = 0 WHERE id = '$id'");
        $msg = '<div class="alert alert-success">✅ Conta a Pagar restaurada com sucesso!</div>';
    } elseif ($tipo === 'cr') {
        $conn->query("UPDATE contas_receber SET deletado = 0 WHERE id = '$id'");
        $msg = '<div class="alert alert-success">✅ Conta a Receber restaurada com sucesso!</div>';
    }

    $aba  = $tipo;
    $acao = 'listar';
}

if (isset($_GET['excluir_permanente']) && isset($_GET['tipo'])) {
    $tipo = $_GET['tipo'];
    $id   = $conn->real_escape_string($_GET['excluir_permanente']);

    if ($tipo === 'cp') {
        $conn->query("DELETE FROM contas_pagar_parcelas WHERE conta_id = '$id'");
        $conn->query("DELETE FROM contas_pagar WHERE id = '$id'");
        $msg = '<div class="alert alert-danger">💥 Conta a Pagar e suas parcelas foram excluídas permanentemente.</div>';
    } elseif ($tipo === 'cr') {
        // Remove também eventuais parcelas de CR, caso existam para este registro
        $conn->query("DELETE FROM contas_receber_parcelas WHERE conta_id = '$id'");
        $conn->query("DELETE FROM contas_receber WHERE id = '$id'");
        $msg = '<div class="alert alert-danger">💥 Conta a Receber excluída permanentemente.</div>';
    }

    $aba  = $tipo;
    $acao = 'lixeira';
}

/* ======================================================
   FORMULÁRIOS – CONTAS A PAGAR (COM PARCELAS)
   ====================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_cp'])) {
    $id   = $_POST['id'] ?? ''; // se vier vazio, é novo
    $novo = $id === '';
    if ($novo) {
        $id = gerarIdFin($conn, 'CP');
    }

    $descricao       = trim($_POST['descricao'] ?? '');
    $categoria       = trim($_POST['categoria'] ?? '');
    $fornecedor      = trim($_POST['fornecedor'] ?? '');
    $valor           = brlParaFloatFin($_POST['valor'] ?? '0');
    $qtd_parcelas    = (int)($_POST['qtd_parcelas'] ?? 1);
    if ($qtd_parcelas < 1) $qtd_parcelas = 1;

    $data_vencimento = trim($_POST['data_vencimento'] ?? '');
    $data_pagamento  = trim($_POST['data_pagamento'] ?? '');
    $status_input    = trim($_POST['status'] ?? 'Pendente');
    $forma_pagamento = trim($_POST['forma_pagamento'] ?? '');
    $mes_referencia  = trim($_POST['mes_referencia'] ?? '');
    $observacoes     = trim($_POST['observacoes'] ?? '');
    $gerar30dias     = isset($_POST['gerar_30dias']);

    // A conta principal é "espelho" das parcelas:
    // valor_pago / pendente são recalculados depois com base nas parcelas.
    $valor_pago   = 0.00;
    $valor_pend   = $valor;
    $status_final = 'Pendente';

    if ($status_input === 'Cancelado') {
        $status_final = 'Cancelado';
    }

    if ($novo) {
        $sql = "INSERT INTO contas_pagar
            (id, descricao, categoria, fornecedor, valor, data_vencimento, data_pagamento,
             forma_pagamento, status, mes_referencia, observacoes, valor_pago, valor_pendente, deletado)
            VALUES (
                " . sqlText($conn, $id) . ",
                " . sqlNullableText($conn, $descricao) . ",
                " . sqlNullableText($conn, $categoria) . ",
                " . sqlNullableText($conn, $fornecedor) . ",
                " . sqlMoney($valor) . ",
                " . sqlNullableDate($conn, $data_vencimento) . ",
                " . sqlNullableDate($conn, $data_pagamento) . ",
                " . sqlNullableText($conn, $forma_pagamento) . ",
                " . sqlText($conn, $status_final) . ",
                " . sqlNullableText($conn, $mes_referencia) . ",
                " . sqlNullableText($conn, $observacoes) . ",
                " . sqlMoney($valor_pago) . ",
                " . sqlMoney($valor_pend) . ",
                0
            )";
    } else {
        $sql = "UPDATE contas_pagar SET
                descricao       = " . sqlNullableText($conn, $descricao) . ",
                categoria       = " . sqlNullableText($conn, $categoria) . ",
                fornecedor      = " . sqlNullableText($conn, $fornecedor) . ",
                valor           = " . sqlMoney($valor) . ",
                data_vencimento = " . sqlNullableDate($conn, $data_vencimento) . ",
                data_pagamento  = " . sqlNullableDate($conn, $data_pagamento) . ",
                forma_pagamento = " . sqlNullableText($conn, $forma_pagamento) . ",
                status          = " . sqlText($conn, $status_final) . ",
                mes_referencia  = " . sqlNullableText($conn, $mes_referencia) . ",
                observacoes     = " . sqlNullableText($conn, $observacoes) . "
            WHERE id = " . sqlText($conn, $id);
    }

    if ($conn->query($sql)) {

        // gera / regenera parcelas
        $contaData = [
            'id'              => $id,
            'qtd_parcelas'    => $qtd_parcelas,
            'valor'           => $valor,
            'data_vencimento' => $data_vencimento,
            'forma_pagamento' => $forma_pagamento,
            'observacoes'     => $observacoes,
        ];
        gerarParcelasCP($conn, $contaData, $gerar30dias);

        // recalcula conta com base nas parcelas (tudo pendente inicialmente)
        recalcContaPagar($conn, $id);

        $msg  = "<div class='alert alert-success'>✅ Conta a Pagar <strong>{$id}</strong> salva com parcelas.</div>";
        $acao = 'listar';
        $aba  = 'cp';
    } else {
        $msg  = "<div class='alert alert-danger'>Erro ao salvar: " . htmlspecialchars($conn->error) . "</div>";
        $acao = $novo ? 'novo_cp' : 'editar_cp';
        $aba  = 'cp';
    }
}

/* Atualização de uma parcela específica de CP (pago/saldo) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_parcela_cp'])) {
    $parcela_id    = $_POST['parcela_id'] ?? '';
    $conta_id      = $_POST['conta_id'] ?? '';
    $valor_pago_in = brlParaFloatFin($_POST['valor_pago_parcela'] ?? '0');

    $parcela_id = $conn->real_escape_string($parcela_id);

    $res = $conn->query("SELECT valor_parcela FROM contas_pagar_parcelas WHERE id = '$parcela_id' LIMIT 1");
    if ($res && $res->num_rows) {
        $row           = $res->fetch_assoc();
        $valor_parcela = (float)$row['valor_parcela'];

        $vp    = max(0.0, min($valor_parcela, $valor_pago_in));
        $saldo = max(0.0, $valor_parcela - $vp);

        $status = 'Pendente';
        if ($saldo <= 0.01 && $vp > 0) {
            $status = 'Pago';
        } elseif ($vp > 0 && $saldo > 0.01) {
            $status = 'Parcial';
        }

        $conn->query("
            UPDATE contas_pagar_parcelas SET
                valor_pago       = " . sqlMoney($vp) . ",
                saldo_devedor    = " . sqlMoney($saldo) . ",
                status_pagamento = " . sqlText($conn, $status) . "
            WHERE id = '$parcela_id'
        ");

        if ($conta_id !== '') {
            recalcContaPagar($conn, $conta_id);
        }

        $msg        = "<div class='alert alert-success'>✅ Parcela atualizada.</div>";
        $aba        = 'cp';
        $acao       = 'editar_cp';
        $_GET['id'] = $conta_id;
    }
}

/* ======================================================
   FORMULÁRIO – CONTAS A RECEBER (SIMPLES, SEM PARCELAS)
   ====================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_cr'])) {
    $id   = $_POST['id'] ?? '';
    $novo = $id === '';
    if ($novo) {
        $id = gerarIdFin($conn, 'CR');
    }

    $descricao        = trim($_POST['descricao'] ?? '');
    $valor             = brlParaFloatFin($_POST['valor'] ?? '0');
    $data_vencimento   = trim($_POST['data_vencimento'] ?? '');
    $data_recebimento  = trim($_POST['data_recebimento'] ?? '');
    $status            = trim($_POST['status'] ?? 'Pendente');
    $observacoes       = trim($_POST['observacoes'] ?? '');

    if ($novo) {
        $sql = "INSERT INTO contas_receber
            (id, descricao, valor, data_vencimento, data_recebimento, status, observacoes, deletado)
            VALUES (
                " . sqlText($conn, $id) . ",
                " . sqlNullableText($conn, $descricao) . ",
                " . sqlMoney($valor) . ",
                " . sqlNullableDate($conn, $data_vencimento) . ",
                " . sqlNullableDate($conn, $data_recebimento) . ",
                " . sqlText($conn, $status) . ",
                " . sqlNullableText($conn, $observacoes) . ",
                0
            )";
    } else {
        $sql = "UPDATE contas_receber SET
                descricao        = " . sqlNullableText($conn, $descricao) . ",
                valor            = " . sqlMoney($valor) . ",
                data_vencimento  = " . sqlNullableDate($conn, $data_vencimento) . ",
                data_recebimento = " . sqlNullableDate($conn, $data_recebimento) . ",
                status           = " . sqlText($conn, $status) . ",
                observacoes      = " . sqlNullableText($conn, $observacoes) . "
            WHERE id = " . sqlText($conn, $id);
    }

    if ($conn->query($sql)) {
        $msg  = "<div class='alert alert-success'>✅ Conta a Receber <strong>{$id}</strong> salva.</div>";
        $acao = 'listar';
        $aba  = 'cr';
    } else {
        $msg  = "<div class='alert alert-danger'>Erro ao salvar: " . htmlspecialchars($conn->error) . "</div>";
        $acao = $novo ? 'novo_cr' : 'editar_cr';
        $aba  = 'cr';
    }
}

/* ======================================================
   LISTAGENS – CONTAS A PAGAR / RECEBER (ATIVOS x LIXEIRA)
   ====================================================== */

$filtro_deletado_cp = ($aba === 'cp' && $acao === 'lixeira') ? 1 : 0;
$filtro_deletado_cr = ($aba === 'cr' && $acao === 'lixeira') ? 1 : 0;

$lista_cp = $conn->query("SELECT * FROM contas_pagar WHERE deletado = $filtro_deletado_cp ORDER BY data_vencimento DESC, id DESC");
$lista_cr = $conn->query("SELECT * FROM contas_receber WHERE deletado = $filtro_deletado_cr ORDER BY data_vencimento DESC, id DESC");

?>
<div class="container-fluid">
    <h2 class="mb-3">💰 Financeiro</h2>

    <?= $msg ?>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $aba === 'cp' ? 'active' : '' ?>" href="?mod=financeiro&aba=cp">Contas a Pagar</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $aba === 'cr' ? 'active' : '' ?>" href="?mod=financeiro&aba=cr">Contas a Receber</a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ================= CONTAS A PAGAR ================= -->
        <div class="tab-pane fade <?= $aba === 'cp' ? 'show active' : '' ?>" id="cp-pane">
            <?php if ($aba === 'cp' && ($acao === 'listar' || $acao === 'lixeira')): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="text-muted small"><?= ($lista_cp && $lista_cp->num_rows) ? $lista_cp->num_rows . ' registro(s) encontrado(s)' : '' ?></span>
                        <?php if ($acao === 'lixeira'): ?>
                            <span class="badge bg-danger ms-2">Lixeira</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($acao === 'lixeira'): ?>
                            <a href="?mod=financeiro&aba=cp&acao=listar" class="btn btn-outline-primary no-print">
                                <i class="bi bi-arrow-left"></i> Voltar à Listagem
                            </a>
                        <?php else: ?>
                            <button onclick="imprimirRelatorio('cp')" class="btn btn-outline-secondary no-print">
                                <i class="bi bi-printer"></i> Imprimir / Salvar PDF
                            </button>
                            <a href="?mod=financeiro&aba=cp&acao=lixeira" class="btn btn-outline-danger no-print">
                                <i class="bi bi-trash"></i> Ver Lixeira
                            </a>
                            <a href="?mod=financeiro&aba=cp&acao=novo_cp" class="btn btn-primary no-print">+ Nova Conta a Pagar</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Descrição</th>
                                        <th>Categoria</th>
                                        <th>Fornecedor</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Pago</th>
                                        <th>Saldo</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($lista_cp && $lista_cp->num_rows): ?>
                                    <?php while ($row = $lista_cp->fetch_assoc()): ?>
                                        <?php $badge = badgeClasseFin((string)$row['status'], 'cp'); ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['id']) ?></td>
                                            <td><?= htmlspecialchars($row['descricao'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['categoria'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['fornecedor'] ?? '-') ?></td>
                                            <td><?= fmtBrlFin($row['valor'] ?? 0) ?></td>
                                            <td><?= !empty($row['data_vencimento']) ? date('d/m/Y', strtotime($row['data_vencimento'])) : '-' ?></td>
                                            <td><?= fmtBrlFin($row['valor_pago'] ?? 0) ?></td>
                                            <td class="text-danger fw-bold"><?= fmtBrlFin($row['valor_pendente'] ?? 0) ?></td>
                                            <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($row['status'] ?? '-') ?></span></td>
                                            <td class="text-nowrap">
                                                <?php if ($acao === 'lixeira'): ?>
                                                    <a href="?mod=financeiro&aba=cp&restaurar=<?= urlencode($row['id']) ?>&tipo=cp"
                                                       class="btn btn-sm btn-outline-success" title="Restaurar">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </a>
                                                    <a href="?mod=financeiro&aba=cp&excluir_permanente=<?= urlencode($row['id']) ?>&tipo=cp"
                                                       class="btn btn-sm btn-danger"
                                                       onclick="return confirm('ATENÇÃO: Deseja mesmo excluir PERMANENTEMENTE a conta <?= htmlspecialchars($row['id']) ?> e suas parcelas? Esta ação não pode ser desfeita.')"
                                                       title="Excluir Permanentemente">
                                                        <i class="bi bi-fire"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?mod=financeiro&aba=cp&acao=editar_cp&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning" title="Editar">✏️</a>
                                                    <a href="?mod=financeiro&aba=cp&excluir=<?= urlencode($row['id']) ?>&tipo=cp"
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="return confirm('Mover a conta <?= htmlspecialchars($row['id']) ?> para a lixeira?')"
                                                       title="Mover para Lixeira">🗑️</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">
                                            <?= $acao === 'lixeira' ? 'Nenhuma conta a pagar na lixeira.' : 'Nenhuma conta a pagar cadastrada.' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($aba === 'cp' && ($acao === 'novo_cp' || $acao === 'editar_cp')): ?>

                <?php
                $conta_cp = [
                    'id'              => '',
                    'descricao'       => '',
                    'categoria'       => '',
                    'fornecedor'      => '',
                    'valor'           => 0,
                    'data_vencimento' => '',
                    'data_pagamento'  => '',
                    'forma_pagamento' => '',
                    'status'          => 'Pendente',
                    'mes_referencia'  => '',
                    'observacoes'     => '',
                ];
                $qtd_parcelas = 1;

                if ($acao === 'editar_cp' && isset($_GET['id'])) {
                    $id_edit = $conn->real_escape_string($_GET['id']);
                    $res_cp  = $conn->query("SELECT * FROM contas_pagar WHERE id = '$id_edit' LIMIT 1");
                    if ($res_cp && $res_cp->num_rows) {
                        $conta_cp = $res_cp->fetch_assoc();
                    }
                    // tenta inferir qtd_parcelas pelas parcelas já geradas
                    $pars = getParcelasCP($conn, $conta_cp['id']);
                    if ($pars) {
                        $qtd_parcelas = count($pars);
                    }
                }
                ?>

                <div class="card">
                    <div class="card-header bg-warning">
                        <?= $conta_cp['id'] ? "✏️ Editar Conta a Pagar — " . htmlspecialchars($conta_cp['id']) : "+ Nova Conta a Pagar" ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="salvar_cp" value="1">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($conta_cp['id']) ?>">

                            <div class="col-md-6">
                                <label class="form-label">Descrição</label>
                                <input type="text" name="descricao" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['descricao'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Categoria</label>
                                <input type="text" name="categoria" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['categoria'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fornecedor</label>
                                <input type="text" name="fornecedor" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['fornecedor'] ?? '') ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Valor Total *</label>
                                <input type="text" name="valor" id="valor_total_cp" class="form-control"
                                       value="<?= fmtBrlFin($conta_cp['valor'] ?? 0) ?>"
                                       oninput="aplicarMascaraMoedaFin(this); atualizarPreviewParcelasCP();">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Qtd Parcelas</label>
                                <input type="number" name="qtd_parcelas" id="qtd_parcelas_cp" class="form-control"
                                       min="1" value="<?= (int)$qtd_parcelas ?>"
                                       oninput="atualizarPreviewParcelasCP();">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Data Vencimento (1ª Parcela)</label>
                                <input type="date" name="data_vencimento" id="data_vencimento_cp" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['data_vencimento'] ?? '') ?>"
                                       onchange="atualizarPreviewParcelasCP();">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Forma Pagamento</label>
                                <input type="text" name="forma_pagamento" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['forma_pagamento'] ?? '') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (['Pendente','Parcial','Pago','Cancelado'] as $st): ?>
                                        <option value="<?= $st ?>" <?= ($conta_cp['status'] ?? 'Pendente') === $st ? 'selected' : '' ?>>
                                            <?= $st ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Data Pagamento</label>
                                <input type="date" name="data_pagamento" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['data_pagamento'] ?? '') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Mês Referência</label>
                                <input type="month" name="mes_referencia" class="form-control"
                                       value="<?= htmlspecialchars($conta_cp['mes_referencia'] ?? '') ?>">
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="gerar_30dias" id="gerar_30dias_cp" checked>
                                    <label class="form-check-label" for="gerar_30dias_cp">
                                        Gerar automaticamente as parcelas a cada 30 dias
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="3"><?= htmlspecialchars($conta_cp['observacoes'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-warning">💾 Salvar Alterações</button>
                                <a href="?mod=financeiro&aba=cp" class="btn btn-secondary">Cancelar</a>
                            </div>
                        </form>

                        <!-- PREVIEW de Parcelas (somente visual, antes de salvar) -->
                        <hr>
                        <h5>Pré-visualização das Parcelas</h5>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Vencimento</th>
                                        <th>Valor Parcela</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="preview_parcelas_cp">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">
                                            Informe Valor Total, Qtd Parcelas e 1º Vencimento para ver as parcelas...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <?php
                        // Parcelas já gravadas no banco (ao editar)
                        if (!empty($conta_cp['id'])):
                            $parcelas_cp = getParcelasCP($conn, $conta_cp['id']);
                            if ($parcelas_cp):
                        ?>
                            <hr>
                            <h5>Parcelas Geradas</h5>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Vencimento</th>
                                            <th>Valor Parcela</th>
                                            <th>Pago</th>
                                            <th>Saldo</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($parcelas_cp as $par): ?>
                                        <?php $vis = statusVisualParcelaFin($par['valor_parcela'], $par['valor_pago']); ?>
                                        <tr>
                                            <td><?= (int)$par['parcela_numero'] ?></td>
                                            <td><?= !empty($par['data_vencimento']) ? date('d/m/Y', strtotime($par['data_vencimento'])) : '-' ?></td>
                                            <td><?= fmtBrlFin($par['valor_parcela']) ?></td>
                                            <td>
                                                <form method="POST" class="d-flex gap-2 align-items-center">
                                                    <input type="hidden" name="salvar_parcela_cp" value="1">
                                                    <input type="hidden" name="parcela_id" value="<?= htmlspecialchars($par['id']) ?>">
                                                    <input type="hidden" name="conta_id" value="<?= htmlspecialchars($conta_cp['id']) ?>">
                                                    <input type="text" name="valor_pago_parcela"
                                                           class="form-control form-control-sm"
                                                           style="max-width:130px"
                                                           value="<?= fmtBrlFin($par['valor_pago']) ?>"
                                                           oninput="aplicarMascaraMoedaFin(this);">
                                                    <button class="btn btn-sm btn-success" title="Salvar valor desta parcela">💾</button>
                                                </form>
                                            </td>
                                            <td><?= fmtBrlFin($par['saldo_devedor']) ?></td>
                                            <td>
                                                <span class="badge <?= $vis['badge'] ?>">
                                                    <?= $vis['dot'] ?> <?= $vis['label'] ?>
                                                </span>
                                            </td>
                                            <td><!-- ações extras, se necessário --></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <small class="text-muted">
                                    🔴 Devedor &nbsp; 🟡 Pago parcialmente &nbsp; 🟢 Quitada
                                </small>
                            </div>
                        <?php
                            endif;
                        endif;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================= CONTAS A RECEBER (SEM PARCELAS, SIMPLES) ================= -->
        <div class="tab-pane fade <?= $aba === 'cr' ? 'show active' : '' ?>" id="cr-pane">
            <?php if ($aba === 'cr' && ($acao === 'listar' || $acao === 'lixeira')): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="text-muted small"><?= ($lista_cr && $lista_cr->num_rows) ? $lista_cr->num_rows . ' registro(s) encontrado(s)' : '' ?></span>
                        <?php if ($acao === 'lixeira'): ?>
                            <span class="badge bg-danger ms-2">Lixeira</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($acao === 'lixeira'): ?>
                            <a href="?mod=financeiro&aba=cr&acao=listar" class="btn btn-outline-primary no-print">
                                <i class="bi bi-arrow-left"></i> Voltar à Listagem
                            </a>
                        <?php else: ?>
                            <button onclick="imprimirRelatorio('cr')" class="btn btn-outline-secondary no-print">
                                <i class="bi bi-printer"></i> Imprimir / Salvar PDF
                            </button>
                            <a href="?mod=financeiro&aba=cr&acao=lixeira" class="btn btn-outline-danger no-print">
                                <i class="bi bi-trash"></i> Ver Lixeira
                            </a>
                            <a href="?mod=financeiro&aba=cr&acao=novo_cr" class="btn btn-primary no-print">+ Nova Conta a Receber</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Descrição</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($lista_cr && $lista_cr->num_rows): ?>
                                    <?php while ($row = $lista_cr->fetch_assoc()): ?>
                                        <?php $badge = badgeClasseFin((string)$row['status'], 'cr'); ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['id']) ?></td>
                                            <td><?= htmlspecialchars($row['descricao'] ?? '-') ?></td>
                                            <td><?= fmtBrlFin($row['valor'] ?? 0) ?></td>
                                            <td><?= !empty($row['data_vencimento']) ? date('d/m/Y', strtotime($row['data_vencimento'])) : '-' ?></td>
                                            <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($row['status'] ?? '-') ?></span></td>
                                            <td class="text-nowrap">
                                                <?php if ($acao === 'lixeira'): ?>
                                                    <a href="?mod=financeiro&aba=cr&restaurar=<?= urlencode($row['id']) ?>&tipo=cr"
                                                       class="btn btn-sm btn-outline-success" title="Restaurar">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </a>
                                                    <a href="?mod=financeiro&aba=cr&excluir_permanente=<?= urlencode($row['id']) ?>&tipo=cr"
                                                       class="btn btn-sm btn-danger"
                                                       onclick="return confirm('ATENÇÃO: Deseja mesmo excluir PERMANENTEMENTE a conta <?= htmlspecialchars($row['id']) ?>? Esta ação não pode ser desfeita.')"
                                                       title="Excluir Permanentemente">
                                                        <i class="bi bi-fire"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?mod=financeiro&aba=cr&acao=editar_cr&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning" title="Editar">✏️</a>
                                                    <a href="?mod=financeiro&aba=cr&excluir=<?= urlencode($row['id']) ?>&tipo=cr"
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="return confirm('Mover a conta <?= htmlspecialchars($row['id']) ?> para a lixeira?')"
                                                       title="Mover para Lixeira">🗑️</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <?= $acao === 'lixeira' ? 'Nenhuma conta a receber na lixeira.' : 'Nenhuma conta a receber cadastrada.' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($aba === 'cr' && ($acao === 'novo_cr' || $acao === 'editar_cr')): ?>

                <?php
                $conta_cr = [
                    'id'               => '',
                    'descricao'        => '',
                    'valor'            => 0,
                    'data_vencimento'  => '',
                    'data_recebimento' => '',
                    'status'           => 'Pendente',
                    'observacoes'      => '',
                ];

                if ($acao === 'editar_cr' && isset($_GET['id'])) {
                    $id_edit = $conn->real_escape_string($_GET['id']);
                    $res_cr  = $conn->query("SELECT * FROM contas_receber WHERE id = '$id_edit' LIMIT 1");
                    if ($res_cr && $res_cr->num_rows) {
                        $conta_cr = $res_cr->fetch_assoc();
                    }
                }
                ?>

                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <?= $conta_cr['id'] ? "✏️ Editar Conta a Receber — " . htmlspecialchars($conta_cr['id']) : "+ Nova Conta a Receber" ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="salvar_cr" value="1">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($conta_cr['id']) ?>">

                            <div class="col-md-6">
                                <label class="form-label">Descrição</label>
                                <input type="text" name="descricao" class="form-control"
                                       value="<?= htmlspecialchars($conta_cr['descricao'] ?? '') ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Valor *</label>
                                <input type="text" name="valor" class="form-control"
                                       value="<?= fmtBrlFin($conta_cr['valor'] ?? 0) ?>"
                                       oninput="aplicarMascaraMoedaFin(this);">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (['Pendente','Recebido','Parcial','Cancelado'] as $st): ?>
                                        <option value="<?= $st ?>" <?= ($conta_cr['status'] ?? 'Pendente') === $st ? 'selected' : '' ?>>
                                            <?= $st ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Data Vencimento</label>
                                <input type="date" name="data_vencimento" class="form-control"
                                       value="<?= htmlspecialchars($conta_cr['data_vencimento'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data Recebimento</label>
                                <input type="date" name="data_recebimento" class="form-control"
                                       value="<?= htmlspecialchars($conta_cr['data_recebimento'] ?? '') ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="3"><?= htmlspecialchars($conta_cr['observacoes'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">💾 Salvar</button>
                                <a href="?mod=financeiro&aba=cr" class="btn btn-secondary">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php $conn->close(); ?>

<script>
function aplicarMascaraMoedaFin(input) {
    let valor = input.value.replace(/\D/g, '');
    if (valor === '') {
        input.value = '';
        return;
    }
    valor = (parseInt(valor, 10) / 100).toFixed(2);
    valor = valor.replace('.', ',');
    valor = valor.replace(/(\d)(\d{3})(\d{3}),/, '$1.$2.$3,');
    valor = valor.replace(/(\d)(\d{3}),/, '$1.$2,');
    input.value = 'R$ ' + valor;
}

/* Preview das parcelas de CP (antes de salvar) */
function parseBrlFin(valor) {
    if (!valor) return 0;
    return parseFloat(
        valor.toString()
            .replace(/R\$/g, '')
            .replace(/\./g, '')
            .replace(',', '.')
            .replace(/[^0-9.]/g, '')
    ) || 0;
}

function formatBrlFinJS(v) {
    return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function atualizarPreviewParcelasCP() {
    const totalEl = document.getElementById('valor_total_cp');
    const qtdEl   = document.getElementById('qtd_parcelas_cp');
    const vencEl  = document.getElementById('data_vencimento_cp');
    const tbody   = document.getElementById('preview_parcelas_cp');

    if (!totalEl || !qtdEl || !vencEl || !tbody) return;

    const total = parseBrlFin(totalEl.value);
    const qtd   = parseInt(qtdEl.value || '1', 10);
    const venc  = vencEl.value;

    if (!total || !qtd || !venc) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Informe Valor Total, Qtd Parcelas e 1º Vencimento...</td></tr>';
        return;
    }

    const totalCents = Math.round(total * 100);
    const baseCents  = Math.floor(totalCents / qtd);
    const resto      = totalCents - (baseCents * qtd);

    let html = '';
    const primeiraData = new Date(venc + 'T00:00:00');

    for (let i = 1; i <= qtd; i++) {
        let cents = baseCents;
        if (i === qtd) cents += resto;
        const valorParcela = cents / 100;

        const dataParcela = new Date(primeiraData);
        if (i > 1) dataParcela.setDate(dataParcela.getDate() + (30 * (i - 1)));

        const vencStr = dataParcela.toLocaleDateString('pt-BR');
        html += `
            <tr>
                <td>${i}</td>
                <td>${vencStr}</td>
                <td>${formatBrlFinJS(valorParcela)}</td>
                <td class="text-muted">Pendente</td>
            </tr>`;
    }

    tbody.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function () {
    atualizarPreviewParcelasCP();
});

/* ====================================================
   IMPRESSÃO / PDF — Financeiro
   ==================================================== */
function imprimirRelatorio(aba) {
    const abaLabel = aba === 'cp' ? 'Contas a Pagar' : 'Contas a Receber';
    const tabela   = document.querySelector('#' + aba + '-pane table');

    if (!tabela) {
        alert('Nenhum dado disponível para imprimir.');
        return;
    }

    // Coleta totais da tabela
    const rows = tabela.querySelectorAll('tbody tr');
    let totalValor = 0, totalPago = 0, totalSaldo = 0;
    rows.forEach(function(tr) {
        const tds = tr.querySelectorAll('td');
        if (tds.length < 5) return;

        function parseBrl(s) {
            if (!s) return 0;
            return parseFloat(s.replace(/R\$\s?/g,'').replace(/\./g,'').replace(',','.')) || 0;
        }

        if (aba === 'cp') {
            totalValor += parseBrl(tds[4] ? tds[4].textContent : '');
            totalPago  += parseBrl(tds[6] ? tds[6].textContent : '');
            totalSaldo += parseBrl(tds[7] ? tds[7].textContent : '');
        } else {
            totalValor += parseBrl(tds[2] ? tds[2].textContent : '');
        }
    });

    function fmt(v) {
        return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Clona a tabela para não mexer no DOM original
    const tabelaClone = tabela.cloneNode(true);
    // Remove coluna Ações do clone
    tabelaClone.querySelectorAll('thead tr').forEach(function(tr) {
        const ths = tr.querySelectorAll('th');
        if (ths.length) ths[ths.length - 1].remove();
    });
    tabelaClone.querySelectorAll('tbody tr').forEach(function(tr) {
        const tds = tr.querySelectorAll('td');
        if (tds.length) tds[tds.length - 1].remove();
        // Limpa badges coloridos — deixa texto simples
        tr.querySelectorAll('.badge').forEach(function(b) {
            b.style.background = '#eee';
            b.style.color = '#000';
            b.style.padding = '2px 6px';
            b.style.borderRadius = '4px';
            b.style.fontSize = '11px';
        });
    });

    const dataGeracao = new Date().toLocaleString('pt-BR');

    const totaisHTML = aba === 'cp'
        ? `<tr style="font-weight:700; background:#f4f4f4;">
               <td colspan="4" style="text-align:right;">TOTAIS</td>
               <td>${fmt(totalValor)}</td>
               <td></td>
               <td>${fmt(totalPago)}</td>
               <td style="color:#b00;">${fmt(totalSaldo)}</td>
               <td></td>
           </tr>`
        : `<tr style="font-weight:700; background:#f4f4f4;">
               <td colspan="2" style="text-align:right;">TOTAL</td>
               <td>${fmt(totalValor)}</td>
               <td colspan="3"></td>
           </tr>`;

    // Insere linha de totais no clone
    const tbody = tabelaClone.querySelector('tbody');
    if (tbody) tbody.insertAdjacentHTML('beforeend', totaisHTML);

    const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório — ${abaLabel} — SGL</title>
    <style>
        @page { size: A4 landscape; margin: 1.5cm 1.5cm; }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #222;
            margin: 0;
            padding: 0;
        }
        .rel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #1d281b;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .rel-header .logo {
            height: 52px;
            width: auto;
        }
        .rel-header .titulo {
            flex: 1;
            text-align: center;
        }
        .rel-header .titulo h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #1d281b;
            text-transform: uppercase;
        }
        .rel-header .titulo p {
            margin: 3px 0 0;
            font-size: 10px;
            color: #555;
        }
        .rel-header .data {
            font-size: 9px;
            color: #666;
            text-align: right;
            white-space: nowrap;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead tr th {
            background: #1d281b;
            color: #fff;
            padding: 7px 9px;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        tbody tr td {
            padding: 6px 9px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        tbody tr:nth-child(even) td {
            background: #f8f8f8;
        }
        .rel-footer {
            margin-top: 24px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
            font-size: 9px;
            color: #888;
            display: flex;
            justify-content: space-between;
        }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="rel-header">
        <img src="/sistema_sgl/assets/img/logo_custom.png" alt="SGL" class="logo">
        <div class="titulo">
            <h2>Relatório de ${abaLabel}</h2>
            <p>Struzik, Guimarães &amp; Lecz — Advocacia &nbsp;|&nbsp; Sistema de Gestão Jurídica</p>
        </div>
        <div class="data">
            Gerado em:<br><strong>${dataGeracao}</strong>
        </div>
    </div>

    ${tabelaClone.outerHTML}

    <div class="rel-footer">
        <span>SGL — Sistema de Gestão Jurídica</span>
        <span>Struzik, Guimarães &amp; Lecz Advocacia</span>
        <span>Emissão: ${dataGeracao}</span>
    </div>
</body>
</html>`;

    const janela = window.open('', '_blank', 'width=1100,height=750');
    janela.document.write(html);
    janela.document.close();
    janela.onload = function() {
        janela.focus();
        janela.print();
    };
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    nav, .sidebar, .btn, a.btn { display: none !important; }
    body { background: #fff !important; }
    .card { box-shadow: none !important; border: none !important; }
}
</style>