<?php
$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
sgl_integracao_garantir_financeiro($conn);
$acao = $_GET['acao'] ?? 'listar';
$msg  = '';

function gerarIdHonorario(mysqli $conn): string
{
    $res = $conn->query("SELECT id FROM honorarios ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'HON001';

    $ultimo = $res->fetch_assoc()['id'];
    $num    = (int) substr($ultimo, 3) + 1;

    return 'HON' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function gerarIdParcela(mysqli $conn): string
{
    $res = $conn->query("SELECT id FROM honorarios_parcelas ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) return 'HPC001';
    $ultimo = $res->fetch_assoc()['id'];
    $num    = (int) substr($ultimo, 3) + 1;
    return 'HPC' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function brlParaFloatHon(string $valor): float
{
    $v = trim($valor);
    if ($v === '') {
        return 0.0;
    }
    $v = str_replace(['R$', ' '], '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float) $v : 0.0;
}

function fmtBrlHon($v): string
{
    if ($v === '' || $v === null || (float)$v == 0) return 'R$ 0,00';
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function statusVisualParcela($valor_parcela, $valor_pago): array
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

function descobrirColunaProcessosCliente(mysqli $conn): string
{
    $colunas_candidatas = [
        'cliente_id',
        'id_cliente',
        'ID Cliente',
        'id_do_cliente',
        'fk_cliente',
        'cliente',
        'codigo_cliente',
        'cod_cliente',
    ];

    foreach ($colunas_candidatas as $candidata) {
        $query = "SHOW COLUMNS FROM processos LIKE '" . $conn->real_escape_string($candidata) . "'";
        $res = $conn->query($query);
        if ($res && $res->num_rows > 0) {
            return "`" . $candidata . "`";
        }
    }
    return '';
}

function gerarParcelasHonorario(mysqli $conn, array $honorario, bool $gerar30dias = true)
{
    $honorario_id    = $honorario['id'];
    $cliente_id      = $honorario['cliente_id'];
    $nome_cliente    = $honorario['nome_cliente'];
    $numero_processo = $honorario['numero_processo'];
    $qtd_parcelas    = max(1, (int)$honorario['qtd_parcelas']);
    $valor_total     = (float)$honorario['valor_total'];
    $data_vencimento = $honorario['data_vencimento'];
    $forma_pagamento = $honorario['forma_pagamento'];
    $observacoes     = $honorario['observacoes'];
    $valor_pago_hon  = (float)$honorario['valor_pago'];

    $valor_parcela_base = round($valor_total / $qtd_parcelas, 2);

    $conn->query("DELETE FROM honorarios_parcelas WHERE honorario_id = '$honorario_id'");

    $data_atual = new DateTime($data_vencimento);

    for ($i = 1; $i <= $qtd_parcelas; $i++) {
        $id_parcela = gerarIdParcela($conn);
        $valor_parcela = $valor_parcela_base;

        if ($i === $qtd_parcelas) {
            $valor_parcela = $valor_total - (($qtd_parcelas - 1) * $valor_parcela_base);
            $valor_parcela = round($valor_parcela, 2);
        }

        $status_parcela = 'Pendente';
        $valor_pago_parcela = 0;
        $saldo_devedor_parcela = $valor_parcela;

        if ($valor_pago_hon > 0) {
            if ($valor_pago_hon >= $valor_parcela) {
                $valor_pago_parcela = $valor_parcela;
                $saldo_devedor_parcela = 0;
                $status_parcela = 'Pago';
                $valor_pago_hon -= $valor_parcela;
            } else {
                $valor_pago_parcela = $valor_pago_hon;
                $saldo_devedor_parcela = $valor_parcela - $valor_pago_parcela;
                $status_parcela = 'Parcial';
                $valor_pago_hon = 0;
            }
        }

        $data_venc_parcela = $data_atual->format('Y-m-d');

        $stmt = $conn->prepare("INSERT INTO honorarios_parcelas
            (id, honorario_id, cliente_id, nome_cliente, numero_processo,
             parcela_numero, valor_parcela, data_vencimento, forma_pagamento,
             status_pagamento, valor_pago, saldo_devedor, observacoes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param("ssssidsssddds",
            $id_parcela,
            $honorario_id,
            $cliente_id,
            $nome_cliente,
            $numero_processo,
            $i,
            $valor_parcela,
            $data_venc_parcela,
            $forma_pagamento,
            $status_parcela,
            $valor_pago_parcela,
            $saldo_devedor_parcela,
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

function getParcelasHonorario(mysqli $conn, string $honorario_id): array
{
    $parcelas = [];
    $res = $conn->query("SELECT * FROM honorarios_parcelas WHERE honorario_id = '$honorario_id' ORDER BY parcela_numero ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $parcelas[] = $row;
        }
    }
    return $parcelas;
}


function buscarHonorarioAuditoria(mysqli $conn, string $id): ?array
{
    $idSeguro = $conn->real_escape_string($id);
    $res = $conn->query("SELECT * FROM honorarios WHERE id = '{$idSeguro}' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        return null;
    }

    $honorario = $res->fetch_assoc();
    $honorario['parcelas'] = getParcelasHonorario($conn, $id);

    return $honorario;
}

function registrarLogHonorario(
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
        'honorarios',
        $registroId,
        $detalhes,
        array_merge(
            [
                'modulo' => 'Honorários',
                'origem' => 'Módulo Honorários',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ],
            $contexto
        )
    );
}

function recalcHonorario(mysqli $conn, string $honorario_id)
{
    $res = $conn->query("
        SELECT
            COALESCE(SUM(valor_parcela), 0) AS total_parcelas,
            COALESCE(SUM(valor_pago), 0) AS total_pago,
            COALESCE(SUM(saldo_devedor), 0) AS total_saldo
        FROM honorarios_parcelas
        WHERE honorario_id = '$honorario_id'
    ");
    $totais = $res->fetch_assoc();

    $total_pago_hon = $totais['total_pago'];
    $total_saldo_hon = $totais['total_saldo'];
    $total_parcelas_hon = $totais['total_parcelas'];

    $status_hon = 'Pendente';
    if ($total_saldo_hon <= 0.01) {
        $status_hon = 'Pago';
    } elseif ($total_pago_hon > 0) {
        $status_hon = 'Parcial';
    }

    $conn->query("
        UPDATE honorarios SET
            valor_pago = '$total_pago_hon',
            valor_pendente = '$total_saldo_hon',
            status = '$status_hon'
        WHERE id = '$honorario_id'
    ");

    if (function_exists('sgl_sincronizar_honorario_financeiro')) {
        sgl_sincronizar_honorario_financeiro($conn, $honorario_id);
    }
}

$tiposHonorario = ['Contrato', 'Êxito', 'Consultoria', 'Acordo', 'Outro'];
$statusOptions = ['Pendente', 'Parcial', 'Pago', 'Cancelado'];
$formasPagamentoHonorario = [
    'Boleto',
    'Pix',
    'Cartão de débito',
    'Cartão de crédito',
    'Dinheiro',
    'Transferência bancária',
    'Depósito bancário',
    'Cheque',
    'Outro',
];

$csrfHonorarios = gerarTokenCsrf();

/* -----------------------------------------------------------
   LIXEIRA SEGURA + LOG ENTERPRISE
----------------------------------------------------------- */
if (isset($_GET['excluir'])) {
    $id = trim((string)$_GET['excluir']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogHonorario(
            $conn,
            'Tentativa inválida de excluir honorário',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'EXCLUSAO',
                'origem' => 'Lista de honorários',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança.</div>';
        $acao = 'listar';
    } else {
        $dadosAnteriores = buscarHonorarioAuditoria($conn, $id);
        $idSeguro = $conn->real_escape_string($id);
        $ok = $conn->query("UPDATE honorarios SET deletado = 1 WHERE id = '{$idSeguro}'");

        if ($ok && $conn->affected_rows > 0) {
            registrarLogHonorario(
                $conn,
                'Honorário movido para a lixeira',
                $id,
                'Exclusão lógica do honorário.',
                [
                    'tipo_acao' => 'EXCLUSAO',
                    'origem' => 'Lista de honorários',
                    'nivel' => 'AVISO',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );
            $msg = '<div class="alert alert-warning">🗑️ Honorário movido para a lixeira com sucesso.</div>';
        } else {
            registrarLogHonorario(
                $conn,
                'Falha ao mover honorário para a lixeira',
                $id,
                'O registro não foi alterado.',
                [
                    'tipo_acao' => 'EXCLUSAO',
                    'origem' => 'Lista de honorários',
                    'resultado' => 'FALHA',
                    'nivel' => 'ERRO',
                    'dados_anteriores' => $dadosAnteriores,
                ]
            );
            $msg = '<div class="alert alert-danger">Não foi possível mover o honorário para a lixeira.</div>';
        }
        $acao = 'listar';
    }
}

if (isset($_GET['restaurar'])) {
    $id = trim((string)$_GET['restaurar']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogHonorario(
            $conn,
            'Tentativa inválida de restaurar honorário',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'RESTAURACAO',
                'origem' => 'Lixeira de honorários',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança.</div>';
        $acao = 'lixeira';
    } else {
        $dadosAnteriores = buscarHonorarioAuditoria($conn, $id);
        $idSeguro = $conn->real_escape_string($id);
        $ok = $conn->query("UPDATE honorarios SET deletado = 0 WHERE id = '{$idSeguro}'");

        if ($ok && $conn->affected_rows > 0) {
            registrarLogHonorario(
                $conn,
                'Honorário restaurado',
                $id,
                'Honorário restaurado da lixeira.',
                [
                    'tipo_acao' => 'RESTAURACAO',
                    'origem' => 'Lixeira de honorários',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );
            $msg = '<div class="alert alert-success">✅ Honorário restaurado com sucesso!</div>';
        } else {
            registrarLogHonorario(
                $conn,
                'Falha ao restaurar honorário',
                $id,
                'O registro não foi restaurado.',
                [
                    'tipo_acao' => 'RESTAURACAO',
                    'origem' => 'Lixeira de honorários',
                    'resultado' => 'FALHA',
                    'nivel' => 'ERRO',
                    'dados_anteriores' => $dadosAnteriores,
                ]
            );
            $msg = '<div class="alert alert-danger">Não foi possível restaurar o honorário.</div>';
        }
        $acao = 'lixeira';
    }
}

if (isset($_GET['excluir_permanente'])) {
    $id = trim((string)$_GET['excluir_permanente']);

    if (!validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        registrarLogHonorario(
            $conn,
            'Tentativa inválida de exclusão permanente de honorário',
            $id,
            'Ação bloqueada por token CSRF inválido.',
            [
                'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                'origem' => 'Lixeira de honorários',
                'resultado' => 'NEGADO',
                'nivel' => 'AVISO',
            ]
        );
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança.</div>';
        $acao = 'lixeira';
    } else {
        $dadosAnteriores = buscarHonorarioAuditoria($conn, $id);
        $idSeguro = $conn->real_escape_string($id);

        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM honorarios_parcelas WHERE honorario_id = '{$idSeguro}'");
            $ok = $conn->query("DELETE FROM honorarios WHERE id = '{$idSeguro}' AND deletado = 1");

            if (!$ok || $conn->affected_rows < 1) {
                throw new RuntimeException('Honorário não encontrado na lixeira.');
            }

            $conn->commit();

            registrarLogHonorario(
                $conn,
                'Honorário excluído permanentemente',
                $id,
                'Honorário e parcelas associadas foram removidos definitivamente.',
                [
                    'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                    'origem' => 'Lixeira de honorários',
                    'nivel' => 'AVISO',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => null,
                ]
            );

            $msg = '<div class="alert alert-danger">💥 Honorário e suas parcelas foram excluídos permanentemente.</div>';
        } catch (Throwable $e) {
            $conn->rollback();

            registrarLogHonorario(
                $conn,
                'Falha na exclusão permanente de honorário',
                $id,
                'Honorário e parcelas não foram removidos.',
                [
                    'tipo_acao' => 'EXCLUSAO_PERMANENTE',
                    'origem' => 'Lixeira de honorários',
                    'resultado' => 'FALHA',
                    'nivel' => 'ERRO',
                    'dados_anteriores' => $dadosAnteriores,
                ]
            );

            $msg = '<div class="alert alert-danger">Não foi possível excluir permanentemente o honorário.</div>';
        }
        $acao = 'lixeira';
    }
}

/* -----------------------------------------------------------
   SALVAR NOVO
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_honorario'])) {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança. Atualize a página.</div>';
        $acao = 'novo';
    } else {
    $id              = gerarIdHonorario($conn);
    $cliente_id      = $conn->real_escape_string(trim($_POST['cliente_id'] ?? ''));
    $nome_cliente    = $conn->real_escape_string(trim($_POST['nome_cliente'] ?? ''));
    $numero_proc     = $conn->real_escape_string(trim($_POST['numero_processo'] ?? ''));
    $tipo_honorario  = $conn->real_escape_string(trim($_POST['tipo_honorario'] ?? 'Contrato'));
    $valor_total     = brlParaFloatHon($_POST['valor_total'] ?? '0');
    $qtd_parcelas    = max(1, (int)($_POST['qtd_parcelas'] ?? 1));
    $data_vencimento = $conn->real_escape_string(trim($_POST['data_vencimento'] ?? ''));
    $forma_pagamento = $conn->real_escape_string(trim($_POST['forma_pagamento'] ?? ''));
    $status          = $conn->real_escape_string(trim($_POST['status'] ?? 'Pendente'));
    $valor_pago      = brlParaFloatHon($_POST['valor_pago'] ?? '0');
    $observacoes     = $conn->real_escape_string(trim($_POST['observacoes'] ?? ''));
    $gerar30dias     = isset($_POST['gerar_30_dias']) ? true : false;

    if ($cliente_id === '') {
        $msg = '<div class="alert alert-danger">❌ Selecione um cliente.</div>';
        $acao = 'novo';
    } elseif ($valor_total <= 0) {
        $msg = '<div class="alert alert-danger">❌ Informe o valor total.</div>';
        $acao = 'novo';
    } elseif ($data_vencimento === '') {
        $msg = '<div class="alert alert-danger">❌ Informe a Data de Vencimento da primeira parcela.</div>';
        $acao = 'novo';
    } else {
        $dadosAnteriores = buscarHonorarioAuditoria($conn, $id);
        $valor_parcela  = round($valor_total / $qtd_parcelas, 2);
        $valor_pendente = max($valor_total - $valor_pago, 0);

        $sql = "INSERT INTO honorarios
                (id, cliente_id, nome_cliente, numero_processo, tipo_honorario,
                 valor_total, qtd_parcelas, valor_parcela, data_vencimento,
                 forma_pagamento, status, valor_pago, valor_pendente, observacoes, deletado)
                VALUES
                ('$id', '$cliente_id', '$nome_cliente', '$numero_proc', '$tipo_honorario',
                 '$valor_total', '$qtd_parcelas', '$valor_parcela', '$data_vencimento',
                 '$forma_pagamento', '$status', '$valor_pago', '$valor_pendente', '$observacoes', 0)";

        if ($conn->query($sql)) {
            gerarParcelasHonorario($conn, [
                'id' => $id,
                'cliente_id' => $cliente_id,
                'nome_cliente' => $nome_cliente,
                'numero_processo' => $numero_proc,
                'qtd_parcelas' => $qtd_parcelas,
                'valor_total' => $valor_total,
                'data_vencimento' => $data_vencimento,
                'forma_pagamento' => $forma_pagamento,
                'observacoes' => $observacoes,
                'valor_pago' => $valor_pago
            ], $gerar30dias);
            sgl_sincronizar_honorario_financeiro($conn, $id);

            registrarLogHonorario(
                $conn,
                'Honorário incluído',
                $id,
                'Novo honorário cadastrado e sincronizado com o Financeiro.',
                [
                    'tipo_acao' => 'INCLUSAO',
                    'origem' => 'Cadastro de honorários',
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );

            registrarLogHonorario(
                $conn,
                'Honorário sincronizado com o Financeiro',
                $id,
                'Parcelas e contas a receber sincronizadas automaticamente.',
                [
                    'tipo_acao' => 'SINCRONIZACAO',
                    'origem' => 'Integração Honorários → Financeiro',
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );

            $msg = "<div class='alert alert-success'>✅ Honorário <strong>$id</strong> cadastrado com sucesso! Financeiro sincronizado automaticamente.</div>";
            $acao = 'listar';
        } else {
            $msg = "<div class='alert alert-danger'>❌ Erro: " . htmlspecialchars($conn->error) . "</div>";
            $acao = 'novo';
        }
    }
    }
}

/* -----------------------------------------------------------
   ATUALIZAR
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_honorario'])) {
    if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Ação bloqueada por segurança. Atualize a página.</div>';
        $acao = 'editar';
    } else {
    $id              = $conn->real_escape_string(trim($_POST['id'] ?? ''));
    $cliente_id      = $conn->real_escape_string(trim($_POST['cliente_id'] ?? ''));
    $nome_cliente    = $conn->real_escape_string(trim($_POST['nome_cliente'] ?? ''));
    $numero_proc     = $conn->real_escape_string(trim($_POST['numero_processo'] ?? ''));
    $tipo_honorario  = $conn->real_escape_string(trim($_POST['tipo_honorario'] ?? 'Contrato'));
    $valor_total     = brlParaFloatHon($_POST['valor_total'] ?? '0');
    $qtd_parcelas    = max(1, (int)($_POST['qtd_parcelas'] ?? 1));
    $data_vencimento = $conn->real_escape_string(trim($_POST['data_vencimento'] ?? ''));
    $forma_pagamento = $conn->real_escape_string(trim($_POST['forma_pagamento'] ?? ''));
    $status          = $conn->real_escape_string(trim($_POST['status'] ?? 'Pendente'));
    $valor_pago      = brlParaFloatHon($_POST['valor_pago'] ?? '0');
    $observacoes     = $conn->real_escape_string(trim($_POST['observacoes'] ?? ''));
    $gerar30dias     = isset($_POST['gerar_30_dias']) ? true : false;

    if ($id === '') {
        $msg = '<div class="alert alert-danger">❌ Honorário inválido.</div>';
        $acao = 'listar';
    } else {
        $valor_parcela  = round($valor_total / $qtd_parcelas, 2);
        $valor_pendente = max($valor_total - $valor_pago, 0);
        $dadosAnteriores = buscarHonorarioAuditoria($conn, $id);

        $sql = "UPDATE honorarios SET
                    cliente_id      = '$cliente_id',
                    nome_cliente    = '$nome_cliente',
                    numero_processo = '$numero_proc',
                    tipo_honorario  = '$tipo_honorario',
                    valor_total     = '$valor_total',
                    qtd_parcelas    = '$qtd_parcelas',
                    valor_parcela   = '$valor_parcela',
                    data_vencimento = '$data_vencimento',
                    forma_pagamento  = '$forma_pagamento',
                    status          = '$status',
                    valor_pago      = '$valor_pago',
                    valor_pendente  = '$valor_pendente',
                    observacoes     = '$observacoes'
                WHERE id = '$id'";

        if ($conn->query($sql)) {
            $resTemParcelas = $conn->query("SELECT COUNT(*) FROM honorarios_parcelas WHERE honorario_id = '$id'")->fetch_row()[0];
            if ($gerar30dias || $resTemParcelas == 0) {
                gerarParcelasHonorario($conn, [
                    'id' => $id,
                    'cliente_id' => $cliente_id,
                    'nome_cliente' => $nome_cliente,
                    'numero_processo' => $numero_proc,
                    'qtd_parcelas' => $qtd_parcelas,
                    'valor_total' => $valor_total,
                    'data_vencimento' => $data_vencimento,
                    'forma_pagamento' => $forma_pagamento,
                    'observacoes' => $observacoes,
                    'valor_pago' => $valor_pago
                ], $gerar30dias);
            }

            recalcHonorario($conn, $id);

            registrarLogHonorario(
                $conn,
                'Honorário atualizado',
                $id,
                'Dados do honorário e parcelas foram atualizados.',
                [
                    'tipo_acao' => 'EDICAO',
                    'origem' => 'Edição de honorários',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );

            registrarLogHonorario(
                $conn,
                'Honorário ressincronizado com o Financeiro',
                $id,
                'Contas a receber recalculadas e sincronizadas.',
                [
                    'tipo_acao' => 'SINCRONIZACAO',
                    'origem' => 'Integração Honorários → Financeiro',
                    'dados_anteriores' => $dadosAnteriores,
                    'dados_novos' => buscarHonorarioAuditoria($conn, $id),
                ]
            );

            $msg = "<div class='alert alert-success'>✅ Honorário <strong>$id</strong> atualizado com sucesso!</div>";
            $acao = 'listar';
        } else {
            $msg = "<div class='alert alert-danger'>❌ Erro: " . htmlspecialchars($conn->error) . "</div>";
            $acao = 'editar';
        }
    }
    }
}

/* -----------------------------------------------------------
   CARREGAR PARA EDIÇÃO
----------------------------------------------------------- */
$hon_editar = null;
if ($acao === 'editar' && isset($_GET['id'])) {
    $id_editar = $conn->real_escape_string($_GET['id']);
    $res = $conn->query("SELECT * FROM honorarios WHERE id = '$id_editar'");
    if ($res && $res->num_rows > 0) {
        $hon_editar = $res->fetch_assoc();
    } else {
        $msg = '<div class="alert alert-danger">Honorário não encontrado.</div>';
        $acao = 'listar';
    }
}

/* -----------------------------------------------------------
   LISTA DINÂMICA (ATIVOS VS LIXEIRA)
----------------------------------------------------------- */
$busca = $conn->real_escape_string(trim($_GET['busca'] ?? ''));
$filtro_status = $conn->real_escape_string(trim($_GET['status'] ?? ''));
$filtro_tipo = $conn->real_escape_string(trim($_GET['tipo'] ?? ''));
$filtro_vencimento = $conn->real_escape_string(trim($_GET['vencimento'] ?? ''));
$filtro_deletado = ($acao === 'lixeira') ? 1 : 0;

$where = "WHERE h.deletado = $filtro_deletado";

if ($filtro_status !== '') {
    $where .= " AND h.status = '$filtro_status'";
}

if ($filtro_tipo !== '') {
    $where .= " AND h.tipo_honorario = '$filtro_tipo'";
}

if ($filtro_vencimento === 'vencidos') {
    $where .= " AND h.data_vencimento < CURDATE() AND h.status NOT IN ('Pago','Quitada','Cancelado')";
} elseif ($filtro_vencimento === 'hoje') {
    $where .= " AND h.data_vencimento = CURDATE()";
} elseif ($filtro_vencimento === '7dias') {
    $where .= " AND h.data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}

if ($busca !== '') {
    $where .= " AND (
        h.id LIKE '%$busca%' OR
        h.cliente_id LIKE '%$busca%' OR
        h.nome_cliente LIKE '%$busca%' OR
        h.numero_processo LIKE '%$busca%' OR
        h.tipo_honorario LIKE '%$busca%' OR
        h.status LIKE '%$busca%'
    )";
}

$lista = $conn->query("
    SELECT
        h.*,
        (SELECT COALESCE(SUM(valor_pago), 0) FROM honorarios_parcelas hp WHERE hp.honorario_id = h.id) AS total_pago_parcelas,
        (SELECT COALESCE(SUM(saldo_devedor), 0) FROM honorarios_parcelas hp WHERE hp.honorario_id = h.id) AS total_saldo_parcelas,
        (SELECT COUNT(*) FROM honorarios_parcelas hp WHERE hp.honorario_id = h.id) AS parcelas_geradas
    FROM honorarios h
    $where
    ORDER BY CAST(SUBSTRING(h.id, 4) AS UNSIGNED) DESC
    LIMIT 200
");

$clientes = $conn->query("SELECT id, nome FROM clientes WHERE deletado = 0 ORDER BY nome");

$coluna_processos = descobrirColunaProcessosCliente($conn);
$processosPorCliente = [];

if ($coluna_processos !== '') {
    $resProc = $conn->query("
        SELECT DISTINCT $coluna_processos AS chave, numero_processo
        FROM processos
        WHERE $coluna_processos IS NOT NULL
          AND $coluna_processos <> ''
          AND numero_processo IS NOT NULL
          AND numero_processo <> ''
        ORDER BY $coluna_processos, numero_processo
    ");

    if ($resProc) {
        while ($p = $resProc->fetch_assoc()) {
            $chave = strtoupper(trim((string)($p['chave'] ?? '')));
            $num   = trim((string)($p['numero_processo'] ?? ''));

            if ($chave === '' || $num === '') {
                continue;
            }

            if (!isset($processosPorCliente[$chave])) {
                $processosPorCliente[$chave] = [];
            }

            if (!in_array($num, $processosPorCliente[$chave], true)) {
                $processosPorCliente[$chave][] = $num;
            }
        }
    }
}


$statsHonorarios = [
    'total' => 0,
    'pendentes' => 0,
    'recebido_mes' => 0,
    'saldo_aberto' => 0,
    'vencidos' => 0,
];

$resStats = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status IN ('Pendente','Parcial') THEN 1 ELSE 0 END),0) AS pendentes,
        COALESCE(SUM(valor_pago),0) AS recebido_mes,
        COALESCE(SUM(valor_pendente),0) AS saldo_aberto,
        COALESCE(SUM(CASE WHEN data_vencimento < CURDATE() AND status NOT IN ('Pago','Quitada','Cancelado') THEN 1 ELSE 0 END),0) AS vencidos
    FROM honorarios
    WHERE deletado = 0
");
if ($resStats) {
    $statsHonorarios = array_merge($statsHonorarios, $resStats->fetch_assoc());
}

$f = [
    'cliente_id'      => $hon_editar['cliente_id'] ?? '',
    'nome_cliente'    => $hon_editar['nome_cliente'] ?? '',
    'numero_processo' => $hon_editar['numero_processo'] ?? '',
    'tipo_honorario'  => $hon_editar['tipo_honorario'] ?? 'Contrato',
    'valor_total'     => $hon_editar['valor_total'] ?? '',
    'qtd_parcelas'    => $hon_editar['qtd_parcelas'] ?? 1,
    'valor_parcela'   => $hon_editar['valor_parcela'] ?? '',
    'data_vencimento' => $hon_editar['data_vencimento'] ?? '',
    'forma_pagamento' => $hon_editar['forma_pagamento'] ?? '',
    'status'          => $hon_editar['status'] ?? 'Pendente',
    'valor_pago'      => $hon_editar['valor_pago'] ?? '',
    'valor_pendente'  => $hon_editar['valor_pendente'] ?? '',
    'observacoes'     => $hon_editar['observacoes'] ?? ''
];

$parcelas_edit = null;
if ($acao === 'editar' && !empty($hon_editar['id'])) {
    $parcelas_edit = getParcelasHonorario($conn, $hon_editar['id']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-stack"></i> Honorários <?= $acao === 'lixeira' ? '<span class="text-danger">(Lixeira)</span>' : '' ?></h2>
    <div class="d-flex gap-2">
        <?php if ($acao === 'lixeira'): ?>
            <a href="?mod=honorarios&acao=listar" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left"></i> Voltar à Listagem
            </a>
        <?php else: ?>
            <a href="?mod=honorarios&acao=lixeira" class="btn btn-outline-danger">
                <i class="bi bi-trash"></i> Ver Lixeira
            </a>
            <?php
            $paramsRelatorioHonorarios = http_build_query([
                'busca' => (string)($_GET['busca'] ?? ''),
                'status' => (string)($_GET['status'] ?? ''),
                'tipo' => (string)($_GET['tipo'] ?? ''),
                'vencimento' => (string)($_GET['vencimento'] ?? ''),
            ]);
            ?>
            <a
                href="modules/relatorios/honorarios.php?<?= htmlspecialchars($paramsRelatorioHonorarios, ENT_QUOTES, 'UTF-8') ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-printer"></i> Relatório / Salvar PDF
            </a>
            <a href="?mod=honorarios&acao=novo" class="btn btn-primary">
                <i class="bi bi-plus"></i> Novo Honorário
            </a>
        <?php endif; ?>
    </div>
</div>

<?= $msg ?>

<style>
@media print {
    .btn, form .btn, .card-header .btn, #gerar_30_dias, .form-check,
    th:last-child, td:last-child, .d-flex.gap-2, nav, .navbar {
        display: none !important;
    }
    body { background: #fff !important; }
    .card { border: none !important; }
    input, select, textarea {
        border: none !important;
        background: transparent !important;
        pointer-events: none;
    }
}
</style>

<script>
const processosPorClienteHon = <?= json_encode($processosPorCliente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const csrfHonorarios = <?= json_encode($csrfHonorarios, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function normalizarHon(valor) {
    return (valor || '').toString().trim().toUpperCase();
}

function aplicarMascaraMoedaHon(input) {
    let digitos = input.value.replace(/\D/g, '');
    if (digitos === '') {
        input.value = '';
        return;
    }
    digitos = digitos.replace(/^0+(?=\d)/, '');
    while (digitos.length < 3) digitos = '0' + digitos;

    const centavos = digitos.slice(-2);
    let inteiro = digitos.slice(0, -2);
    inteiro = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    input.value = 'R$ ' + inteiro + ',' + centavos;
}

function parseMoedaHon(valor) {
    if (!valor) return 0;
    return parseFloat(
        valor.toString()
            .replace(/R\$\s?/g, '')
            .replace(/\./g, '')
            .replace(',', '.')
            .replace(/[^\d.-]/g, '')
    ) || 0;
}

function formatMoedaHon(valor) {
    return 'R$ ' + (valor || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function preencherClienteHon(select) {
    const opt = select.options[select.selectedIndex];
    const clienteId = normalizarHon(select.value);
    const nomeInput = document.getElementById('nome_cliente_hon');
    const processoSelect = document.getElementById('numero_processo_hon');

    if (nomeInput) {
        nomeInput.value = (opt && opt.dataset && opt.dataset.nome) ? opt.dataset.nome : '';
    }

    if (!processoSelect) return;

    const lista = processosPorClienteHon[clienteId] || [];
    const processoAtual = processoSelect.dataset.selected || '';

    processoSelect.innerHTML = '';

    if (!lista.length) {
        const vazio = document.createElement('option');
        vazio.value = '';
        vazio.textContent = '-- Nenhum processo encontrado para este cliente --';
        processoSelect.appendChild(vazio);
        return;
    }

    const padrao = document.createElement('option');
    padrao.value = '';
    padrao.textContent = '-- Selecione --';
    processoSelect.appendChild(padrao);

    lista.forEach(function(numero) {
        const o = document.createElement('option');
        o.value = numero;
        o.textContent = numero;
        if (numero === processoAtual) o.selected = true;
        processoSelect.appendChild(o);
    });
}

function atualizarPreviewParcelasHon() {
    const tabela = document.getElementById('tabela-preview-parcelas');
    if (!tabela) return;

    const totalEl = document.querySelector('[name="valor_total"]');
    const qtdEl   = document.querySelector('[name="qtd_parcelas"]');
    const dataEl  = document.querySelector('[name="data_vencimento"]');
    const tbody   = tabela.querySelector('tbody');

    const total = totalEl ? parseMoedaHon(totalEl.value) : 0;
    const qtd   = qtdEl ? (parseInt(qtdEl.value || '1', 10) || 1) : 1;
    const dataInicial = dataEl && dataEl.value ? dataEl.value : '';

    if (total <= 0 || !dataInicial) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Informe Valor Total, Qtd Parcelas e Data de Vencimento para ver a pré-visualização.</td></tr>';
        return;
    }

    const valorBase = Math.round((total / qtd) * 100) / 100;
    let linhas = '';
    const dataAtual = new Date(dataInicial + 'T00:00:00');

    for (let i = 1; i <= qtd; i++) {
        let valorParcela = valorBase;
        if (i === qtd) {
            valorParcela = Math.round((total - (valorBase * (qtd - 1))) * 100) / 100;
        }
        const dd = String(dataAtual.getDate()).padStart(2, '0');
        const mm = String(dataAtual.getMonth() + 1).padStart(2, '0');
        const yyyy = dataAtual.getFullYear();

        linhas += '<tr>' +
            '<td>' + i + '</td>' +
            '<td>' + dd + '/' + mm + '/' + yyyy + '</td>' +
            '<td>' + formatMoedaHon(valorParcela) + '</td>' +
            '<td><span class="badge bg-danger">🔴 Devedor</span></td>' +
            '</tr>';

        dataAtual.setDate(dataAtual.getDate() + 30);
    }

    tbody.innerHTML = linhas;
}

function atualizarCalculosHon() {
    const totalEl = document.querySelector('[name="valor_total"]');
    const qtdEl   = document.querySelector('[name="qtd_parcelas"]');
    const pagoEl  = document.querySelector('[name="valor_pago"]');
    const parcEl  = document.querySelector('[name="valor_parcela"]');
    const saldoEl = document.querySelector('[name="valor_pendente"]');

    if (!totalEl || !qtdEl || !parcEl || !saldoEl) return;

    const total = parseMoedaHon(totalEl.value);
    const qtd   = parseInt(qtdEl.value || '1', 10) || 1;
    const pago  = pagoEl ? parseMoedaHon(pagoEl.value) : 0;

    const valorParcela = qtd > 0 ? (total / qtd) : 0;
    const saldoDevedor  = Math.max(total - pago, 0);

    parcEl.value  = total > 0 ? formatMoedaHon(valorParcela) : '';
    saldoEl.value = total > 0 ? formatMoedaHon(saldoDevedor) : '';
}

document.addEventListener('DOMContentLoaded', function () {
    const selectCliente = document.querySelector('select[name="cliente_id"]');
    if (selectCliente && selectCliente.value) {
        preencherClienteHon(selectCliente);
    }

    document.querySelectorAll('[name="valor_total"], [name="valor_pago"]').forEach(function (campo) {
        campo.addEventListener('input', function () {
            aplicarMascaraMoedaHon(campo);
            atualizarCalculosHon();
        });
    });

    const qtdEl = document.querySelector('[name="qtd_parcelas"]');
    if (qtdEl) {
        qtdEl.addEventListener('input', atualizarCalculosHon);
        qtdEl.addEventListener('change', atualizarCalculosHon);
    }

    atualizarCalculosHon();
    atualizarPreviewParcelasHon();

    ['valor_total', 'qtd_parcelas', 'data_vencimento'].forEach(function (nome) {
        const el = document.querySelector('[name="' + nome + '"]');
        if (el) {
            el.addEventListener('input', atualizarPreviewParcelasHon);
            el.addEventListener('change', atualizarPreviewParcelasHon);
        }
    });

    document.querySelectorAll('.btn-salvar-parcela').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const linha = btn.closest('tr');
            const parcelaId = linha.dataset.parcelaId;
            const input = linha.querySelector('.input-pago-parcela');
            const valorPago = input.value;

            btn.disabled = true;
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = '...';

            const params = new URLSearchParams();
            params.append('parcela_id', parcelaId);
            params.append('valor_pago', valorPago);
            params.append('csrf_token', csrfHonorarios);

            fetch('modules/ajax_salvar_parcela.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(async response => {
                const data = await response.json();
                if (!response.ok && data.ok !== false) {
                    data.ok = false;
                    data.erro = data.erro || 'Não foi possível salvar a parcela.';
                }
                return data;
            })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = textoOriginal;

                if (!data.ok) {
                    alert('Erro ao salvar: ' + (data.erro || 'desconhecido'));
                    return;
                }

                input.value = data.valor_pago_fmt;
                linha.querySelector('.celula-saldo').textContent = data.saldo_fmt;
                linha.querySelector('.celula-status').innerHTML =
                    '<span class="badge ' + data.status_badge + '">' + data.status_dot + ' ' + data.status_label + '</span>';

                const pagoHonEl = document.querySelector('[name="valor_pago"]');
                const saldoHonEl = document.querySelector('[name="valor_pendente"]');
                const statusHonEl = document.querySelector('[name="status"]');
                if (pagoHonEl) pagoHonEl.value = data.hon_valor_pago_fmt;
                if (saldoHonEl) saldoHonEl.value = data.hon_valor_pendente_fmt;
                if (statusHonEl) statusHonEl.value = data.hon_status;

                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-success');
                setTimeout(() => {
                    btn.classList.remove('btn-outline-success');
                    btn.classList.add('btn-success');
                }, 600);
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = textoOriginal;
                alert('Falha de conexão ao salvar a parcela.');
                console.error(err);
            });
        });
    });
});
</script>

<?php if ($acao === 'novo' || $acao === 'editar'): ?>

<div class="card mb-4">
    <div class="card-header <?= $acao === 'editar' ? 'bg-warning text-dark' : 'bg-primary text-white' ?> d-flex justify-content-between align-items-center">
        <span><?= $acao === 'editar' ? '✏️ Editar Honorário — ' . htmlspecialchars($hon_editar['id'] ?? '') : '💰 Novo Honorário' ?></span>
    </div>
    <div class="card-body">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfHonorarios, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($acao === 'editar'): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($hon_editar['id'] ?? '') ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Cliente *</label>
                    <select name="cliente_id" class="form-select" onchange="preencherClienteHon(this)">
                        <option value="">-- Selecione --</option>
                        <?php if ($clientes && $clientes->num_rows > 0): 
                            mysqli_data_seek($clientes, 0);
                            while ($c = $clientes->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>"
                                        data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>"
                                        <?= ($f['cliente_id'] === $c['id']) ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($c['id']) ?>] <?= htmlspecialchars($c['nome']) ?>
                                </option>
                            <?php endwhile;
                        endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Nome do Cliente (Automático)</label>
                    <input type="text" id="nome_cliente_hon" name="nome_cliente" class="form-control" value="<?= htmlspecialchars($f['nome_cliente']) ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Processo Vinculado</label>
                    <select id="numero_processo_hon" name="numero_processo" class="form-select" data-selected="<?= htmlspecialchars($f['numero_processo']) ?>">
                        <option value="">-- Selecione o Cliente Primeiro --</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tipo de Honorário</label>
                    <select name="tipo_honorario" class="form-select">
                        <?php foreach ($tiposHonorario as $t): ?>
                            <option value="<?= $t ?>" <?= $f['tipo_honorario'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Valor Total *</label>
                    <input type="text" name="valor_total" class="form-control text-end font-monospace fw-bold" value="<?= $f['valor_total'] !== '' ? fmtBrlHon($f['valor_total']) : '' ?>" placeholder="R$ 0,00">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Qtd Parcelas</label>
                    <input type="number" name="qtd_parcelas" class="form-control text-center" min="1" max="120" value="<?= htmlspecialchars($f['qtd_parcelas']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Data Vencimento (1ª Parcela) *</label>
                    <input type="date" name="data_vencimento" class="form-control" value="<?= htmlspecialchars($f['data_vencimento']) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Forma de Pagamento</label>
                    <select name="forma_pagamento" class="form-select">
                        <option value="">-- Selecione --</option>
                        <?php
                        $formaAtual = trim((string)($f['forma_pagamento'] ?? ''));
                        $formaAtualConhecida = in_array($formaAtual, $formasPagamentoHonorario, true);
                        ?>
                        <?php if ($formaAtual !== '' && !$formaAtualConhecida): ?>
                            <option value="<?= htmlspecialchars($formaAtual, ENT_QUOTES, 'UTF-8') ?>" selected>
                                <?= htmlspecialchars($formaAtual, ENT_QUOTES, 'UTF-8') ?> (valor anterior)
                            </option>
                        <?php endif; ?>
                        <?php foreach ($formasPagamentoHonorario as $formaPagamento): ?>
                            <option
                                value="<?= htmlspecialchars($formaPagamento, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $formaAtual === $formaPagamento ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($formaPagamento, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Valor Cada Parcela</label>
                    <input type="text" name="valor_parcela" class="form-control text-end bg-light font-monospace" value="<?= $f['valor_parcela'] !== '' ? fmtBrlHon($f['valor_parcela']) : '' ?>" readonly>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Valor Pago Inicial</label>
                    <input type="text" name="valor_pago" class="form-control text-end font-monospace text-success fw-bold" value="<?= $f['valor_pago'] !== '' ? fmtBrlHon($f['valor_pago']) : '' ?>" placeholder="R$ 0,00" <?= $acao === 'editar' ? 'readonly bg-light' : '' ?>>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Saldo Pendente</label>
                    <input type="text" name="valor_pendente" class="form-control text-end bg-light font-monospace text-danger fw-bold" value="<?= $f['valor_pendente'] !== '' ? fmtBrlHon($f['valor_pendente']) : '' ?>" readonly>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status Global</label>
                    <select name="status" class="form-select fw-bold <?= $f['status'] === 'Pago' ? 'text-success' : ($f['status'] === 'Parcial' ? 'text-warning' : 'text-danger') ?>" <?= $acao === 'editar' ? 'disabled bg-light' : '' ?>>
                        <?php foreach ($statusOptions as $s): ?>
                            <option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-9 d-flex align-items-center mt-4">
                    <div class="form-check form-switch bg-light p-2 rounded border w-100 ps-5">
                        <input class="form-check-input" type="checkbox" name="gerar_30_dias" id="gerar_30_dias" value="1" checked>
                        <label class="form-check-label fw-bold" for="gerar_30_dias">
                            🔄 Recriar/Gerar todas as parcelas automaticamente (Intervalo de 30 dias)
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">Observações Internas</label>
                    <textarea name="observacoes" class="form-control" rows="3"><?= htmlspecialchars($f['observacoes']) ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex justify-content-end gap-2">
                <a href="?mod=honorarios&acao=listar" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" name="<?= $acao === 'editar' ? 'atualizar_honorario' : 'salvar_honorario' ?>" class="btn <?= $acao === 'editar' ? 'btn-warning' : 'btn-primary' ?>">
                    <i class="bi bi-save"></i> Salvar Registro
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($acao === 'editar' && !empty($parcelas_edit)): ?>
<div class="card mb-4 border-dark">
    <div class="card-header bg-dark text-white fw-bold">
        📋 Parcelas Geradas do Contrato (Gerenciamento Individual)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-secondary">
                    <tr>
                        <th width="60" class="text-center">Parc.</th>
                        <th width="120">Vencimento</th>
                        <th width="140" class="text-end">Valor Parcela</th>
                        <th width="160" class="text-center">Status Real</th>
                        <th width="160">Valor Pago</th>
                        <th width="140" class="text-end">Saldo Restante</th>
                        <th width="80" class="text-center">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parcelas_edit as $p): 
                        $visual = statusVisualParcela($p['valor_parcela'], $p['valor_pago']);
                    ?>
                        <tr data-parcela-id="<?= htmlspecialchars($p['id']) ?>">
                            <td class="text-center fw-bold">#<?= $p['parcela_numero'] ?></td>
                            <td><?= date('d/m/Y', strtotime($p['data_vencimento'])) ?></td>
                            <td class="text-end font-monospace fw-bold"><?= fmtBrlHon($p['valor_parcela']) ?></td>
                            <td class="text-center celula-status">
                                <span class="badge <?= $visual['badge'] ?>"><?= $visual['dot'] ?> <?= $visual['label'] ?></span>
                            </td>
                            <td>
                                <input type="text" class="form-control form-control-sm text-end font-monospace input-pago-parcela" value="<?= fmtBrlHon($p['valor_pago']) ?>" oninput="aplicarMascaraMoedaHon(this)">
                            </td>
                            <td class="text-end font-monospace text-danger fw-bold celula-saldo">
                                <?= fmtBrlHon($p['saldo_devedor']) ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-success btn-salvar-parcela" title="Salvar Parcela">
                                    <i class="bi bi-floppy"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif ($acao === 'novo'): ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-info text-dark fw-bold">
        👀 Pré-visualização das Parcelas (Projeção Estimada)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0" id="tabela-preview-parcelas">
                <thead>
                    <tr class="table-light">
                        <th width="80">Parcela</th>
                        <th>Vencimento Estimado</th>
                        <th>Valor Previsto</th>
                        <th>Status Inicial</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4" class="text-center text-muted">Informe Valor Total, Qtd Parcelas e Data de Vencimento para ver a pré-visualização.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Total de honorários</div>
                <div class="fs-3 fw-bold"><?= (int)$statsHonorarios['total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Pendentes / parciais</div>
                <div class="fs-3 fw-bold text-warning"><?= (int)$statsHonorarios['pendentes'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Saldo em aberto</div>
                <div class="fs-3 fw-bold text-danger"><?= fmtBrlHon($statsHonorarios['saldo_aberto']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Vencidos</div>
                <div class="fs-3 fw-bold text-danger"><?= (int)$statsHonorarios['vencidos'] ?></div>
            </div>
        </div>
    </div>
</div>

<form class="card shadow-sm border-0 mb-3" method="GET">
    <div class="card-body">
        <input type="hidden" name="mod" value="honorarios">
        <input type="hidden" name="acao" value="<?= htmlspecialchars($acao) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Pesquisa inteligente</label>
                <input type="text" name="busca" class="form-control" placeholder="ID, cliente, processo, tipo ou status" value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($statusOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $filtro_status === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($tiposHonorario as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $filtro_tipo === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Vencimento</label>
                <select name="vencimento" class="form-select">
                    <option value="">Todos</option>
                    <option value="vencidos" <?= $filtro_vencimento === 'vencidos' ? 'selected' : '' ?>>Vencidos</option>
                    <option value="hoje" <?= $filtro_vencimento === 'hoje' ? 'selected' : '' ?>>Hoje</option>
                    <option value="7dias" <?= $filtro_vencimento === '7dias' ? 'selected' : '' ?>>Próximos 7 dias</option>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
            <div class="col-md-1 d-grid">
                <a href="?mod=honorarios&acao=<?= htmlspecialchars($acao) ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Cód.</th>
                        <th>Cliente</th>
                        <th>Processo</th>
                        <th>Tipo</th>
                        <th class="text-end">Vlr Total</th>
                        <th class="text-center">Parc.</th>
                        <th class="text-end">Pago</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" width="130">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lista && $lista->num_rows > 0): ?>
                        <?php while ($h = $lista->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($h['id']) ?></td>
                                <td><?= htmlspecialchars($h['nome_cliente']) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($h['numero_processo'] ?: 'Não Vinculado') ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($h['tipo_honorario']) ?></span></td>
                                <td class="text-end font-monospace fw-bold"><?= fmtBrlHon($h['valor_total']) ?></td>
                                <td class="text-center font-monospace small"><?= $h['parcelas_geradas'] ?> / <?= $h['qtd_parcelas'] ?></td>
                                <td class="text-end font-monospace text-success"><?= fmtBrlHon($h['total_pago_parcelas']) ?></td>
                                <td class="text-end font-monospace text-danger fw-bold"><?= fmtBrlHon($h['total_saldo_parcelas']) ?></td>
                                <td class="text-center">
                                    <?php if ($h['status'] === 'Pago'): ?>
                                        <span class="badge bg-success">🟢 Pago</span>
                                    <?php elseif ($h['status'] === 'Parcial'): ?>
                                        <span class="badge bg-warning text-dark">🟡 Parcial</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">🔴 Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($acao === 'lixeira'): ?>
                                        <a href="?mod=honorarios&restaurar=<?= $h['id'] ?>&csrf_token=<?= urlencode($csrfHonorarios) ?>" class="btn btn-sm btn-outline-success" title="Restaurar Honorário">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </a>
                                        <a href="?mod=honorarios&excluir_permanente=<?= $h['id'] ?>&csrf_token=<?= urlencode($csrfHonorarios) ?>" class="btn btn-sm btn-danger" onclick="return confirm('ATENÇÃO: Deseja mesmo excluir PERMANENTEMENTE este honorário e todas as parcelas associadas? Esta ação não pode ser desfeita.')" title="Excluir do Banco">
                                            <i class="bi bi-fire"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="?mod=honorarios&acao=editar&id=<?= $h['id'] ?>" class="btn btn-sm btn-warning" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="?mod=honorarios&excluir=<?= $h['id'] ?>&csrf_token=<?= urlencode($csrfHonorarios) ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deseja mover este honorário para a lixeira?')" title="Mover para Lixeira">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted p-4">Nenhum registro encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>