<?php
$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    $conn->close();
    throw new RuntimeException('Contexto Multi-Tenant inválido para o módulo Processos.');
}

$tenantId = function_exists('rojexTenantId')
    ? (string)rojexTenantId()
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    $conn->close();
    throw new RuntimeException('Tenant ou escritório não identificado para o módulo Processos.');
}

if (function_exists('sgl_garantir_logs')) {
    sgl_garantir_logs($conn);
}

/*
 * A estrutura Multi-Tenant da tabela processos é mantida por migração SQL.
 * DDL e backfills próprios de processos não são executados durante requisições HTTP.
 * Migração: migrations/20260718_ra06_processos_runtime_ddl.sql
 */

$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}
function gerarIdProcesso(mysqli $conn): string {
    $res = $conn->query("SELECT id FROM processos WHERE id LIKE 'PRC%' ORDER BY CAST(SUBSTRING(id,4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'PRC001';
    $num = (int)substr($res->fetch_assoc()['id'], 3) + 1;
    return 'PRC' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}
function brlParaFloat(string $valor): float {
    $v = trim(str_replace(['R$', ' '], '', $valor));
    if ($v === '') return 0.0;
    if (str_contains($v, ',')) $v = str_replace(',', '.', str_replace('.', '', $v));
    return is_numeric($v) ? (float)$v : 0.0;
}
function brl($valor): string { return 'R$ ' . number_format((float)$valor, 2, ',', '.'); }
function dataBr($data): string { return $data ? date('d/m/Y', strtotime($data)) : '-'; }
function registrarLog(
    mysqli $conn,
    string $acao,
    string $registroId,
    string $detalhes = '',
    array $contexto = []
): void {
    if (function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $conn,
            $acao,
            'processos',
            $registroId,
            $detalhes,
            array_merge(
                [
                    'modulo' => 'Processos',
                    'origem' => 'Módulo Processos',
                    'resultado' => 'SUCESSO',
                    'nivel' => 'INFO',
                ],
                $contexto
            )
        );
    }
}

function buscarProcessoAuditoria(mysqli $conn, string $tenantId, int $escritorioId, string $id): ?array {
    $stmt = $conn->prepare(
        "SELECT *
           FROM processos
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
          LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('sis', $tenantId, $escritorioId, $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $processo = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $processo;
}

function processoExiste(
    mysqli $conn,
    string $tenantId,
    int $escritorioId,
    string $numero,
    string $ignorarId = ''
): bool {
    $sql = "SELECT id
              FROM processos
             WHERE tenant_id = ?
               AND escritorio_id = ?
               AND numero_processo = ?
               AND status != 'Excluído'";

    $params = [$tenantId, $escritorioId, $numero];
    $types = 'sis';

    if ($ignorarId !== '') {
        $sql .= " AND id <> ?";
        $params[] = $ignorarId;
        $types .= 's';
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $existe = $res && $res->num_rows > 0;
    $stmt->close();

    return $existe;
}

function clientePertenceTenant(mysqli $conn, string $tenantId, int $escritorioId, string $clienteId): bool {
    $stmt = $conn->prepare(
        "SELECT id
           FROM clientes
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
            AND deletado = 0
            AND status != 'Excluído'
          LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sis', $tenantId, $escritorioId, $clienteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function advogadoPertenceTenant(mysqli $conn, string $tenantId, int $escritorioId, string $advogadoId): bool {
    $stmt = $conn->prepare(
        "SELECT id
           FROM advogados
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
            AND deletado = 0
            AND status = 'Ativo'
          LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sis', $tenantId, $escritorioId, $advogadoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['salvar_processo']) || isset($_POST['atualizar_processo']))) {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Sessão expirada. Atualize a página e tente novamente.</div>';
        $acao = isset($_POST['atualizar_processo']) ? 'editar' : 'novo';
    } else {
        $id                = isset($_POST['atualizar_processo']) ? trim($_POST['id'] ?? '') : gerarIdProcesso($conn);
        $numero_processo   = trim($_POST['numero_processo'] ?? '');
        $cliente_id        = trim($_POST['cliente_id'] ?? '');
        $tipo_processo     = trim($_POST['tipo_processo'] ?? '');
        $vara              = trim($_POST['vara'] ?? '');
        $comarca           = trim($_POST['comarca'] ?? '');
        $advogado_id       = trim($_POST['advogado_id'] ?? '');

        if ($advogado_id === '') {
            $advogado_id = null;
        } elseif (!advogadoPertenceTenant($conn, $tenantId, $escritorioId, $advogado_id)) {
            $advogado_id = null;
        }
        $data_distribuicao = trim($_POST['data_distribuicao'] ?? '') ?: null;
        $fase_atual        = trim($_POST['fase_atual'] ?? '');
        $valor_causa       = brlParaFloat($_POST['valor_causa'] ?? '0');
        $proximo_prazo     = trim($_POST['proximo_prazo'] ?? '') ?: null;
        $status            = trim($_POST['status'] ?? 'Em Andamento');
        $observacoes       = trim($_POST['observacoes'] ?? '');

        if ($numero_processo === '' || $cliente_id === '') {
            $msg = '<div class="alert alert-danger">Informe o número do processo e selecione o cliente.</div>';
            $acao = isset($_POST['atualizar_processo']) ? 'editar' : 'novo';
        } elseif (!clientePertenceTenant($conn, $tenantId, $escritorioId, $cliente_id)) {
            $msg = '<div class="alert alert-danger">O cliente selecionado não pertence ao escritório ativo.</div>';
            $acao = isset($_POST['atualizar_processo']) ? 'editar' : 'novo';
        } elseif (processoExiste($conn, $tenantId, $escritorioId, $numero_processo, isset($_POST['atualizar_processo']) ? $id : '')) {
            $msg = '<div class="alert alert-warning">Já existe um processo ativo com este número.</div>';
            $acao = isset($_POST['atualizar_processo']) ? 'editar' : 'novo';
        } else {
            if (isset($_POST['salvar_processo'])) {
                $stmt = $conn->prepare(
                    "INSERT INTO processos (
                        id, tenant_id, escritorio_id, numero_processo, cliente_id,
                        tipo_processo, vara, comarca, advogado_id, data_distribuicao,
                        fase_atual, valor_causa, proximo_prazo, status, observacoes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    'ssissssssssdsss',
                    $id,
                    $tenantId,
                    $escritorioId,
                    $numero_processo,
                    $cliente_id,
                    $tipo_processo,
                    $vara,
                    $comarca,
                    $advogado_id,
                    $data_distribuicao,
                    $fase_atual,
                    $valor_causa,
                    $proximo_prazo,
                    $status,
                    $observacoes
                );
                $ok = $stmt->execute();
                if ($ok) {
                    registrarLog(
                        $conn,
                        'Processo incluído',
                        $id,
                        'Novo processo cadastrado: ' . $numero_processo,
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Cadastro de processos',
                            'dados_novos' => buscarProcessoAuditoria($conn, $tenantId, $escritorioId, $id),
                        ]
                    );
                    $msg = "<div class='alert alert-success'>Processo <strong>".h($id)."</strong> cadastrado com sucesso.</div>";
                    $acao='listar';
                } else {
                    registrarLog(
                        $conn,
                        'Falha ao incluir processo',
                        $id,
                        'Não foi possível cadastrar o processo: ' . $numero_processo,
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Cadastro de processos',
                            'resultado' => 'FALHA',
                            'nivel' => 'ERRO',
                            'dados_novos' => [
                                'numero_processo' => $numero_processo,
                                'cliente_id' => $cliente_id,
                                'advogado_id' => $advogado_id,
                                'valor_causa' => $valor_causa,
                                'status' => $status,
                            ],
                        ]
                    );
                    $msg = '<div class="alert alert-danger">Não foi possível salvar o processo.</div>';
                    $acao='novo';
                }
            } else {
                $dadosAnteriores = buscarProcessoAuditoria($conn, $tenantId, $escritorioId, $id);

                $stmt = $conn->prepare(
                    "UPDATE processos
                        SET numero_processo = ?,
                            cliente_id = ?,
                            tipo_processo = ?,
                            vara = ?,
                            comarca = ?,
                            advogado_id = ?,
                            data_distribuicao = ?,
                            fase_atual = ?,
                            valor_causa = ?,
                            proximo_prazo = ?,
                            status = ?,
                            observacoes = ?
                      WHERE tenant_id = ?
                        AND escritorio_id = ?
                        AND id = ?
                        AND status != 'Excluído'"
                );
                $stmt->bind_param(
                    'ssssssssdssssis',
                    $numero_processo,
                    $cliente_id,
                    $tipo_processo,
                    $vara,
                    $comarca,
                    $advogado_id,
                    $data_distribuicao,
                    $fase_atual,
                    $valor_causa,
                    $proximo_prazo,
                    $status,
                    $observacoes,
                    $tenantId,
                    $escritorioId,
                    $id
                );
                $ok = $stmt->execute();

                if ($ok) {
                    registrarLog(
                        $conn,
                        'Processo atualizado',
                        $id,
                        'Dados do processo atualizados: ' . $numero_processo,
                        [
                            'tipo_acao' => 'EDICAO',
                            'origem' => 'Edição de processos',
                            'dados_anteriores' => $dadosAnteriores,
                            'dados_novos' => buscarProcessoAuditoria($conn, $tenantId, $escritorioId, $id),
                        ]
                    );
                    $msg = "<div class='alert alert-success'>Processo <strong>".h($id)."</strong> atualizado com sucesso.</div>";
                    $acao='listar';
                } else {
                    registrarLog(
                        $conn,
                        'Falha ao atualizar processo',
                        $id,
                        'Não foi possível atualizar o processo: ' . $numero_processo,
                        [
                            'tipo_acao' => 'EDICAO',
                            'origem' => 'Edição de processos',
                            'resultado' => 'FALHA',
                            'nivel' => 'ERRO',
                            'dados_anteriores' => $dadosAnteriores,
                            'dados_novos' => [
                                'numero_processo' => $numero_processo,
                                'cliente_id' => $cliente_id,
                                'advogado_id' => $advogado_id,
                                'valor_causa' => $valor_causa,
                                'status' => $status,
                            ],
                        ]
                    );
                    $msg = '<div class="alert alert-danger">Não foi possível atualizar o processo.</div>';
                    $acao='editar';
                }
            }
        }
    }
}

if (isset($_GET['excluir'])) {
    $id = trim($_GET['excluir']);
    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token inválido para exclusão.</div>';
    } else {
        $dadosAnteriores = buscarProcessoAuditoria($conn, $tenantId, $escritorioId, $id);

        $stmt = $conn->prepare(
            "UPDATE processos
                SET status = 'Excluído'
              WHERE tenant_id = ?
                AND escritorio_id = ?
                AND id = ?
                AND status != 'Excluído'"
        );
        $stmt->bind_param('sis', $tenantId, $escritorioId, $id);
        $okExcluir = $stmt->execute();
        $linhasAfetadas = $stmt->affected_rows;
        $stmt->close();

        if ($okExcluir && $linhasAfetadas > 0) {
            registrarLog(
                $conn,
                'Processo movido para a lixeira',
                $id,
                'Exclusão lógica do processo: ' . (string)($dadosAnteriores['numero_processo'] ?? $id),
                [
                    'tipo_acao' => 'EXCLUSAO',
                    'origem' => 'Lista de processos',
                    'nivel' => 'AVISO',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => buscarProcessoAuditoria($conn, $tenantId, $escritorioId, $id),
                ]
            );
            $msg = '<div class="alert alert-warning">Processo movido para a lixeira com sucesso.</div>';
        } else {
            registrarLog(
                $conn,
                'Falha ao mover processo para a lixeira',
                $id,
                'O registro não foi alterado.',
                [
                    'tipo_acao' => 'EXCLUSAO',
                    'origem' => 'Lista de processos',
                    'resultado' => 'FALHA',
                    'nivel' => 'ERRO',
                    'dados_anteriores' => $dadosAnteriores,
                ]
            );
            $msg = '<div class="alert alert-danger">Não foi possível mover o processo para a lixeira.</div>';
        }
    }
    $acao = 'listar';
}

$processo_editar = null;
if ($acao === 'editar' && isset($_GET['id'])) {
    $id_editar = trim($_GET['id']);
    $stmt = $conn->prepare(
        "SELECT *
           FROM processos
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
            AND status != 'Excluído'
          LIMIT 1"
    );
    $stmt->bind_param('sis', $tenantId, $escritorioId, $id_editar);
    $stmt->execute();
    $processo_editar = $stmt->get_result()->fetch_assoc();
    if (!$processo_editar) { $msg = '<div class="alert alert-danger">Processo não encontrado.</div>'; $acao = 'listar'; }
}

$f = [
    'numero_processo'=>$processo_editar['numero_processo']??'', 'cliente_id'=>$processo_editar['cliente_id']??'',
    'tipo_processo'=>$processo_editar['tipo_processo']??'Trabalhista', 'vara'=>$processo_editar['vara']??'', 'comarca'=>$processo_editar['comarca']??'',
    'advogado_id'=>$processo_editar['advogado_id']??'', 'data_distribuicao'=>$processo_editar['data_distribuicao']??'',
    'fase_atual'=>$processo_editar['fase_atual']??'', 'valor_causa'=>$processo_editar['valor_causa']??'', 'proximo_prazo'=>$processo_editar['proximo_prazo']??'',
    'status'=>$processo_editar['status']??'Em Andamento', 'observacoes'=>$processo_editar['observacoes']??''
];
$valor_form = ((float)$f['valor_causa'] > 0) ? brl($f['valor_causa']) : '';
$csrf = gerarTokenCsrf();
$stmtClientes = $conn->prepare(
    "SELECT id, nome
      FROM clientes
      WHERE tenant_id = ?
        AND escritorio_id = ?
        AND deletado = 0
        AND status != 'Excluído'
      ORDER BY nome"
);
$stmtClientes->bind_param('si', $tenantId, $escritorioId);
$stmtClientes->execute();
$clientes = $stmtClientes->get_result();

$stmtAdvogados = $conn->prepare(
    "SELECT id, nome
      FROM advogados
      WHERE tenant_id = ?
        AND escritorio_id = ?
        AND deletado = 0
        AND status = 'Ativo'
      ORDER BY nome"
);
$stmtAdvogados->bind_param('si', $tenantId, $escritorioId);
$stmtAdvogados->execute();
$advogados = $stmtAdvogados->get_result();
$tipos_processo = ['Trabalhista','Civil','Criminal','Família','Tributário','Previdenciário','Imobiliário','Consumidor','Empresarial','Outro'];
$statuses_proc  = ['Em Andamento','Suspenso','Arquivado','Encerrado'];

$stmtCards = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Em Andamento'), 0) AS ativos,
        COALESCE(SUM(
            proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status = 'Em Andamento'
        ), 0) AS prazos,
        COALESCE(SUM(valor_causa), 0) AS valor_total
     FROM processos
     WHERE tenant_id = ?
       AND escritorio_id = ?
       AND status != 'Excluído'"
);
$stmtCards->bind_param('si', $tenantId, $escritorioId);
$stmtCards->execute();
$cards = $stmtCards->get_result()->fetch_assoc() ?: [
    'total' => 0,
    'ativos' => 0,
    'prazos' => 0,
    'valor_total' => 0,
];
$stmtCards->close();
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div><h2 class="mb-1"><i class="bi bi-folder2-open"></i> Processos</h2><p class="text-muted mb-0">Gestão, prazos e acompanhamento dos processos do escritório.</p></div>
    <a href="?mod=processos&acao=novo" class="btn btn-primary mt-2 mt-md-0"><i class="bi bi-plus-circle"></i> Novo Processo</a>
</div>
<?= $msg ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">TOTAL DE PROCESSOS</small><h3><?= (int)$cards['total'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">EM ANDAMENTO</small><h3 class="text-primary"><?= (int)$cards['ativos'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">PRAZOS EM 7 DIAS</small><h3 class="text-warning"><?= (int)$cards['prazos'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><small class="text-muted">VALOR DAS CAUSAS</small><h3><?= brl($cards['valor_total']) ?></h3></div></div></div>
</div>

<script>
function mascaraMoeda(input){let v=input.value.replace(/\D/g,''); if(!v){input.value='';return;} v=(parseInt(v,10)/100).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.'); input.value='R$ '+v;}
function limparMoedaAntesDeSalvar(form){const c=form.querySelector('[name="valor_causa"]'); if(c)c.value=c.value.replace(/[R$\s.]/g,'').replace(',','.'); return true;}
</script>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-bold">
        <?= $acao === 'editar' ? 'Editar Processo — ' . h($processo_editar['id'] ?? '') : 'Cadastrar Novo Processo' ?>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off" onsubmit="return limparMoedaAntesDeSalvar(this)">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <?php if ($acao === 'editar'): ?><input type="hidden" name="id" value="<?= h($processo_editar['id'] ?? '') ?>"><?php endif; ?>
            <div class="alert alert-light border"><i class="bi bi-info-circle"></i> Campos marcados com * são obrigatórios. O sistema bloqueia número de processo duplicado.</div>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Nº Processo *</label><input type="text" name="numero_processo" class="form-control" value="<?= h($f['numero_processo']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Cliente *</label><select name="cliente_id" class="form-select" required><option value="">-- Selecione --</option><?php if($clientes): while($c=$clientes->fetch_assoc()): ?><option value="<?= h($c['id']) ?>" <?= $f['cliente_id']===$c['id']?'selected':'' ?>><?= h($c['nome']) ?></option><?php endwhile; endif; ?></select></div>
                <div class="col-md-4"><label class="form-label">Tipo</label><select name="tipo_processo" class="form-select"><?php foreach($tipos_processo as $tp): ?><option <?= $f['tipo_processo']===$tp?'selected':'' ?>><?= h($tp) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Vara / Tribunal</label><input type="text" name="vara" class="form-control" value="<?= h($f['vara']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Comarca</label><input type="text" name="comarca" class="form-control" value="<?= h($f['comarca']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Advogado Responsável</label><select name="advogado_id" class="form-select"><option value="">-- Selecione --</option><?php if($advogados): while($a=$advogados->fetch_assoc()): ?><option value="<?= h($a['id']) ?>" <?= $f['advogado_id']===$a['id']?'selected':'' ?>><?= h($a['nome']) ?></option><?php endwhile; endif; ?></select></div>
                <div class="col-md-3"><label class="form-label">Data Distribuição</label><input type="date" name="data_distribuicao" class="form-control" value="<?= h($f['data_distribuicao']) ?>"></div>
                <div class="col-md-3"><label class="form-label">Valor da Causa</label><input type="text" name="valor_causa" class="form-control" placeholder="R$ 0,00" value="<?= h($valor_form) ?>" oninput="mascaraMoeda(this)" inputmode="numeric"></div>
                <div class="col-md-3"><label class="form-label">Próximo Prazo</label><input type="date" name="proximo_prazo" class="form-control" value="<?= h($f['proximo_prazo']) ?>"></div>
                <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach($statuses_proc as $st): ?><option <?= $f['status']===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label">Fase Atual</label><input type="text" name="fase_atual" class="form-control" value="<?= h($f['fase_atual']) ?>" placeholder="Ex.: Inicial, Instrução, Recurso, Execução..."></div>
                <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="3"><?= h($f['observacoes']) ?></textarea></div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" name="<?= $acao === 'editar' ? 'atualizar_processo' : 'salvar_processo' ?>" class="btn <?= $acao === 'editar' ? 'btn-warning' : 'btn-success' ?>"><i class="bi bi-save"></i> Salvar</button>
                <a href="?mod=processos" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<?php
$busca = trim($_GET['busca'] ?? ''); $statusFiltro = trim($_GET['status'] ?? ''); $tipoFiltro = trim($_GET['tipo'] ?? ''); $prazoFiltro = trim($_GET['prazo'] ?? '');
$where = "WHERE p.tenant_id = ? AND p.escritorio_id = ? AND p.status != 'Excluído'";
$params = [$tenantId, $escritorioId];
$types = 'si';
if($busca!==''){ $like="%$busca%"; $where.=" AND (p.numero_processo LIKE ? OR c.nome LIKE ? OR p.tipo_processo LIKE ? OR p.comarca LIKE ? OR p.fase_atual LIKE ?)"; array_push($params,$like,$like,$like,$like,$like); $types.='sssss'; }
if($statusFiltro!==''){ $where.=" AND p.status=?"; $params[]=$statusFiltro; $types.='s'; }
if($tipoFiltro!==''){ $where.=" AND p.tipo_processo=?"; $params[]=$tipoFiltro; $types.='s'; }
if($prazoFiltro==='7'){ $where.=" AND p.proximo_prazo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"; }
if($prazoFiltro==='vencido'){ $where.=" AND p.proximo_prazo < CURDATE() AND p.status='Em Andamento'"; }
$sql = "SELECT
            p.id,
            p.numero_processo,
            c.nome AS cliente_nome,
            p.tipo_processo,
            p.vara,
            p.comarca,
            p.valor_causa,
            p.proximo_prazo,
            p.status,
            p.fase_atual
        FROM processos p
        LEFT JOIN clientes c
               ON c.id = p.cliente_id
              AND c.tenant_id = p.tenant_id
              AND c.escritorio_id = p.escritorio_id
        {$where}
        ORDER BY COALESCE(p.proximo_prazo, '2999-12-31') ASC, p.id DESC
        LIMIT 300";
$stmt = $conn->prepare($sql); if($params) $stmt->bind_param($types, ...$params); $stmt->execute(); $lista=$stmt->get_result();
?>
<div class="card shadow-sm border-0 mb-3"><div class="card-body"><form class="row g-2" method="GET"><input type="hidden" name="mod" value="processos"><div class="col-md-4"><label class="form-label">Pesquisa inteligente</label><input type="text" name="busca" class="form-control" placeholder="Número, cliente, tipo, comarca ou fase" value="<?= h($busca) ?>"></div><div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><?php foreach($statuses_proc as $st): ?><option value="<?= h($st) ?>" <?= $statusFiltro===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="">Todos</option><?php foreach($tipos_processo as $tp): ?><option value="<?= h($tp) ?>" <?= $tipoFiltro===$tp?'selected':'' ?>><?= h($tp) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Prazo</label><select name="prazo" class="form-select"><option value="">Todos</option><option value="7" <?= $prazoFiltro==='7'?'selected':'' ?>>Próx. 7 dias</option><option value="vencido" <?= $prazoFiltro==='vencido'?'selected':'' ?>>Vencidos</option></select></div><div class="col-md-2 d-flex align-items-end gap-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Buscar</button><a href="?mod=processos" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a></div></form></div></div>
<div class="card shadow-sm border-0"><div class="card-header bg-dark text-white d-flex justify-content-between"><strong><i class="bi bi-list-ul"></i> Lista de Processos</strong><span><?= $lista->num_rows ?> registro(s)</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>ID</th><th>Nº Processo</th><th>Cliente</th><th>Tipo/Fase</th><th>Comarca</th><th>Valor</th><th>Prazo</th><th>Status</th><th class="text-end">Ações</th></tr></thead><tbody><?php if($lista->num_rows): while($row=$lista->fetch_assoc()): $badge=match($row['status']){'Em Andamento'=>'primary','Suspenso'=>'warning','Encerrado'=>'success','Arquivado'=>'secondary',default=>'secondary'}; $prazoClass=(!empty($row['proximo_prazo']) && $row['proximo_prazo'] < date('Y-m-d') && $row['status']==='Em Andamento')?'text-danger fw-bold':''; ?><tr><td><?= h($row['id']) ?></td><td class="fw-semibold"><?= h($row['numero_processo']) ?></td><td><?= h($row['cliente_nome'] ?? '-') ?></td><td><?= h($row['tipo_processo']) ?><br><small class="text-muted"><?= h($row['fase_atual'] ?: '-') ?></small></td><td><?= h($row['comarca'] ?: '-') ?></td><td><?= brl($row['valor_causa']) ?></td><td class="<?= $prazoClass ?>"><?= dataBr($row['proximo_prazo']) ?></td><td><span class="badge bg-<?= $badge ?>"><?= h($row['status']) ?></span></td><td class="text-end text-nowrap"><a href="?mod=processos&acao=editar&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a> <a href="?mod=processos&excluir=<?= urlencode($row['id']) ?>&csrf_token=<?= h($csrf) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Deseja mover este processo para a lixeira?')"><i class="bi bi-trash"></i></a></td></tr><?php endwhile; else: ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum processo encontrado.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php
if (isset($stmtClientes) && $stmtClientes instanceof mysqli_stmt) {
    $stmtClientes->close();
}
if (isset($stmtAdvogados) && $stmtAdvogados instanceof mysqli_stmt) {
    $stmtAdvogados->close();
}
$conn->close();
?>
