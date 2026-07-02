<?php
/**
 * Fase 3.8 — Central Inteligente
 * Painel operacional de alertas e prioridades do escritório.
 */
$conn = conectar();
if (function_exists('sgl_integracao_garantir_financeiro')) {
    sgl_integracao_garantir_financeiro($conn);
}

if (!function_exists('h')) {
    function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sgl_ci_moeda')) {
    function sgl_ci_moeda($valor): string { return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.'); }
}
if (!function_exists('sgl_ci_data')) {
    function sgl_ci_data($data): string {
        if (empty($data) || $data === '0000-00-00') return '-';
        $ts = strtotime((string)$data);
        return $ts ? date('d/m/Y', $ts) : '-';
    }
}
if (!function_exists('sgl_ci_tabela_existe')) {
    function sgl_ci_tabela_existe(mysqli $conn, string $tabela): bool {
        $safe = $conn->real_escape_string($tabela);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('sgl_ci_coluna_existe')) {
    function sgl_ci_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
        $safeTabela = str_replace('`', '', $tabela);
        $safeColuna = $conn->real_escape_string($coluna);
        $res = $conn->query("SHOW COLUMNS FROM `{$safeTabela}` LIKE '{$safeColuna}'");
        return $res && $res->num_rows > 0;
    }
}
if (!function_exists('sgl_ci_rows')) {
    function sgl_ci_rows(mysqli $conn, string $sql): array {
        $res = $conn->query($sql);
        if (!$res) {
            error_log('[SGL Central Inteligente] ' . $conn->error . ' | ' . $sql);
            return [];
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
}
if (!function_exists('sgl_ci_scalar')) {
    function sgl_ci_scalar(mysqli $conn, string $sql): float {
        $res = $conn->query($sql);
        if (!$res) {
            error_log('[SGL Central Inteligente scalar] ' . $conn->error . ' | ' . $sql);
            return 0;
        }
        $row = $res->fetch_assoc();
        return (float)($row['total'] ?? 0);
    }
}
if (!function_exists('sgl_ci_add')) {
    function sgl_ci_add(array &$lista, string $tipo, string $titulo, string $descricao, string $prioridade, string $link, string $icone, string $cor, array $meta = []): void {
        $peso = ['critico'=>1, 'alto'=>2, 'medio'=>3, 'baixo'=>4][$prioridade] ?? 5;
        $lista[] = compact('tipo','titulo','descricao','prioridade','link','icone','cor','meta','peso');
    }
}

$hoje = date('Y-m-d');
$amanha = date('Y-m-d', strtotime('+1 day'));
$seteDias = date('Y-m-d', strtotime('+7 days'));
$trintaDiasAtras = date('Y-m-d', strtotime('-30 days'));
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');
$alertas = [];

// Honorários vencidos
if (sgl_ci_tabela_existe($conn, 'honorarios_parcelas')) {
    $joinHonorarios = sgl_ci_tabela_existe($conn, 'honorarios') ? "LEFT JOIN honorarios h ON h.id = hp.honorario_id" : "";
    $joinClientes = sgl_ci_tabela_existe($conn, 'clientes') ? "LEFT JOIN clientes c ON c.id = h.cliente_id" : "";
    $clienteExpr = sgl_ci_tabela_existe($conn, 'clientes') ? "COALESCE(c.nome,'Cliente não informado')" : "'Cliente não informado'";
    $whereHonorarioAtivo = sgl_ci_tabela_existe($conn, 'honorarios') && sgl_ci_coluna_existe($conn, 'honorarios', 'deletado') ? "AND COALESCE(h.deletado,0)=0" : "";
    $rows = sgl_ci_rows($conn, "
        SELECT hp.id, {$clienteExpr} AS cliente_nome, hp.data_vencimento,
               COALESCE(NULLIF(hp.saldo_devedor,0), hp.valor_parcela, 0) AS valor
        FROM honorarios_parcelas hp
        {$joinHonorarios}
        {$joinClientes}
        WHERE hp.data_vencimento < '{$hoje}'
          AND COALESCE(hp.status_pagamento,'Pendente') NOT IN ('Pago','Quitada','Recebido','Cancelado')
          AND COALESCE(NULLIF(hp.saldo_devedor,0), hp.valor_parcela, 0) > 0
          {$whereHonorarioAtivo}
        ORDER BY hp.data_vencimento ASC
        LIMIT 8
    ");
    foreach ($rows as $r) {
        sgl_ci_add($alertas, 'Honorários', 'Honorário vencido — ' . ($r['cliente_nome'] ?? 'Cliente'),
            'Vencimento: ' . sgl_ci_data($r['data_vencimento']) . ' • Pendente: ' . sgl_ci_moeda($r['valor']),
            'critico', '?mod=honorarios', 'bi-cash-stack', 'danger');
    }
}

// Contas a receber vencidas e recebimentos de hoje
if (sgl_ci_tabela_existe($conn, 'contas_receber')) {
    $del = sgl_ci_coluna_existe($conn, 'contas_receber', 'deletado') ? "AND COALESCE(cr.deletado,0)=0" : "";
    $joinClientes = sgl_ci_tabela_existe($conn, 'clientes') ? "LEFT JOIN clientes c ON c.id = cr.cliente_id" : "";
    $temClienteNomeCR = sgl_ci_coluna_existe($conn, 'contas_receber', 'cliente_nome');
    $clienteExpr = sgl_ci_tabela_existe($conn, 'clientes')
        ? ($temClienteNomeCR ? "COALESCE(c.nome, cr.cliente_nome, '-')" : "COALESCE(c.nome, '-')")
        : ($temClienteNomeCR ? "COALESCE(cr.cliente_nome, '-')" : "'-'");
    $valorExprCR = sgl_ci_coluna_existe($conn, 'contas_receber', 'valor_pendente')
        ? "COALESCE(NULLIF(cr.valor_pendente,0), cr.valor,0)"
        : "COALESCE(cr.valor,0)";
    $rows = sgl_ci_rows($conn, "
        SELECT cr.id, cr.id AS codigo, cr.descricao, {$clienteExpr} AS cliente_nome, cr.data_vencimento,
               {$valorExprCR} AS valor
        FROM contas_receber cr
        {$joinClientes}
        WHERE cr.data_vencimento < '{$hoje}'
          AND cr.status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')
          {$del}
        ORDER BY cr.data_vencimento ASC
        LIMIT 8
    ");
    foreach ($rows as $r) {
        sgl_ci_add($alertas, 'Financeiro', 'Conta a receber vencida — ' . ($r['cliente_nome'] ?: $r['codigo']),
            'Vencimento: ' . sgl_ci_data($r['data_vencimento']) . ' • Valor: ' . sgl_ci_moeda($r['valor']),
            'critico', '?mod=financeiro&tipo=receber', 'bi-arrow-down-circle', 'danger');
    }
    $rows = sgl_ci_rows($conn, "
        SELECT cr.id, cr.id AS codigo, cr.descricao, {$clienteExpr} AS cliente_nome, cr.data_vencimento,
               {$valorExprCR} AS valor
        FROM contas_receber cr
        {$joinClientes}
        WHERE cr.data_vencimento = '{$hoje}'
          AND cr.status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')
          {$del}
        ORDER BY cr.id DESC
        LIMIT 8
    ");
    foreach ($rows as $r) {
        sgl_ci_add($alertas, 'Financeiro', 'Recebimento previsto hoje — ' . ($r['cliente_nome'] ?: $r['codigo']),
            'Valor: ' . sgl_ci_moeda($r['valor']) . ' • ' . ($r['descricao'] ?: 'Conta a receber'),
            'alto', '?mod=financeiro&tipo=receber', 'bi-calendar-check', 'primary');
    }
}

// Contas a pagar vencidas e vencendo hoje
if (sgl_ci_tabela_existe($conn, 'contas_pagar')) {
    $del = sgl_ci_coluna_existe($conn, 'contas_pagar', 'deletado') ? "AND COALESCE(cp.deletado,0)=0" : "";
    foreach ([['<', 'Conta a pagar vencida', 'critico', 'danger'], ['=', 'Conta a pagar vence hoje', 'alto', 'warning']] as $cfg) {
        [$op, $tituloBase, $prioridade, $cor] = $cfg;
        $rows = sgl_ci_rows($conn, "
            SELECT cp.id, cp.descricao, cp.fornecedor, cp.data_vencimento,
                   COALESCE(NULLIF(cp.valor_pendente,0), cp.valor,0) AS valor
            FROM contas_pagar cp
            WHERE cp.data_vencimento {$op} '{$hoje}'
              AND cp.status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')
              {$del}
            ORDER BY cp.data_vencimento ASC
            LIMIT 8
        ");
        foreach ($rows as $r) {
            sgl_ci_add($alertas, 'Financeiro', $tituloBase . ' — ' . ($r['fornecedor'] ?: $r['descricao'] ?: 'Despesa'),
                'Vencimento: ' . sgl_ci_data($r['data_vencimento']) . ' • Valor: ' . sgl_ci_moeda($r['valor']),
                $prioridade, '?mod=financeiro&tipo=pagar', 'bi-exclamation-triangle', $cor);
        }
    }
}

// Agenda hoje e amanhã
if (sgl_ci_tabela_existe($conn, 'agenda')) {
    $del = sgl_ci_coluna_existe($conn, 'agenda', 'deletado') ? "AND COALESCE(a.deletado,0)=0" : "";
    foreach ([[$hoje, 'Compromisso de hoje', 'alto'], [$amanha, 'Compromisso de amanhã', 'medio']] as $cfg) {
        [$data, $tituloBase, $prioridade] = $cfg;
        $rows = sgl_ci_rows($conn, "
            SELECT a.id, a.horario, a.tipo_compromisso, a.nome_cliente, a.local, a.local_evento, a.status
            FROM agenda a
            WHERE a.data_evento = '{$data}'
              AND COALESCE(a.status,'') NOT IN ('Cancelado','Concluído')
              {$del}
            ORDER BY a.horario ASC
            LIMIT 8
        ");
        foreach ($rows as $r) {
            sgl_ci_add($alertas, 'Agenda', $tituloBase . ' — ' . ($r['tipo_compromisso'] ?: 'Compromisso'),
                'Hora: ' . ($r['horario'] ? date('H:i', strtotime($r['horario'])) : '-') . ' • Cliente: ' . ($r['nome_cliente'] ?: '-') . ' • Local: ' . (($r['local'] ?: $r['local_evento']) ?: '-'),
                $prioridade, '?mod=agenda', 'bi-calendar-event', 'info');
        }
    }
}

// Processos com prazo próximo e processos sem movimentação
if (sgl_ci_tabela_existe($conn, 'processos')) {
    $num = sgl_ci_coluna_existe($conn,'processos','numero_processo') ? 'numero_processo' : (sgl_ci_coluna_existe($conn,'processos','num_processo') ? 'num_processo' : 'id');
    if (sgl_ci_coluna_existe($conn, 'processos', 'proximo_prazo')) {
        $rows = sgl_ci_rows($conn, "
            SELECT id, `{$num}` AS numero, tipo_processo, fase_atual AS fase, proximo_prazo, status
            FROM processos
            WHERE proximo_prazo BETWEEN '{$hoje}' AND '{$seteDias}'
              AND COALESCE(status,'') NOT IN ('Excluído','Arquivado','Encerrado')
            ORDER BY proximo_prazo ASC
            LIMIT 8
        ");
        foreach ($rows as $r) {
            sgl_ci_add($alertas, 'Processos', 'Prazo processual próximo — ' . ($r['numero'] ?: 'Processo'),
                'Prazo: ' . sgl_ci_data($r['proximo_prazo']) . ' • Tipo: ' . ($r['tipo_processo'] ?: '-') . ' • Fase: ' . ($r['fase'] ?: '-'),
                $r['proximo_prazo'] === $hoje ? 'critico' : 'alto', '?mod=processos&busca=' . urlencode($r['numero'] ?: $r['id']), 'bi-hourglass-split', 'warning');
        }
    }
    $dataCol = sgl_ci_coluna_existe($conn,'processos','atualizado_em') ? 'atualizado_em' : (sgl_ci_coluna_existe($conn,'processos','data_cadastro') ? 'data_cadastro' : null);
    if ($dataCol) {
        $rows = sgl_ci_rows($conn, "
            SELECT id, `{$num}` AS numero, tipo_processo, fase_atual AS fase, `{$dataCol}` AS data_ref, status
            FROM processos
            WHERE DATE(`{$dataCol}`) <= '{$trintaDiasAtras}'
              AND COALESCE(status,'') IN ('Em Andamento','Ativo','Distribuído','Pendente')
            ORDER BY `{$dataCol}` ASC
            LIMIT 6
        ");
        foreach ($rows as $r) {
            sgl_ci_add($alertas, 'Processos', 'Processo sem atualização há mais de 30 dias — ' . ($r['numero'] ?: 'Processo'),
                'Última referência: ' . sgl_ci_data($r['data_ref']) . ' • Fase: ' . ($r['fase'] ?: '-'),
                'medio', '?mod=processos&busca=' . urlencode($r['numero'] ?: $r['id']), 'bi-clock-history', 'secondary');
        }
    }
}

// Clientes sem documentos anexados
if (sgl_ci_tabela_existe($conn, 'clientes') && sgl_ci_tabela_existe($conn, 'documentos')) {
    $delClientes = sgl_ci_coluna_existe($conn, 'clientes', 'deletado') ? "AND COALESCE(c.deletado,0)=0" : "";
    $delDocs = sgl_ci_coluna_existe($conn, 'documentos', 'deletado') ? "AND COALESCE(d.deletado,0)=0" : "";
    $rows = sgl_ci_rows($conn, "
        SELECT c.id, c.nome, c.cpf_cnpj
        FROM clientes c
        WHERE COALESCE(c.status,'Ativo') = 'Ativo'
          {$delClientes}
          AND NOT EXISTS (
              SELECT 1 FROM documentos d
              WHERE d.cliente_id = c.id {$delDocs}
          )
        ORDER BY c.nome ASC
        LIMIT 8
    ");
    foreach ($rows as $r) {
        sgl_ci_add($alertas, 'Documentos', 'Cliente sem documentos — ' . ($r['nome'] ?: 'Cliente'),
            'CPF/CNPJ: ' . ($r['cpf_cnpj'] ?: '-') . ' • Anexe RG, CPF, procuração, comprovantes ou provas.',
            'baixo', '?mod=documentos&cliente_id=' . (int)$r['id'], 'bi-file-earmark-arrow-up', 'primary');
    }
}

usort($alertas, function($a, $b) { return [$a['peso'], $a['tipo']] <=> [$b['peso'], $b['tipo']]; });

$contCritico = count(array_filter($alertas, fn($a) => $a['prioridade'] === 'critico'));
$contAlto = count(array_filter($alertas, fn($a) => $a['prioridade'] === 'alto'));
$contMedio = count(array_filter($alertas, fn($a) => $a['prioridade'] === 'medio'));
$contBaixo = count(array_filter($alertas, fn($a) => $a['prioridade'] === 'baixo'));

$entradasHoje = sgl_ci_tabela_existe($conn,'contas_receber') ? sgl_ci_scalar($conn, "SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_receber WHERE status IN ('Recebido','Pago','Quitada') AND COALESCE(data_recebimento, DATE(atualizado_em), data_vencimento) = '{$hoje}'" . (sgl_ci_coluna_existe($conn,'contas_receber','deletado') ? " AND COALESCE(deletado,0)=0" : "")) : 0;
$saidasHoje = sgl_ci_tabela_existe($conn,'contas_pagar') ? sgl_ci_scalar($conn, "SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_pagar WHERE status IN ('Pago','Quitada') AND COALESCE(data_pagamento, DATE(atualizado_em), data_vencimento) = '{$hoje}'" . (sgl_ci_coluna_existe($conn,'contas_pagar','deletado') ? " AND COALESCE(deletado,0)=0" : "")) : 0;
$receberHoje = sgl_ci_tabela_existe($conn,'contas_receber') ? sgl_ci_scalar($conn, "SELECT COALESCE(SUM(COALESCE(NULLIF(valor_pendente,0),valor,0)),0) AS total FROM contas_receber WHERE data_vencimento = '{$hoje}' AND status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')" . (sgl_ci_coluna_existe($conn,'contas_receber','deletado') ? " AND COALESCE(deletado,0)=0" : "")) : 0;
$pagarHoje = sgl_ci_tabela_existe($conn,'contas_pagar') ? sgl_ci_scalar($conn, "SELECT COALESCE(SUM(COALESCE(NULLIF(valor_pendente,0),valor,0)),0) AS total FROM contas_pagar WHERE data_vencimento = '{$hoje}' AND status IN ('Pendente','Parcial','Aberto','Em Aberto','Vencido')" . (sgl_ci_coluna_existe($conn,'contas_pagar','deletado') ? " AND COALESCE(deletado,0)=0" : "")) : 0;

$porTipo = [];
foreach ($alertas as $a) { $porTipo[$a['tipo']] = ($porTipo[$a['tipo']] ?? 0) + 1; }
?>

<style>
.ci-hero{background:linear-gradient(135deg,#123a5a,#2f7cc0);border-radius:18px;color:#fff!important;padding:24px;box-shadow:0 12px 28px rgba(18,58,90,.18)}
.ci-hero h1,.ci-hero h2,.ci-hero h3,.ci-hero p,.ci-hero div{color:#fff!important}
.ci-hero .opacity-75{opacity:.9!important}
.ci-card{background:#fff;border-radius:16px;border:1px solid rgba(15,23,42,.08);box-shadow:0 8px 24px rgba(15,23,42,.06)}
.ci-kpi{padding:18px}.ci-kpi .num{font-size:2rem;font-weight:900;line-height:1}.ci-kpi .lbl{font-size:.78rem;text-transform:uppercase;color:#64748b;font-weight:700}
.ci-alerta{border-left:5px solid #94a3b8;border-radius:14px;background:#fff;padding:16px;box-shadow:0 6px 18px rgba(15,23,42,.06);margin-bottom:12px}
.ci-alerta.critico{border-left-color:#dc3545}.ci-alerta.alto{border-left-color:#fd7e14}.ci-alerta.medio{border-left-color:#ffc107}.ci-alerta.baixo{border-left-color:#0d6efd}
.ci-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-size:1.2rem}.ci-filter .btn.active{box-shadow:inset 0 -3px 0 rgba(255,255,255,.35)}
@media print{.sgl-sidebar,.sgl-topbar,.ci-actions,.ci-filter{display:none!important}.sgl-main{padding:0!important;background:#fff}.ci-card,.ci-alerta{box-shadow:none!important}.ci-hero{color:#111;background:#fff;border:1px solid #ddd}}
</style>

<div class="ci-hero mb-4 d-flex flex-column flex-lg-row justify-content-between gap-3">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-lightbulb"></i> Central Inteligente</h2>
        <div class="opacity-75">Prioridades, alertas e pendências para orientar a rotina do escritório.</div>
    </div>
    <div class="ci-actions d-flex gap-2 align-items-start">
        <a class="btn btn-light" href="?mod=agenda"><i class="bi bi-calendar-event"></i> Agenda</a>
        <a class="btn btn-outline-light" href="?mod=financeiro"><i class="bi bi-cash-coin"></i> Financeiro</a>
        <button class="btn btn-outline-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="ci-card ci-kpi"><div class="lbl">Críticos</div><div class="num text-danger"><?= $contCritico ?></div><small>precisam de ação imediata</small></div></div>
    <div class="col-md-3"><div class="ci-card ci-kpi"><div class="lbl">Alta prioridade</div><div class="num text-warning"><?= $contAlto ?></div><small>acompanhar hoje</small></div></div>
    <div class="col-md-3"><div class="ci-card ci-kpi"><div class="lbl">A receber hoje</div><div class="num text-primary"><?= sgl_ci_moeda($receberHoje) ?></div><small>previsto para o dia</small></div></div>
    <div class="col-md-3"><div class="ci-card ci-kpi"><div class="lbl">Saldo do dia</div><div class="num <?= ($entradasHoje - $saidasHoje) >= 0 ? 'text-success' : 'text-danger' ?>"><?= sgl_ci_moeda($entradasHoje - $saidasHoje) ?></div><small>recebido - pago</small></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="ci-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-list-check"></i> O que precisa de atenção</h5>
                <span class="badge text-bg-dark"><?= count($alertas) ?> alerta(s)</span>
            </div>
            <div class="ci-filter btn-group mb-3" role="group">
                <button class="btn btn-dark btn-sm active" type="button" data-ci-filter="todos">Todos</button>
                <button class="btn btn-outline-danger btn-sm" type="button" data-ci-filter="critico">Críticos</button>
                <button class="btn btn-outline-warning btn-sm" type="button" data-ci-filter="alto">Alta</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-ci-filter="medio">Média</button>
                <button class="btn btn-outline-primary btn-sm" type="button" data-ci-filter="baixo">Baixa</button>
            </div>
            <?php if (empty($alertas)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
                    <h5 class="mt-3">Nenhum alerta importante no momento.</h5>
                    <p>O sistema não encontrou pendências críticas com os dados atuais.</p>
                </div>
            <?php else: ?>
                <?php foreach ($alertas as $a): ?>
                    <div class="ci-alerta <?= h($a['prioridade']) ?>" data-prioridade="<?= h($a['prioridade']) ?>">
                        <div class="d-flex gap-3">
                            <div class="ci-icon text-<?= h($a['cor']) ?>"><i class="bi <?= h($a['icone']) ?>"></i></div>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold text-dark"><?= h($a['titulo']) ?></div>
                                        <div class="text-muted small"><?= h($a['descricao']) ?></div>
                                    </div>
                                    <span class="badge text-bg-<?= $a['prioridade']==='critico'?'danger':($a['prioridade']==='alto'?'warning':($a['prioridade']==='medio'?'secondary':'primary')) ?>"><?= h(strtoupper($a['prioridade'])) ?></span>
                                </div>
                                <div class="mt-2"><a href="<?= h($a['link']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-right-circle"></i> Abrir</a></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ci-card p-3 mb-3">
            <h5><i class="bi bi-pie-chart"></i> Resumo por área</h5>
            <?php if (empty($porTipo)): ?>
                <p class="text-muted mb-0">Sem alertas agrupados.</p>
            <?php else: ?>
                <?php foreach ($porTipo as $tipo => $qtd): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span><?= h($tipo) ?></span><strong><?= (int)$qtd ?></strong>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="ci-card p-3">
            <h5><i class="bi bi-cash-stack"></i> Caixa rápido</h5>
            <div class="d-flex justify-content-between py-2"><span>Entradas hoje</span><strong class="text-success"><?= sgl_ci_moeda($entradasHoje) ?></strong></div>
            <div class="d-flex justify-content-between py-2"><span>Saídas hoje</span><strong class="text-danger"><?= sgl_ci_moeda($saidasHoje) ?></strong></div>
            <div class="d-flex justify-content-between py-2"><span>A receber hoje</span><strong class="text-primary"><?= sgl_ci_moeda($receberHoje) ?></strong></div>
            <div class="d-flex justify-content-between py-2"><span>A pagar hoje</span><strong class="text-warning"><?= sgl_ci_moeda($pagarHoje) ?></strong></div>
            <hr>
            <a href="?mod=financeiro&fechamento=dia" class="btn btn-outline-dark w-100"><i class="bi bi-box-arrow-up-right"></i> Ver fechamento do dia</a>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-ci-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('[data-ci-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const filtro = btn.getAttribute('data-ci-filter');
        document.querySelectorAll('.ci-alerta').forEach(card => {
            card.style.display = (filtro === 'todos' || card.getAttribute('data-prioridade') === filtro) ? '' : 'none';
        });
    });
});
</script>
