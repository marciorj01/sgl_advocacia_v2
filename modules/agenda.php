<?php
/**
 * Módulo Agenda — Fase 2.5
 * Gestão profissional de compromissos, prazos, audiências e atendimentos.
 */

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    $conn->close();
    throw new RuntimeException('Contexto Multi-Tenant inválido para o módulo Agenda.');
}

$tenantId = function_exists('rojexTenantId')
    ? (string)rojexTenantId()
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    $conn->close();
    throw new RuntimeException('Tenant ou escritório não identificado para o módulo Agenda.');
}

/*
 * Verificação somente de leitura usada para compatibilidade das consultas.
 * A estrutura da Agenda é mantida pela migração:
 * migrations/20260718_ra06_agenda_runtime_ddl.sql
 */
if (!function_exists('sgl_coluna_existe')) {
    function sgl_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool {
        $tabelaSegura = $conn->real_escape_string($tabela);
        $colunaSegura = $conn->real_escape_string($coluna);
        $res = $conn->query("SHOW COLUMNS FROM `{$tabelaSegura}` LIKE '{$colunaSegura}'");
        return $res && $res->num_rows > 0;
    }
}

$agendaTemDeletado = sgl_coluna_existe($conn, 'agenda', 'deletado');
$clientesTemDeletado = sgl_coluna_existe($conn, 'clientes', 'deletado');
$advogadosTemDeletado = sgl_coluna_existe($conn, 'advogados', 'deletado');
$processosTemDeletado = sgl_coluna_existe($conn, 'processos', 'deletado');

$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

if (!function_exists('h')) {
    function h($valor): string {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function gerarIdAgenda(mysqli $conn): string {
    $res = $conn->query("SELECT id FROM agenda WHERE id LIKE 'AGE%' ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        return 'AGE001';
    }
    $ultimo = $res->fetch_assoc()['id'];
    $num = (int) substr($ultimo, 3) + 1;
    return 'AGE' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

function camposAgenda(array $d = []): array {
    return [
        'data_evento'      => trim($d['data_evento'] ?? ''),
        'horario'          => trim($d['horario'] ?? ''),
        'tipo_compromisso' => trim($d['tipo_compromisso'] ?? 'Audiência'),
        'cliente_id'       => trim($d['cliente_id'] ?? ''),
        'nome_cliente'     => trim($d['nome_cliente'] ?? ''),
        'numero_processo'  => trim($d['numero_processo'] ?? ''),
        'local'            => trim($d['local'] ?? ''),
        'advogado_id'      => trim($d['advogado_id'] ?? ''),
        'status'           => trim($d['status'] ?? 'Pendente'),
        'prazo_fatal'      => trim($d['prazo_fatal'] ?? 'Não'),
        'observacoes'      => trim($d['observacoes'] ?? ''),
    ];
}

function validarAgenda(array $c): array {
    $erros = [];
    $tiposValidos = ['Audiência','Reunião','Prazo','Atendimento','Perícia','Sustentação Oral','Lembrete','Outro'];
    $statusValidos = ['Pendente','Confirmado','Realizado','Cancelado'];

    if ($c['data_evento'] === '') {
        $erros[] = 'Informe a data do compromisso.';
    }
    if ($c['data_evento'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['data_evento'])) {
        $erros[] = 'A data informada é inválida.';
    }
    if ($c['horario'] !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $c['horario'])) {
        $erros[] = 'O horário informado é inválido.';
    }
    if (!in_array($c['tipo_compromisso'], $tiposValidos, true)) {
        $erros[] = 'Tipo de compromisso inválido.';
    }
    if (!in_array($c['status'], $statusValidos, true)) {
        $erros[] = 'Status inválido.';
    }
    if (!in_array($c['prazo_fatal'], ['Sim','Não'], true)) {
        $erros[] = 'Prazo fatal inválido.';
    }
    return $erros;
}

function buscarAgendaAuditoria(mysqli $conn, string $tenantId, int $escritorioId, string $id): ?array {
    $stmt = $conn->prepare(
        "SELECT *
           FROM agenda
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
          LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("sis", $tenantId, $escritorioId, $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $evento = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $evento;
}

function agendaClientePertenceTenant(mysqli $conn, string $tenantId, int $escritorioId, ?string $clienteId): bool {
    if ($clienteId === null || $clienteId === '') {
        return true;
    }

    $stmt = $conn->prepare(
        "SELECT id
           FROM clientes
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
            AND deletado = 0
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

function agendaAdvogadoPertenceTenant(mysqli $conn, string $tenantId, int $escritorioId, ?string $advogadoId): bool {
    if ($advogadoId === null || $advogadoId === '') {
        return true;
    }

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

function agendaProcessoPertenceEscritorio(
    mysqli $conn,
    string $tenantId,
    int $escritorioId,
    string $numeroProcesso,
    ?string $clienteId
): bool {
    if ($numeroProcesso === '') {
        return true;
    }
    if ($clienteId === null || $clienteId === '') {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT id
           FROM processos
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND numero_processo = ?
            AND cliente_id = ?
            AND status != 'Excluído'
          LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('siss', $tenantId, $escritorioId, $numeroProcesso, $clienteId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function registrarLogAgenda(
    mysqli $conn,
    string $acao,
    string $registroId,
    string $detalhes,
    array $contexto = []
): void {
    if (!function_exists('sgl_registrar_log')) {
        return;
    }

    sgl_registrar_log(
        $conn,
        $acao,
        'agenda',
        $registroId,
        $detalhes,
        array_merge(
            [
                'modulo' => 'Agenda',
                'origem' => 'Módulo Agenda',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ],
            $contexto
        )
    );
}

function redirecionarAgenda(string $params): void {
    echo "<script>window.location.href='?mod=agenda{$params}';</script>";
    exit;
}

if (isset($_GET['msg_sucesso'])) {
    $msg = "<div class='alert alert-success'>✅ " . h($_GET['msg_sucesso']) . "</div>";
}
if (isset($_GET['msg_erro'])) {
    $msg = "<div class='alert alert-danger'>❌ " . h($_GET['msg_erro']) . "</div>";
}

$csrfAgenda = gerarTokenCsrf();

/* Ações protegidas */
if (isset($_GET['excluir'])) {
    $id = trim((string)$_GET['excluir']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogAgenda(
            $conn,
            'Tentativa inválida de excluir compromisso',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'EXCLUSAO',
                'origem' => 'Lista da agenda',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        redirecionarAgenda('&msg_erro=' . urlencode('Ação bloqueada por segurança.'));
    }

    $dadosAnteriores = buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id);

    try {
        $stmt = $conn->prepare(
            "UPDATE agenda
                SET deletado = 1
              WHERE tenant_id = ?
                AND escritorio_id = ?
                AND id = ?
                AND deletado = 0"
        );
        $stmt->bind_param("sis", $tenantId, $escritorioId, $id);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $afetadas < 1) {
            throw new RuntimeException('Nenhum registro foi alterado.');
        }

        registrarLogAgenda(
            $conn,
            'Compromisso movido para a lixeira',
            $id,
            'Exclusão lógica do compromisso de agenda.',
            [
                'tipo_acao' => 'EXCLUSAO',
                'origem' => 'Lista da agenda',
                'nivel' => 'AVISO',
                'dados_anteriores' => $dadosAnteriores,
                'dados_novos' => buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id),
            ]
        );

        redirecionarAgenda('&msg_sucesso=' . urlencode('Compromisso movido para a lixeira.'));
    } catch (Throwable $e) {
        registrarLogAgenda(
            $conn,
            'Falha ao mover compromisso para a lixeira',
            $id,
            'O compromisso não foi alterado.',
            [
                'tipo_acao' => 'EXCLUSAO',
                'origem' => 'Lista da agenda',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_anteriores' => $dadosAnteriores,
            ]
        );
        redirecionarAgenda('&msg_erro=' . urlencode('Erro ao mover compromisso para a lixeira.'));
    }
}

if (isset($_GET['restaurar'])) {
    $id = trim((string)$_GET['restaurar']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogAgenda(
            $conn,
            'Tentativa inválida de restaurar compromisso',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'RESTAURACAO',
                'origem' => 'Lixeira da agenda',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        redirecionarAgenda('&acao=lixeira&msg_erro=' . urlencode('Ação bloqueada por segurança.'));
    }

    $dadosAnteriores = buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id);

    try {
        $stmt = $conn->prepare(
            "UPDATE agenda
                SET deletado = 0
              WHERE tenant_id = ?
                AND escritorio_id = ?
                AND id = ?
                AND deletado = 1"
        );
        $stmt->bind_param("sis", $tenantId, $escritorioId, $id);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $afetadas < 1) {
            throw new RuntimeException('Nenhum registro foi alterado.');
        }

        registrarLogAgenda(
            $conn,
            'Compromisso restaurado',
            $id,
            'Compromisso restaurado da lixeira.',
            [
                'tipo_acao' => 'RESTAURACAO',
                'origem' => 'Lixeira da agenda',
                'dados_anteriores' => $dadosAnteriores,
                'dados_novos' => buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id),
            ]
        );

        redirecionarAgenda('&acao=lixeira&msg_sucesso=' . urlencode('Compromisso restaurado.'));
    } catch (Throwable $e) {
        registrarLogAgenda(
            $conn,
            'Falha ao restaurar compromisso',
            $id,
            'O compromisso não foi restaurado.',
            [
                'tipo_acao' => 'RESTAURACAO',
                'origem' => 'Lixeira da agenda',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_anteriores' => $dadosAnteriores,
            ]
        );
        redirecionarAgenda('&acao=lixeira&msg_erro=' . urlencode('Erro ao restaurar compromisso.'));
    }
}

if (isset($_GET['excluir_permanente'])) {
    $id = trim((string)$_GET['excluir_permanente']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogAgenda(
            $conn,
            'Tentativa inválida de exclusão permanente',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                'origem' => 'Lixeira da agenda',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        redirecionarAgenda('&acao=lixeira&msg_erro=' . urlencode('Ação bloqueada por segurança.'));
    }

    $dadosAnteriores = buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id);

    try {
        $stmt = $conn->prepare(
            "DELETE FROM agenda
              WHERE tenant_id = ?
                AND escritorio_id = ?
                AND id = ?
                AND deletado = 1"
        );
        $stmt->bind_param("sis", $tenantId, $escritorioId, $id);
        $ok = $stmt->execute();
        $afetadas = $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $afetadas < 1) {
            throw new RuntimeException('Nenhum registro elegível foi excluído.');
        }

        registrarLogAgenda(
            $conn,
            'Compromisso excluído permanentemente',
            $id,
            'Exclusão definitiva de compromisso previamente enviado à lixeira.',
            [
                'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                'origem' => 'Lixeira da agenda',
                'nivel' => 'AVISO',
                'dados_anteriores' => $dadosAnteriores,
                'dados_novos' => null,
            ]
        );

        redirecionarAgenda('&acao=lixeira&msg_sucesso=' . urlencode('Compromisso excluído permanentemente.'));
    } catch (Throwable $e) {
        registrarLogAgenda(
            $conn,
            'Falha na exclusão permanente de compromisso',
            $id,
            'O compromisso não foi excluído permanentemente.',
            [
                'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                'origem' => 'Lixeira da agenda',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
                'dados_anteriores' => $dadosAnteriores,
            ]
        );
        redirecionarAgenda('&acao=lixeira&msg_erro=' . urlencode('Não foi possível excluir permanentemente este compromisso.'));
    }
}

/* Salvar / atualizar */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">❌ Token de segurança inválido. Atualize a página e tente novamente.</div>';
        $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
    } else {
        $dados = camposAgenda($_POST);
        $erros = validarAgenda($dados);

        if ($erros) {
            $msg = '<div class="alert alert-danger">❌ ' . h(implode(' ', $erros)) . '</div>';
            $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
        } else {
            $clienteId = $dados['cliente_id'] === '' ? null : $dados['cliente_id'];
            $advogadoId = $dados['advogado_id'] === '' ? null : $dados['advogado_id'];
            $horario = $dados['horario'] === '' ? null : $dados['horario'];

            if (!agendaClientePertenceTenant($conn, $tenantId, $escritorioId, $clienteId)) {
                $msg = '<div class="alert alert-danger">❌ O cliente selecionado não pertence ao escritório ativo.</div>';
                $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
            } elseif (!agendaAdvogadoPertenceTenant($conn, $tenantId, $escritorioId, $advogadoId)) {
                $msg = '<div class="alert alert-danger">❌ O advogado selecionado não pertence ao escritório ativo.</div>';
                $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
            } elseif (!agendaProcessoPertenceEscritorio(
                $conn,
                $tenantId,
                $escritorioId,
                $dados['numero_processo'],
                $clienteId
            )) {
                $msg = '<div class="alert alert-danger">❌ O processo selecionado não pertence ao cliente e ao escritório ativos.</div>';
                $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
            } else try {
                if (isset($_POST['salvar_evento'])) {
                    $id = gerarIdAgenda($conn);
                    $sql = "INSERT INTO agenda
                            (
                                id, tenant_id, escritorio_id, data_evento, horario,
                                tipo_compromisso, cliente_id, nome_cliente, numero_processo,
                                `local`, advogado_id, status, prazo_fatal, observacoes, deletado
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "ssisssssssssss",
                        $id,
                        $tenantId,
                        $escritorioId,
                        $dados['data_evento'],
                        $horario,
                        $dados['tipo_compromisso'],
                        $clienteId,
                        $dados['nome_cliente'],
                        $dados['numero_processo'],
                        $dados['local'],
                        $advogadoId,
                        $dados['status'],
                        $dados['prazo_fatal'],
                        $dados['observacoes']
                    );
                    $stmt->execute();
                    $stmt->close();

                    registrarLogAgenda(
                        $conn,
                        'Compromisso incluído',
                        $id,
                        'Novo compromisso cadastrado na agenda.',
                        [
                            'tipo_acao' => 'INCLUSAO',
                            'origem' => 'Cadastro da agenda',
                            'dados_novos' => buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id),
                        ]
                    );

                    redirecionarAgenda('&msg_sucesso=' . urlencode("Compromisso {$id} cadastrado com sucesso."));
                }

                if (isset($_POST['atualizar_evento'])) {
                    $id = trim($_POST['id'] ?? '');
                    $dadosAnteriores = buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id);

                    $sql = "UPDATE agenda SET
                                data_evento = ?, horario = ?, tipo_compromisso = ?, cliente_id = ?, nome_cliente = ?,
                                numero_processo = ?, `local` = ?, advogado_id = ?, status = ?, prazo_fatal = ?, observacoes = ?
                            WHERE tenant_id = ?
                              AND escritorio_id = ?
                              AND id = ?
                              AND deletado = 0";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "ssssssssssssis",
                        $dados['data_evento'],
                        $horario,
                        $dados['tipo_compromisso'],
                        $clienteId,
                        $dados['nome_cliente'],
                        $dados['numero_processo'],
                        $dados['local'],
                        $advogadoId,
                        $dados['status'],
                        $dados['prazo_fatal'],
                        $dados['observacoes'],
                        $tenantId,
                        $escritorioId,
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();

                    registrarLogAgenda(
                        $conn,
                        'Compromisso atualizado',
                        $id,
                        'Dados do compromisso atualizados.',
                        [
                            'tipo_acao' => 'EDICAO',
                            'origem' => 'Edição da agenda',
                            'dados_anteriores' => $dadosAnteriores,
                            'dados_novos' => buscarAgendaAuditoria($conn, $tenantId, $escritorioId, $id),
                        ]
                    );

                    redirecionarAgenda('&msg_sucesso=' . urlencode("Compromisso {$id} atualizado com sucesso."));
                }
            } catch (mysqli_sql_exception $e) {
                $msg = '<div class="alert alert-danger">❌ Erro ao salvar compromisso. Verifique se a migração da Fase 2.5 foi importada.</div>';
                $acao = isset($_POST['atualizar_evento']) ? 'editar' : 'novo';
            }
        }
    }
}

/* Dados para edição */
$evento_editar = null;
if ($acao === 'editar' && isset($_GET['id'])) {
    $id_editar = trim($_GET['id']);
    $stmt = $conn->prepare(
        "SELECT *
           FROM agenda
          WHERE tenant_id = ?
            AND escritorio_id = ?
            AND id = ?
          LIMIT 1"
    );
    $stmt->bind_param("sis", $tenantId, $escritorioId, $id_editar);
    $stmt->execute();
    $res = $stmt->get_result();
    $evento_editar = $res && $res->num_rows ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$evento_editar) {
        $msg = '<div class="alert alert-danger">Compromisso não encontrado.</div>';
        $acao = 'listar';
    }
}

$f = camposAgenda($evento_editar ?? []);
$tipos = ['Audiência','Reunião','Prazo','Atendimento','Perícia','Sustentação Oral','Lembrete','Outro'];
$statuses = ['Pendente','Confirmado','Realizado','Cancelado'];

$stmtClientes = $conn->prepare(
    "SELECT id, nome
      FROM clientes
      WHERE tenant_id = ?
        AND escritorio_id = ?
        AND deletado = 0
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

$processos_por_cliente = [];
$stmtProc = $conn->prepare(
    "SELECT DISTINCT cliente_id, numero_processo
      FROM processos
      WHERE tenant_id = ?
        AND escritorio_id = ?
        AND cliente_id <> ''
        AND numero_processo <> ''
        AND status != 'Excluído'
      ORDER BY cliente_id, numero_processo"
);
$stmtProc->bind_param('si', $tenantId, $escritorioId);
$stmtProc->execute();
$resProc = $stmtProc->get_result();

if ($resProc) {
    while ($p = $resProc->fetch_assoc()) {
        $processos_por_cliente[$p['cliente_id']][] = $p['numero_processo'];
    }
}

/* Indicadores */
$hoje = date('Y-m-d');
$em7 = date('Y-m-d', strtotime('+7 days'));
$stmtIndicadores = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(data_evento = ?), 0) AS hoje,
        COALESCE(SUM(data_evento BETWEEN ? AND ?), 0) AS proximos_7,
        COALESCE(SUM(
            prazo_fatal = 'Sim'
            AND status IN ('Pendente','Confirmado')
        ), 0) AS prazos_fatais
     FROM agenda
     WHERE tenant_id = ?
       AND escritorio_id = ?
       AND deletado = 0"
);
$stmtIndicadores->bind_param('ssssi', $hoje, $hoje, $em7, $tenantId, $escritorioId);
$stmtIndicadores->execute();
$indicadoresAgenda = $stmtIndicadores->get_result()->fetch_assoc() ?: [];
$stmtIndicadores->close();

$totalAgenda = (int)($indicadoresAgenda['total'] ?? 0);
$agendaHoje = (int)($indicadoresAgenda['hoje'] ?? 0);
$agenda7 = (int)($indicadoresAgenda['proximos_7'] ?? 0);
$prazosFatais = (int)($indicadoresAgenda['prazos_fatais'] ?? 0);
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-calendar-event"></i> Agenda <?= $acao === 'lixeira' ? '<span class="text-danger">(Lixeira)</span>' : '' ?></h2>
        <div class="text-muted">Controle de audiências, prazos, reuniões e compromissos do escritório.</div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($acao === 'lixeira'): ?>
            <a href="?mod=agenda" class="btn btn-outline-primary"><i class="bi bi-arrow-left"></i> Voltar</a>
        <?php else: ?>
            <a href="?mod=agenda&acao=lixeira" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Lixeira</a>
            <a href="?mod=agenda&acao=novo" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo Compromisso</a>
        <?php endif; ?>
    </div>
</div>

<?= $msg ?>

<?php if ($acao !== 'novo' && $acao !== 'editar'): ?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">TOTAL NA AGENDA</div><h3 class="mb-0"><?= $totalAgenda ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">COMPROMISSOS HOJE</div><h3 class="text-primary mb-0"><?= $agendaHoje ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">PRÓXIMOS 7 DIAS</div><h3 class="text-warning mb-0"><?= $agenda7 ?></h3></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0"><div class="card-body"><div class="text-muted small">PRAZOS FATAIS</div><h3 class="text-danger mb-0"><?= $prazosFatais ?></h3></div></div></div>
</div>
<?php endif; ?>

<script>
const processosPorCliente = <?= json_encode($processos_por_cliente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function preencherNomeCliente(select) {
    const opt = select.options[select.selectedIndex];
    const clienteId = select.value;
    const inputNome = document.getElementById('nome_cliente');
    if (inputNome) inputNome.value = (opt && opt.dataset.nome) ? opt.dataset.nome : '';
    const selProc = document.getElementById('numero_processo');
    if (!selProc) return;
    const atual = selProc.dataset.selected || '';
    selProc.innerHTML = '';
    if (!clienteId || !processosPorCliente[clienteId]) {
        selProc.innerHTML = '<option value="">-- Selecione o cliente primeiro --</option>';
        return;
    }
    selProc.innerHTML = '<option value="">-- Sem vínculo obrigatório --</option>';
    processosPorCliente[clienteId].forEach(function(numero) {
        const o = document.createElement('option');
        o.value = numero; o.textContent = numero;
        if (numero === atual) o.selected = true;
        selProc.appendChild(o);
    });
}
document.addEventListener('DOMContentLoaded', function(){
    const sel = document.querySelector('select[name="cliente_id"]');
    if (sel && sel.value) preencherNomeCliente(sel);
});
</script>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-bold">
        <?= $acao === 'editar' ? '✏️ Editar Compromisso — ' . h($evento_editar['id'] ?? '') : '📅 Novo Compromisso' ?>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrfAgenda) ?>">
            <?php if ($acao === 'editar'): ?><input type="hidden" name="id" value="<?= h($evento_editar['id'] ?? '') ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-2"><label class="form-label">Data *</label><input type="date" name="data_evento" class="form-control" value="<?= h($f['data_evento']) ?>" required></div>
                <div class="col-md-2"><label class="form-label">Horário</label><input type="time" name="horario" class="form-control" value="<?= h(substr($f['horario'],0,5)) ?>"></div>
                <div class="col-md-3"><label class="form-label">Tipo</label><select name="tipo_compromisso" class="form-select"><?php foreach ($tipos as $tp): ?><option value="<?= h($tp) ?>" <?= $f['tipo_compromisso']===$tp?'selected':'' ?>><?= h($tp) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach ($statuses as $st): ?><option value="<?= h($st) ?>" <?= $f['status']===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">Prazo Fatal</label><select name="prazo_fatal" class="form-select"><option value="Não" <?= $f['prazo_fatal']==='Não'?'selected':'' ?>>Não</option><option value="Sim" <?= $f['prazo_fatal']==='Sim'?'selected':'' ?>>Sim</option></select></div>
                <div class="col-md-4"><label class="form-label">Cliente</label><select name="cliente_id" class="form-select" onchange="preencherNomeCliente(this)"><option value="">-- Selecione --</option><?php if ($clientes): while ($c=$clientes->fetch_assoc()): ?><option value="<?= h($c['id']) ?>" data-nome="<?= h($c['nome']) ?>" <?= $f['cliente_id']===$c['id']?'selected':'' ?>><?= h($c['id']) ?> — <?= h($c['nome']) ?></option><?php endwhile; endif; ?></select></div>
                <div class="col-md-4"><label class="form-label">Nome Cliente</label><input type="text" id="nome_cliente" name="nome_cliente" class="form-control" value="<?= h($f['nome_cliente']) ?>" placeholder="Preenchido automaticamente ou livre"></div>
                <div class="col-md-4"><label class="form-label">Processo vinculado</label><select id="numero_processo" name="numero_processo" class="form-select" data-selected="<?= h($f['numero_processo']) ?>"><option value="">-- Selecione o cliente primeiro --</option><?php if ($f['numero_processo']): ?><option value="<?= h($f['numero_processo']) ?>" selected><?= h($f['numero_processo']) ?></option><?php endif; ?></select></div>
                <div class="col-md-6"><label class="form-label">Local</label><input type="text" name="local" class="form-control" value="<?= h($f['local']) ?>" placeholder="Fórum, vara, endereço ou sala"></div>
                <div class="col-md-6"><label class="form-label">Advogado Responsável</label><select name="advogado_id" class="form-select"><option value="">-- Selecione --</option><?php if ($advogados): while ($a=$advogados->fetch_assoc()): ?><option value="<?= h($a['id']) ?>" <?= $f['advogado_id']===$a['id']?'selected':'' ?>><?= h($a['nome']) ?></option><?php endwhile; endif; ?></select></div>
                <div class="col-12"><label class="form-label">Observações</label><textarea name="observacoes" class="form-control" rows="3"><?= h($f['observacoes']) ?></textarea></div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" name="<?= $acao === 'editar' ? 'atualizar_evento' : 'salvar_evento' ?>" class="btn <?= $acao === 'editar' ? 'btn-warning' : 'btn-success' ?>">💾 Salvar</button>
                <a href="?mod=agenda" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<?php
$busca = trim($_GET['busca'] ?? '');
$filtroStatus = trim($_GET['status'] ?? '');
$filtroTipo = trim($_GET['tipo'] ?? '');
$filtroPeriodo = trim($_GET['periodo'] ?? '');
$filtro_deletado = ($acao === 'lixeira') ? 1 : 0;

$where = ['a.tenant_id = ?', 'a.escritorio_id = ?'];
$params = [$tenantId, $escritorioId];
$types = 'si';
if ($agendaTemDeletado) {
    $where[] = 'a.deletado = ?';
    $params[] = $filtro_deletado;
    $types .= 'i';
} elseif ($acao === 'lixeira') {
    $where[] = '1 = 0';
}
if ($busca !== '') {
    $where[] = '(a.nome_cliente LIKE ? OR a.tipo_compromisso LIKE ? OR a.numero_processo LIKE ? OR a.`local` LIKE ? OR adv.nome LIKE ?)';
    $like = "%{$busca}%";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($filtroStatus !== '') { $where[] = 'a.status = ?'; $params[] = $filtroStatus; $types .= 's'; }
if ($filtroTipo !== '') { $where[] = 'a.tipo_compromisso = ?'; $params[] = $filtroTipo; $types .= 's'; }
if ($filtroPeriodo === 'hoje') { $where[] = 'a.data_evento = ?'; $params[] = $hoje; $types .= 's'; }
if ($filtroPeriodo === '7dias') { $where[] = 'a.data_evento BETWEEN ? AND ?'; array_push($params, $hoje, $em7); $types .= 'ss'; }
if ($filtroPeriodo === 'vencidos') { $where[] = "a.data_evento < ? AND a.status IN ('Pendente','Confirmado')"; $params[] = $hoje; $types .= 's'; }
if ($filtroPeriodo === 'fatal') { $where[] = "a.prazo_fatal = 'Sim'"; }

$sqlLista = "SELECT a.id, a.data_evento, a.horario, a.tipo_compromisso, a.cliente_id, a.nome_cliente, a.numero_processo, a.`local`, adv.nome AS advogado_nome, a.status, a.prazo_fatal
             FROM agenda a
             LEFT JOIN advogados adv
                   ON adv.id = a.advogado_id
                   AND adv.tenant_id = a.tenant_id
                   AND adv.escritorio_id = a.escritorio_id
             " . (count($where) ? "WHERE " . implode(' AND ', $where) : "") . "
             ORDER BY a.data_evento ASC, a.horario ASC
             LIMIT 300";
$stmtLista = $conn->prepare($sqlLista);
if ($types !== '') {
    $stmtLista->bind_param($types, ...$params);
}
$stmtLista->execute();
$lista = $stmtLista->get_result();
$totalEncontrado = $lista ? $lista->num_rows : 0;
?>

<form class="card shadow-sm border-0 mb-3" method="GET">
    <div class="card-body">
        <input type="hidden" name="mod" value="agenda">
        <input type="hidden" name="acao" value="<?= h($acao) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label">Pesquisa inteligente</label><input type="text" name="busca" class="form-control" placeholder="Cliente, processo, local, advogado ou tipo" value="<?= h($busca) ?>"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><?php foreach ($statuses as $st): ?><option value="<?= h($st) ?>" <?= $filtroStatus===$st?'selected':'' ?>><?= h($st) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="">Todos</option><?php foreach ($tipos as $tp): ?><option value="<?= h($tp) ?>" <?= $filtroTipo===$tp?'selected':'' ?>><?= h($tp) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Período</label><select name="periodo" class="form-select"><option value="">Todos</option><option value="hoje" <?= $filtroPeriodo==='hoje'?'selected':'' ?>>Hoje</option><option value="7dias" <?= $filtroPeriodo==='7dias'?'selected':'' ?>>Próximos 7 dias</option><option value="vencidos" <?= $filtroPeriodo==='vencidos'?'selected':'' ?>>Vencidos</option><option value="fatal" <?= $filtroPeriodo==='fatal'?'selected':'' ?>>Prazo fatal</option></select></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="submit"><i class="bi bi-search"></i> Buscar</button><a href="?mod=agenda&acao=<?= h($acao) ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a></div>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-list-check"></i> Lista de Compromissos</strong>
        <span><?= (int)$totalEncontrado ?> registro(s) encontrado(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>ID</th><th>Data/Hora</th><th>Tipo</th><th>Cliente</th><th>Processo</th><th>Local</th><th>Advogado</th><th>Status</th><th>Prazo</th><th class="text-end">Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($lista && $lista->num_rows > 0): while ($row = $lista->fetch_assoc()):
                $badge = match($row['status']) { 'Confirmado'=>'primary', 'Realizado'=>'success', 'Cancelado'=>'secondary', default=>'warning' };
                $dataFmt = $row['data_evento'] ? date('d/m/Y', strtotime($row['data_evento'])) : '-';
                $horaFmt = $row['horario'] ? substr($row['horario'],0,5) : '--:--';
                $vencido = $row['data_evento'] < $hoje && in_array($row['status'], ['Pendente','Confirmado'], true);
            ?>
                <tr class="<?= $row['prazo_fatal']==='Sim' ? 'table-danger' : ($vencido ? 'table-warning' : '') ?>">
                    <td><?= h($row['id']) ?></td>
                    <td><strong><?= h($dataFmt) ?></strong><br><span class="text-muted small"><?= h($horaFmt) ?></span></td>
                    <td><?= h($row['tipo_compromisso']) ?></td>
                    <td><?= h($row['nome_cliente'] ?: $row['cliente_id'] ?: '-') ?></td>
                    <td><?= h($row['numero_processo'] ?: '-') ?></td>
                    <td><?= h($row['local'] ?: '-') ?></td>
                    <td><?= h($row['advogado_nome'] ?: '-') ?></td>
                    <td><span class="badge bg-<?= $badge ?>"><?= h($row['status']) ?></span></td>
                    <td><?= $row['prazo_fatal']==='Sim' ? '<span class="badge bg-danger">Fatal</span>' : '<span class="badge bg-light text-dark border">Normal</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($acao === 'lixeira'): ?>
                            <a href="?mod=agenda&restaurar=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrfAgenda) ?>" class="btn btn-sm btn-outline-success" title="Restaurar"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <a href="?mod=agenda&excluir_permanente=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrfAgenda) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir PERMANENTEMENTE este compromisso?')" title="Excluir definitivo"><i class="bi bi-fire"></i></a>
                        <?php else: ?>
                            <a href="?mod=agenda&acao=editar&id=<?= urlencode($row['id']) ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <a href="?mod=agenda&excluir=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrfAgenda) ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Mover este compromisso para a lixeira?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="10" class="text-center text-muted py-4"><?= $acao === 'lixeira' ? 'A lixeira está vazia.' : 'Nenhum compromisso encontrado.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if (isset($stmtLista)) { $stmtLista->close(); } ?>
<?php endif; ?>
<?php
if (isset($stmtClientes) && $stmtClientes instanceof mysqli_stmt) {
    $stmtClientes->close();
}
if (isset($stmtAdvogados) && $stmtAdvogados instanceof mysqli_stmt) {
    $stmtAdvogados->close();
}
if (isset($stmtProc) && $stmtProc instanceof mysqli_stmt) {
    $stmtProc->close();
}
$conn->close();
?>
