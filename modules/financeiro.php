<?php
// C:\xampp\htdocs\sistema_sgl\modules\financeiro.php

$conn = conectar();
require_once __DIR__ . '/../config/integracoes.php';
sgl_integracao_garantir_financeiro($conn);
sgl_integracao_garantir_recibos($conn);
if (function_exists('sgl_garantir_logs')) { sgl_garantir_logs($conn); }
$csrf_token_fin = function_exists('gerarTokenCsrf') ? gerarTokenCsrf() : '';

$aba  = $_GET['aba'] ?? 'cp';     // cp = contas a pagar, cr = receber
$acao = $_GET['acao'] ?? 'listar'; // listar | novo_cp | editar_cp | novo_cr | editar_cr | lixeira
$msg  = '';

/* ======================================================
   COMPATIBILIDADE DO BANCO — FINANCEIRO
   Evita erros em instalações antigas que ainda não tenham
   todas as colunas usadas pelo módulo.
   ====================================================== */
function financeiroColunaExiste(mysqli $conn, string $tabela, string $coluna): bool
{
    $tabela = $conn->real_escape_string($tabela);
    $coluna = $conn->real_escape_string($coluna);
    $res = $conn->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
    return $res && $res->num_rows > 0;
}

function financeiroAdicionarColuna(mysqli $conn, string $tabela, string $coluna, string $definicao): void
{
    if (!financeiroColunaExiste($conn, $tabela, $coluna)) {
        @$conn->query("ALTER TABLE `{$tabela}` ADD COLUMN {$definicao}");
    }
}

function financeiroGarantirEstrutura(mysqli $conn): void
{
    financeiroAdicionarColuna($conn, 'contas_pagar', 'qtd_parcelas', "qtd_parcelas INT DEFAULT 1");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'valor_parcela', "valor_parcela DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'valor_pago', "valor_pago DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'valor_pendente', "valor_pendente DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'data_pagamento', "data_pagamento DATE NULL");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'forma_pagamento', "forma_pagamento VARCHAR(80) NULL");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'mes_referencia', "mes_referencia VARCHAR(7) NULL");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'observacoes', "observacoes TEXT NULL");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'deletado', "deletado TINYINT(1) NOT NULL DEFAULT 0");

    financeiroAdicionarColuna($conn, 'contas_receber', 'cliente_id', "cliente_id VARCHAR(10) NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'qtd_parcelas', "qtd_parcelas INT DEFAULT 1");
    financeiroAdicionarColuna($conn, 'contas_receber', 'valor_parcela', "valor_parcela DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_receber', 'valor_pago', "valor_pago DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_receber', 'valor_pendente', "valor_pendente DECIMAL(12,2) DEFAULT 0");
    financeiroAdicionarColuna($conn, 'contas_receber', 'data_recebimento', "data_recebimento DATE NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'forma_recebimento', "forma_recebimento VARCHAR(80) NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'mes_referencia', "mes_referencia VARCHAR(7) NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'observacoes', "observacoes TEXT NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'deletado', "deletado TINYINT(1) NOT NULL DEFAULT 0");

    $conn->query("CREATE TABLE IF NOT EXISTS contas_pagar_parcelas (
        id VARCHAR(20) PRIMARY KEY,
        conta_id VARCHAR(10) NOT NULL,
        parcela_numero INT NOT NULL,
        valor_parcela DECIMAL(12,2) NOT NULL DEFAULT 0,
        data_vencimento DATE NULL,
        forma_pagamento VARCHAR(80) NULL,
        status_pagamento VARCHAR(30) DEFAULT 'Pendente',
        valor_pago DECIMAL(12,2) DEFAULT 0,
        saldo_devedor DECIMAL(12,2) DEFAULT 0,
        observacoes TEXT NULL,
        INDEX idx_cpp_conta (conta_id),
        INDEX idx_cpp_status (status_pagamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS contas_receber_parcelas (
        id VARCHAR(20) PRIMARY KEY,
        conta_id VARCHAR(10) NOT NULL,
        parcela_numero INT NOT NULL,
        valor_parcela DECIMAL(12,2) NOT NULL DEFAULT 0,
        data_vencimento DATE NULL,
        forma_pagamento VARCHAR(80) NULL,
        status_pagamento VARCHAR(30) DEFAULT 'Pendente',
        valor_pago DECIMAL(12,2) DEFAULT 0,
        saldo_devedor DECIMAL(12,2) DEFAULT 0,
        observacoes TEXT NULL,
        INDEX idx_crp_conta (conta_id),
        INDEX idx_crp_status (status_pagamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS bancos_caixa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(120) NOT NULL,
        tipo VARCHAR(40) DEFAULT 'Conta Corrente',
        banco VARCHAR(120) NULL,
        agencia VARCHAR(40) NULL,
        conta VARCHAR(60) NULL,
        saldo_inicial DECIMAL(12,2) DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bancos_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS bancos_movimentacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data_movimento DATE NOT NULL,
        tipo VARCHAR(30) NOT NULL DEFAULT 'Transferência',
        banco_origem_id INT NULL,
        banco_destino_id INT NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        descricao VARCHAR(255) NULL,
        origem_outros VARCHAR(150) NULL,
        destino_outros VARCHAR(150) NULL,
        usuario_nome VARCHAR(150) NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bm_data (data_movimento),
        INDEX idx_bm_origem (banco_origem_id),
        INDEX idx_bm_destino (banco_destino_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    financeiroAdicionarColuna($conn, 'bancos_movimentacoes', 'origem_outros', "origem_outros VARCHAR(150) NULL");
    financeiroAdicionarColuna($conn, 'bancos_movimentacoes', 'destino_outros', "destino_outros VARCHAR(150) NULL");
    financeiroAdicionarColuna($conn, 'contas_pagar', 'banco_id', "banco_id INT NULL");
    financeiroAdicionarColuna($conn, 'contas_receber', 'banco_id', "banco_id INT NULL");
    financeiroAdicionarColuna($conn, 'bancos_caixa', 'titularidade', "titularidade VARCHAR(40) NOT NULL DEFAULT 'Pessoa Jurídica'");
    financeiroAdicionarColuna($conn, 'bancos_caixa', 'finalidade', "finalidade VARCHAR(120) NULL");
    financeiroAdicionarColuna($conn, 'bancos_caixa', 'observacoes', "observacoes TEXT NULL");
}

financeiroGarantirEstrutura($conn);


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
    $v = trim((string)$valor);
    if ($v === '') return 0.0;

    // Aceita: 1000,00 | 1.000,00 | R$ 1.000,00 | 1000.00 | 1,000.00
    $v = str_replace(["Â ", 'R$', 'r$', ' '], '', $v);
    $v = preg_replace('/[^0-9,\.\-]/', '', $v);
    if ($v === '' || $v === '-' || $v === ',' || $v === '.') return 0.0;

    $lastComma = strrpos($v, ',');
    $lastDot = strrpos($v, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            // padrão BR: 1.000,00
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            // padrão internacional: 1,000.00
            $v = str_replace(',', '', $v);
        }
    } elseif ($lastComma !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif ($lastDot !== false) {
        // Se só há ponto e ele parece separador de milhar (1.000), remove; se parece decimal (1000.00), mantém.
        $decimals = strlen($v) - $lastDot - 1;
        if ($decimals === 3 && substr_count($v, '.') >= 1) {
            $v = str_replace('.', '', $v);
        }
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

function financeiroListaBancos(mysqli $conn, bool $somenteAtivos = true): array
{
    $where = $somenteAtivos ? 'WHERE ativo = 1' : '';
    $lista = [];
    $res = $conn->query("SELECT * FROM bancos_caixa {$where} ORDER BY ativo DESC, nome ASC");
    if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
    return $lista;
}

function financeiroNomeBanco(mysqli $conn, $id): string
{
    $id = (int)$id;
    if ($id <= 0) return '-';
    $res = $conn->query("SELECT nome FROM bancos_caixa WHERE id = {$id} LIMIT 1");
    if ($res && $res->num_rows) return (string)$res->fetch_assoc()['nome'];
    return '-';
}

function financeiroSelectBanco(mysqli $conn, $selecionado = null): string
{
    $selecionado = (int)($selecionado ?? 0);
    $html = '<select name="banco_id" class="form-select"><option value="">Selecione...</option>';
    foreach (financeiroListaBancos($conn, true) as $b) {
        $id = (int)$b['id'];
        $sel = $id === $selecionado ? ' selected' : '';
        $nome = htmlspecialchars($b['nome'] . ' - ' . ($b['tipo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html .= "<option value=\"{$id}\"{$sel}>{$nome}</option>";
    }
    $html .= '</select><div class="form-text">Use para identificar em qual caixa/banco entrou ou saiu o dinheiro.</div>';
    return $html;
}


function financeiroSaldoBanco(mysqli $conn, int $bancoId): float
{
    if ($bancoId <= 0) return 0.0;
    $saldo = 0.0;
    $res = $conn->query("SELECT COALESCE(saldo_inicial,0) AS total FROM bancos_caixa WHERE id={$bancoId}");
    if ($res && $row = $res->fetch_assoc()) $saldo += (float)$row['total'];
    $res = $conn->query("SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_receber WHERE deletado=0 AND banco_id={$bancoId} AND status IN ('Recebido','Pago','Quitada')");
    if ($res && $row = $res->fetch_assoc()) $saldo += (float)$row['total'];
    $res = $conn->query("SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_pagar WHERE deletado=0 AND banco_id={$bancoId} AND status IN ('Pago','Quitada')");
    if ($res && $row = $res->fetch_assoc()) $saldo -= (float)$row['total'];
    $res = $conn->query("SELECT COALESCE(SUM(valor),0) AS total FROM bancos_movimentacoes WHERE banco_destino_id={$bancoId}");
    if ($res && $row = $res->fetch_assoc()) $saldo += (float)$row['total'];
    $res = $conn->query("SELECT COALESCE(SUM(valor),0) AS total FROM bancos_movimentacoes WHERE banco_origem_id={$bancoId}");
    if ($res && $row = $res->fetch_assoc()) $saldo -= (float)$row['total'];
    return $saldo;
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
   INTEGRAÇÃO FINANCEIRA — RECEBER CONTA E GERAR RECIBO
   ====================================================== */
if (isset($_GET['receber_cr'])) {
    if (function_exists('validarTokenCsrf') && !validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido. Atualize a página e tente novamente.</div>';
    } else {
        $id = $conn->real_escape_string((string)$_GET['receber_cr']);
        $res = $conn->query("SELECT valor, forma_recebimento FROM contas_receber WHERE id = '$id' LIMIT 1");
        if ($res && $res->num_rows) {
            $cr = $res->fetch_assoc();
            $valor = (float)($cr['valor'] ?? 0);
            $hojeSql = date('Y-m-d');
            $conn->query("UPDATE contas_receber SET valor_pago = {$valor}, valor_pendente = 0, status = 'Recebido', data_recebimento = '{$hojeSql}' WHERE id = '$id'");
            $reciboId = sgl_gerar_recibo_de_conta_receber($conn, $id);
            if ($reciboId) {
                $msg = '<div class="alert alert-success">✅ Recebimento confirmado e recibo gerado automaticamente.</div>';
                if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Confirmou recebimento', 'contas_receber', $id, 'Recibo automático: ' . $reciboId); }
            } else {
                $msg = '<div class="alert alert-warning">Recebimento confirmado, mas o recibo automático não pôde ser gerado.</div>';
            }
        } else {
            $msg = '<div class="alert alert-danger">Conta a receber não encontrada.</div>';
        }
    }
    $aba = 'cr';
    $acao = 'listar';
}

if (isset($_GET['gerar_recibo_cr'])) {
    if (function_exists('validarTokenCsrf') && !validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido. Atualize a página e tente novamente.</div>';
    } else {
        $id = $conn->real_escape_string((string)$_GET['gerar_recibo_cr']);
        $res = $conn->query("SELECT valor, valor_pago, status, data_recebimento FROM contas_receber WHERE id = '$id' LIMIT 1");
        if ($res && $res->num_rows) {
            $cr = $res->fetch_assoc();
            $valor = (float)($cr['valor_pago'] ?? 0);
            if ($valor <= 0) $valor = (float)($cr['valor'] ?? 0);
            $dataReceb = $cr['data_recebimento'] ?: date('Y-m-d');
            $conn->query("UPDATE contas_receber SET valor_pago = " . sqlMoney($valor) . ", valor_pendente = 0, status = 'Recebido', data_recebimento = '" . $conn->real_escape_string($dataReceb) . "' WHERE id = '$id'");
            $reciboId = sgl_gerar_recibo_de_conta_receber($conn, $id);
            $msg = $reciboId
                ? '<div class="alert alert-success">✅ Recibo gerado com sucesso para a conta a receber.</div>'
                : '<div class="alert alert-warning">A conta foi marcada como recebida, mas o recibo não pôde ser gerado.</div>';
        } else {
            $msg = '<div class="alert alert-danger">Conta a receber não encontrada.</div>';
        }
    }
    $aba = 'cr';
    $acao = 'listar';
}

if (isset($_GET['pagar_cp'])) {
    if (function_exists('validarTokenCsrf') && !validarTokenCsrf($_GET['csrf_token'] ?? null)) {
        $msg = '<div class="alert alert-danger">Token de segurança inválido. Atualize a página e tente novamente.</div>';
    } else {
        $id = (string)$_GET['pagar_cp'];
        marcarContaPagarPaga($conn, $id, date('Y-m-d'));
        $msg = '<div class="alert alert-success">✅ Conta a pagar marcada como paga.</div>';
    }
    $aba = 'cp';
    $acao = 'listar';
}


/* ======================================================
   CADASTRO DE BANCOS / CAIXAS
   ====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_banco'])) {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $tipo = trim($_POST['tipo'] ?? 'Conta Corrente');
    $banco = trim($_POST['banco'] ?? '');
    $agencia = trim($_POST['agencia'] ?? '');
    $conta = trim($_POST['conta'] ?? '');
    $saldo_inicial = brlParaFloatFin($_POST['saldo_inicial'] ?? '0');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '') {
        $msg = '<div class="alert alert-danger">Informe o nome da conta/banco/caixa.</div>';
    } elseif ($id > 0) {
        $stmt = $conn->prepare("UPDATE bancos_caixa SET nome=?, tipo=?, banco=?, agencia=?, conta=?, saldo_inicial=?, ativo=? WHERE id=?");
        $stmt->bind_param('sssssdis', $nome, $tipo, $banco, $agencia, $conta, $saldo_inicial, $ativo, $id);
        $ok = $stmt->execute();
        $stmt->close();
        $msg = $ok ? '<div class="alert alert-success">Banco/Caixa atualizado.</div>' : '<div class="alert alert-danger">Erro ao atualizar banco/caixa.</div>';
        if ($ok && function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'Atualizou banco/caixa', 'bancos_caixa', (string)$id, $nome);
    } else {
        $stmt = $conn->prepare("INSERT INTO bancos_caixa (nome, tipo, banco, agencia, conta, saldo_inicial, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssdi', $nome, $tipo, $banco, $agencia, $conta, $saldo_inicial, $ativo);
        $ok = $stmt->execute();
        $novoBancoId = $stmt->insert_id;
        $stmt->close();
        $msg = $ok ? '<div class="alert alert-success">Banco/Caixa cadastrado.</div>' : '<div class="alert alert-danger">Erro ao cadastrar banco/caixa.</div>';
        if ($ok && function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'Cadastrou banco/caixa', 'bancos_caixa', (string)$novoBancoId, $nome);
    }
    $acao = 'bancos';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transferir_banco'])) {
    $origemRaw = trim((string)($_POST['banco_origem_id'] ?? ''));
    $destinoRaw = trim((string)($_POST['banco_destino_id'] ?? ''));
    $origem = ctype_digit($origemRaw) ? (int)$origemRaw : 0;
    $destino = ctype_digit($destinoRaw) ? (int)$destinoRaw : 0;
    $origemOutros = strtoupper($origemRaw) === 'OUTROS' ? trim((string)($_POST['origem_outros'] ?? 'OUTROS')) : null;
    $destinoOutros = strtoupper($destinoRaw) === 'OUTROS' ? trim((string)($_POST['destino_outros'] ?? 'OUTROS')) : null;
    $valor = brlParaFloatFin((string)($_POST['valor_transferencia'] ?? '0'));
    $dataMov = $_POST['data_movimento'] ?: date('Y-m-d');
    $descricao = trim($_POST['descricao_transferencia'] ?? 'Transferência entre contas');
    $usuarioNome = $_SESSION['usuario_nome'] ?? $_SESSION['usuario'] ?? $_SESSION['nome'] ?? 'Sistema';

    $origemValida = $origem > 0 || $origemOutros !== null;
    $destinoValido = $destino > 0 || $destinoOutros !== null;
    $mesmaConta = ($origem > 0 && $destino > 0 && $origem === $destino) || ($origemRaw !== '' && $origemRaw === $destinoRaw);

    if (!$origemValida || !$destinoValido || $mesmaConta || $valor <= 0) {
        $msg = '<div class="alert alert-danger">Não foi possível registrar a transferência: selecione origem/destino diferentes e informe valor maior que zero. Aceita: 1000,00, 1.000,00 ou R$ 1.000,00.</div>';
    } else {
        if ($origemOutros !== null && $origemOutros === '') $origemOutros = 'OUTROS';
        if ($destinoOutros !== null && $destinoOutros === '') $destinoOutros = 'OUTROS';
        $stmt = $conn->prepare("INSERT INTO bancos_movimentacoes (data_movimento, tipo, banco_origem_id, banco_destino_id, valor, descricao, origem_outros, destino_outros, usuario_nome) VALUES (?, 'Transferência', ?, ?, ?, ?, ?, ?, ?)");
        $origemDb = $origem > 0 ? $origem : null;
        $destinoDb = $destino > 0 ? $destino : null;
        $stmt->bind_param('siidssss', $dataMov, $origemDb, $destinoDb, $valor, $descricao, $origemOutros, $destinoOutros, $usuarioNome);
        $ok = $stmt->execute();
        $movId = $stmt->insert_id;
        $stmt->close();
        $msg = $ok ? '<div class="alert alert-success">Transferência registrada entre Caixa/Bancos/Outros.</div>' : '<div class="alert alert-danger">Erro ao registrar transferência.</div>';
        if ($ok && function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, 'Transferiu valor entre bancos/caixa/outros', 'bancos_movimentacoes', (string)$movId, $descricao . ' - ' . fmtBrlFin($valor)); }
    }
    $acao = 'movimentacao_bancos';
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
    $banco_id        = (int)($_POST['banco_id'] ?? 0);
    $banco_sql       = $banco_id > 0 ? (string)$banco_id : 'NULL';
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
             forma_pagamento, status, mes_referencia, observacoes, valor_pago, valor_pendente, banco_id, deletado)
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
                {$banco_sql},
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
                observacoes     = " . sqlNullableText($conn, $observacoes) . ",
                banco_id        = {$banco_sql}
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

        // recalcula conta com base nas parcelas e respeita o status informado.
        if ($status_input === 'Pago') {
            marcarContaPagarPaga($conn, $id, $data_pagamento ?: date('Y-m-d'));
        } else {
            recalcContaPagar($conn, $id);
            if ($status_input === 'Cancelado') {
                $conn->query("UPDATE contas_pagar SET status = 'Cancelado' WHERE id = " . sqlText($conn, $id));
            }
        }

        if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, $novo ? 'Cadastrou conta a pagar' : 'Atualizou conta a pagar', 'contas_pagar', $id, $descricao); }
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
    $forma_recebimento = trim($_POST['forma_recebimento'] ?? '');
    $observacoes       = trim($_POST['observacoes'] ?? '');
    $banco_id          = (int)($_POST['banco_id'] ?? 0);
    $banco_sql         = $banco_id > 0 ? (string)$banco_id : 'NULL';

    $valor_pago = 0.00;
    $valor_pendente = $valor;

    if (in_array($status, ['Recebido','Pago','Quitada'], true)) {
        $status = 'Recebido';
        if ($data_recebimento === '') $data_recebimento = date('Y-m-d');
        $valor_pago = $valor;
        $valor_pendente = 0.00;
    } elseif ($status === 'Parcial') {
        // Nesta tela simples ainda não há campo de pagamento parcial.
        // Mantemos o saldo total pendente até implantarmos parcelas de CR.
        $valor_pago = 0.00;
        $valor_pendente = $valor;
    } elseif ($status === 'Cancelado') {
        $valor_pago = 0.00;
        $valor_pendente = 0.00;
    }

    if ($novo) {
        $sql = "INSERT INTO contas_receber
            (id, descricao, valor, valor_parcela, valor_pago, valor_pendente, data_vencimento, data_recebimento, forma_recebimento, status, observacoes, banco_id, deletado)
            VALUES (
                " . sqlText($conn, $id) . ",
                " . sqlNullableText($conn, $descricao) . ",
                " . sqlMoney($valor) . ",
                " . sqlMoney($valor) . ",
                " . sqlMoney($valor_pago) . ",
                " . sqlMoney($valor_pendente) . ",
                " . sqlNullableDate($conn, $data_vencimento) . ",
                " . sqlNullableDate($conn, $data_recebimento) . ",
                " . sqlNullableText($conn, $forma_recebimento) . ",
                " . sqlText($conn, $status) . ",
                " . sqlNullableText($conn, $observacoes) . ",
                {$banco_sql},
                0
            )";
    } else {
        $sql = "UPDATE contas_receber SET
                descricao          = " . sqlNullableText($conn, $descricao) . ",
                valor              = " . sqlMoney($valor) . ",
                valor_parcela      = " . sqlMoney($valor) . ",
                valor_pago         = " . sqlMoney($valor_pago) . ",
                valor_pendente     = " . sqlMoney($valor_pendente) . ",
                data_vencimento    = " . sqlNullableDate($conn, $data_vencimento) . ",
                data_recebimento   = " . sqlNullableDate($conn, $data_recebimento) . ",
                forma_recebimento  = " . sqlNullableText($conn, $forma_recebimento) . ",
                status             = " . sqlText($conn, $status) . ",
                observacoes        = " . sqlNullableText($conn, $observacoes) . ",
                banco_id           = {$banco_sql}
            WHERE id = " . sqlText($conn, $id);
    }

    if ($conn->query($sql)) {
        if ($status === 'Recebido') {
            $reciboId = sgl_gerar_recibo_de_conta_receber($conn, $id);
            if ($reciboId) {
                $msg = "<div class='alert alert-success'>✅ Conta a Receber <strong>{$id}</strong> salva, marcada como recebida e recibo gerado automaticamente.</div>";
            } else {
                $msg = "<div class='alert alert-warning'>Conta a Receber <strong>{$id}</strong> foi salva como recebida, mas o recibo automático não pôde ser gerado.</div>";
            }
        } else {
            $msg = "<div class='alert alert-success'>✅ Conta a Receber <strong>{$id}</strong> salva.</div>";
        }
        if (function_exists('sgl_registrar_log')) { sgl_registrar_log($conn, $novo ? 'Cadastrou conta a receber' : 'Atualizou conta a receber', 'contas_receber', $id, $descricao); }
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

$lista_cp = $conn->query("SELECT cp.*, b.nome AS banco_nome FROM contas_pagar cp LEFT JOIN bancos_caixa b ON b.id = cp.banco_id WHERE cp.deletado = $filtro_deletado_cp ORDER BY cp.data_vencimento DESC, cp.id DESC");
$lista_cr = $conn->query("SELECT cr.*, c.nome AS cliente_nome, b.nome AS banco_nome FROM contas_receber cr LEFT JOIN clientes c ON c.id = cr.cliente_id LEFT JOIN bancos_caixa b ON b.id = cr.banco_id WHERE cr.deletado = $filtro_deletado_cr ORDER BY cr.data_vencimento DESC, cr.id DESC");

$hoje = date('Y-m-d');
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');

$resumoFinanceiro = [
    'pagar_aberto' => 0,
    'receber_aberto' => 0,
    'pago_mes' => 0,
    'recebido_mes' => 0,
    'vencidas_pagar' => 0,
    'vencidas_receber' => 0,
];

$q = $conn->query("SELECT COALESCE(SUM(valor_pendente),0) AS total FROM contas_pagar WHERE deletado = 0 AND status IN ('Pendente','Parcial')");
if ($q) $resumoFinanceiro['pagar_aberto'] = (float)($q->fetch_assoc()['total'] ?? 0);
$q = $conn->query("SELECT COALESCE(SUM(CASE WHEN valor_pendente > 0 THEN valor_pendente ELSE valor END),0) AS total FROM contas_receber WHERE deletado = 0 AND status IN ('Pendente','Parcial')");
if ($q) $resumoFinanceiro['receber_aberto'] = (float)($q->fetch_assoc()['total'] ?? 0);
$q = $conn->query("SELECT COALESCE(SUM(valor_pago),0) AS total FROM contas_pagar WHERE deletado = 0 AND data_pagamento BETWEEN '$inicioMes' AND '$fimMes'");
if ($q) $resumoFinanceiro['pago_mes'] = (float)($q->fetch_assoc()['total'] ?? 0);
$q = $conn->query("SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_receber WHERE deletado = 0 AND status IN ('Recebido','Pago','Quitada') AND data_recebimento BETWEEN '$inicioMes' AND '$fimMes'");
if ($q) $resumoFinanceiro['recebido_mes'] = (float)($q->fetch_assoc()['total'] ?? 0);
$q = $conn->query("SELECT COUNT(*) AS total FROM contas_pagar WHERE deletado = 0 AND status IN ('Pendente','Parcial') AND data_vencimento < '$hoje'");
if ($q) $resumoFinanceiro['vencidas_pagar'] = (int)($q->fetch_assoc()['total'] ?? 0);
$q = $conn->query("SELECT COUNT(*) AS total FROM contas_receber WHERE deletado = 0 AND status IN ('Pendente','Parcial') AND data_vencimento < '$hoje'");
if ($q) $resumoFinanceiro['vencidas_receber'] = (int)($q->fetch_assoc()['total'] ?? 0);

if ($acao === 'caixa') {
    $periodo = $_GET['periodo'] ?? 'dia';
    $dataBase = $_GET['data'] ?? date('Y-m-d');
    $mesBase = $_GET['mes'] ?? date('Y-m');
    if ($periodo === 'mes') {
        $inicioCaixa = $mesBase . '-01';
        $fimCaixa = date('Y-m-t', strtotime($inicioCaixa));
        $tituloCaixa = 'Relatório de Fechamento Mensal';
        $subtituloCaixa = date('m/Y', strtotime($inicioCaixa));
    } else {
        $inicioCaixa = $fimCaixa = $dataBase;
        $tituloCaixa = 'Relatório de Fechamento Diário';
        $subtituloCaixa = date('d/m/Y', strtotime($dataBase));
    }

    $entradas = [];
    $saidas = [];
    $transferencias = [];

    $isCaixa = function($nome, $tipo): bool {
        $n = mb_strtoupper(trim((string)$nome), 'UTF-8');
        $t = mb_strtoupper(trim((string)$tipo), 'UTF-8');
        return $n === 'CAIXA' || $t === 'CAIXA' || str_contains($n, 'CAIXA');
    };

    // Entradas operacionais: recebimentos reais de clientes/honorários/contas.
    $sqlEntradas = "SELECT cr.id, cr.descricao, cr.valor_pago, cr.valor, cr.data_recebimento, cr.forma_recebimento, cr.status, c.nome AS cliente_nome, b.nome AS banco_nome, b.tipo AS banco_tipo FROM contas_receber cr LEFT JOIN clientes c ON c.id=cr.cliente_id LEFT JOIN bancos_caixa b ON b.id=cr.banco_id WHERE cr.deletado=0 AND cr.status IN ('Recebido','Pago','Quitada') AND cr.data_recebimento BETWEEN '$inicioCaixa' AND '$fimCaixa' ORDER BY cr.data_recebimento ASC, cr.id ASC";
    $res = $conn->query($sqlEntradas);
    if ($res) while($r=$res->fetch_assoc()) $entradas[]=$r;

    // Saídas operacionais: despesas reais pagas.
    $sqlSaidas = "SELECT cp.id, cp.descricao, cp.categoria, cp.fornecedor, cp.valor_pago, cp.valor, cp.data_pagamento, cp.forma_pagamento, cp.status, b.nome AS banco_nome, b.tipo AS banco_tipo FROM contas_pagar cp LEFT JOIN bancos_caixa b ON b.id=cp.banco_id WHERE cp.deletado=0 AND cp.status IN ('Pago','Quitada') AND cp.data_pagamento BETWEEN '$inicioCaixa' AND '$fimCaixa' ORDER BY cp.data_pagamento ASC, cp.id ASC";
    $res = $conn->query($sqlSaidas);
    if ($res) while($r=$res->fetch_assoc()) $saidas[]=$r;

    // Transferências internas: caixa <-> bancos, banco <-> banco, banco/caixa <-> outros.
    $sqlTransferencias = "
        SELECT m.*, 
               bo.nome AS origem_nome_real, bo.tipo AS origem_tipo,
               bd.nome AS destino_nome_real, bd.tipo AS destino_tipo,
               COALESCE(bo.nome, m.origem_outros, 'OUTROS') AS origem_nome,
               COALESCE(bd.nome, m.destino_outros, 'OUTROS') AS destino_nome
        FROM bancos_movimentacoes m
        LEFT JOIN bancos_caixa bo ON bo.id = m.banco_origem_id
        LEFT JOIN bancos_caixa bd ON bd.id = m.banco_destino_id
        WHERE m.data_movimento BETWEEN '$inicioCaixa' AND '$fimCaixa'
        ORDER BY m.data_movimento ASC, m.id ASC
    ";
    $res = $conn->query($sqlTransferencias);
    if ($res) while($r=$res->fetch_assoc()) $transferencias[]=$r;

    $totalEntradasOperacionais = 0.0;
    $totalSaidasOperacionais = 0.0;
    $entradasCaixa = 0.0;
    $entradasBancos = 0.0;
    $saidasCaixa = 0.0;
    $saidasBancos = 0.0;

    foreach ($entradas as $r) {
        $valor = (float)($r['valor_pago'] ?: $r['valor']);
        $totalEntradasOperacionais += $valor;
        if ($isCaixa($r['banco_nome'] ?? '', $r['banco_tipo'] ?? '')) $entradasCaixa += $valor; else $entradasBancos += $valor;
    }
    foreach ($saidas as $r) {
        $valor = (float)($r['valor_pago'] ?: $r['valor']);
        $totalSaidasOperacionais += $valor;
        if ($isCaixa($r['banco_nome'] ?? '', $r['banco_tipo'] ?? '')) $saidasCaixa += $valor; else $saidasBancos += $valor;
    }

    $transfEntradaCaixa = 0.0;
    $transfSaidaCaixa = 0.0;
    $transfEntradaBancos = 0.0;
    $transfSaidaBancos = 0.0;
    $totalTransferenciasInternas = 0.0;

    foreach ($transferencias as $r) {
        $valor = (float)($r['valor'] ?? 0);
        if ($valor <= 0) continue;
        $totalTransferenciasInternas += $valor;
        $origemCaixa = $isCaixa($r['origem_nome_real'] ?? $r['origem_nome'] ?? '', $r['origem_tipo'] ?? '');
        $destinoCaixa = $isCaixa($r['destino_nome_real'] ?? $r['destino_nome'] ?? '', $r['destino_tipo'] ?? '');
        $origemBanco = !empty($r['banco_origem_id']) && !$origemCaixa;
        $destinoBanco = !empty($r['banco_destino_id']) && !$destinoCaixa;
        if ($destinoCaixa) $transfEntradaCaixa += $valor;
        if ($origemCaixa) $transfSaidaCaixa += $valor;
        if ($destinoBanco) $transfEntradaBancos += $valor;
        if ($origemBanco) $transfSaidaBancos += $valor;
    }

    $resultadoOperacional = $totalEntradasOperacionais - $totalSaidasOperacionais;
    $saldoPeriodoCaixa = $entradasCaixa - $saidasCaixa + $transfEntradaCaixa - $transfSaidaCaixa;
    $saldoPeriodoBancos = $entradasBancos - $saidasBancos + $transfEntradaBancos - $transfSaidaBancos;
    $saldoPeriodoGeral = $saldoPeriodoCaixa + $saldoPeriodoBancos;
    ?>
    <div class="container-fluid caixa-relatorio">
        <style>
            .relatorio-print-head{display:none;}
            .kpi-card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.06);height:100%;}
            .kpi-card .small-title{font-size:.72rem;text-transform:uppercase;color:#6b7280;letter-spacing:.03em;}
            .kpi-card .big-number{font-size:1.65rem;font-weight:800;line-height:1.1;}
            .table-relatorio th{font-size:.78rem;text-transform:uppercase;letter-spacing:.02em;}
            @media print{
                body{background:#fff!important;color:#111!important;}
                .sidebar,.sgl-sidebar,.no-print,nav,header,.navbar,.topbar,.sgl-topbar{display:none!important;}
                main,.content,.container-fluid{margin:0!important;padding:0!important;width:100%!important;max-width:100%!important;}
                .caixa-relatorio{font-family:Arial, sans-serif;font-size:11px;color:#111;}
                .relatorio-print-head{display:block!important;text-align:center;border-bottom:2px solid #111;margin-bottom:12px;padding-bottom:8px;}
                .relatorio-print-head h1{font-size:18px;margin:0;font-weight:800;}
                .relatorio-print-head p{font-size:11px;margin:2px 0;color:#333;}
                .card,.kpi-card{box-shadow:none!important;border:1px solid #ccc!important;border-radius:4px!important;break-inside:avoid;}
                .card-header{background:#f1f5f9!important;color:#111!important;border-bottom:1px solid #ccc!important;font-weight:700!important;}
                .table{font-size:10px!important;}
                .table th,.table td{padding:5px!important;border-color:#ddd!important;}
                .row{display:flex!important;flex-wrap:wrap!important;}
                .col-md-2,.col-md-3,.col-md-4{flex:0 0 auto!important;width:33.333%!important;margin-bottom:8px!important;}
                .kpi-card .big-number{font-size:16px!important;}
                @page{size:A4 portrait;margin:12mm;}
            }
        </style>

        <div class="relatorio-print-head">
            <h1>ROJEX.AI / SGL Advocacia</h1>
            <p><?= htmlspecialchars($tituloCaixa) ?> — Período: <?= htmlspecialchars($subtituloCaixa) ?></p>
            <p>Emitido em <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($_SESSION['nome'] ?? $_SESSION['usuario'] ?? 'Usuário') ?></p>
        </div>

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 no-print">
            <div>
                <h2 class="fw-bold text-primary"><i class="bi bi-cash-register"></i> <?= $tituloCaixa ?></h2>
                <p class="text-muted mb-0">Relatório profissional de entradas, saídas, transferências internas, caixa físico e saldos bancários: <strong><?= $subtituloCaixa ?></strong></p>
            </div>
            <div class="d-flex gap-2"><button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Imprimir / Salvar PDF</button><a href="?mod=dashboard" class="btn btn-outline-secondary">Voltar</a></div>
        </div>
        <form class="card card-body shadow-sm border-0 mb-3 no-print" method="get">
            <input type="hidden" name="mod" value="financeiro"><input type="hidden" name="acao" value="caixa"><input type="hidden" name="periodo" value="<?= htmlspecialchars($periodo) ?>">
            <div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">Data</label><input type="date" name="data" class="form-control" value="<?= htmlspecialchars($dataBase) ?>" <?= $periodo==='mes'?'disabled':'' ?>></div><div class="col-md-3"><label class="form-label">Mês</label><input type="month" name="mes" class="form-control" value="<?= htmlspecialchars($mesBase) ?>" <?= $periodo==='dia'?'disabled':'' ?>></div><div class="col-md-3"><button class="btn btn-outline-primary w-100">Filtrar</button></div></div>
        </form>

        <div class="row g-3 mb-3">
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Entradas operacionais</div><div class="big-number text-success"><?= fmtBrlFin($totalEntradasOperacionais) ?></div><small class="text-muted">Recebimentos reais</small></div></div>
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Saídas operacionais</div><div class="big-number text-danger"><?= fmtBrlFin($totalSaidasOperacionais) ?></div><small class="text-muted">Despesas reais</small></div></div>
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Transferências internas</div><div class="big-number text-secondary"><?= fmtBrlFin($totalTransferenciasInternas) ?></div><small class="text-muted">Caixa/Bancos/Outros</small></div></div>
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Resultado operacional</div><div class="big-number <?= $resultadoOperacional>=0?'text-primary':'text-danger' ?>"><?= fmtBrlFin($resultadoOperacional) ?></div><small class="text-muted">Entradas - despesas</small></div></div>
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Saldo período caixa</div><div class="big-number <?= $saldoPeriodoCaixa>=0?'text-success':'text-danger' ?>"><?= fmtBrlFin($saldoPeriodoCaixa) ?></div><small class="text-muted">Caixa físico</small></div></div>
            <div class="col-md-4 col-lg-2"><div class="kpi-card p-3"><div class="small-title">Saldo período bancos</div><div class="big-number <?= $saldoPeriodoBancos>=0?'text-success':'text-danger' ?>"><?= fmtBrlFin($saldoPeriodoBancos) ?></div><small class="text-muted">Contas bancárias</small></div></div>
        </div>

        <div class="alert alert-light border small mb-3">
            <strong>Critério contábil:</strong> transferências internas mudam onde o dinheiro está, mas não representam receita ou despesa. Por isso, o <strong>resultado operacional</strong> considera apenas entradas reais menos despesas reais. O saldo do caixa e o saldo dos bancos consideram também as transferências.
        </div>

        <div class="card shadow-sm border-0 mb-3"><div class="card-header bg-success text-white fw-bold">Entradas operacionais</div><div class="table-responsive"><table class="table table-sm table-relatorio align-middle mb-0"><thead><tr><th>Data</th><th>Descrição</th><th>Cliente</th><th>Forma</th><th>Destino financeiro</th><th class="text-end">Valor</th></tr></thead><tbody>
        <?php if(empty($entradas)): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma entrada operacional no período.</td></tr><?php endif; ?>
        <?php foreach($entradas as $r): ?><tr><td><?= date('d/m/Y', strtotime($r['data_recebimento'])) ?></td><td><?= htmlspecialchars($r['descricao'] ?: $r['id']) ?></td><td><?= htmlspecialchars($r['cliente_nome'] ?: '-') ?></td><td><?= htmlspecialchars($r['forma_recebimento'] ?: '-') ?></td><td><?= htmlspecialchars($r['banco_nome'] ?: '-') ?></td><td class="text-end fw-bold text-success"><?= fmtBrlFin($r['valor_pago'] ?: $r['valor']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>

        <div class="card shadow-sm border-0 mb-3"><div class="card-header bg-danger text-white fw-bold">Saídas operacionais</div><div class="table-responsive"><table class="table table-sm table-relatorio align-middle mb-0"><thead><tr><th>Data</th><th>Descrição</th><th>Fornecedor</th><th>Categoria</th><th>Origem financeira</th><th class="text-end">Valor</th></tr></thead><tbody>
        <?php if(empty($saidas)): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma saída operacional no período.</td></tr><?php endif; ?>
        <?php foreach($saidas as $r): ?><tr><td><?= date('d/m/Y', strtotime($r['data_pagamento'])) ?></td><td><?= htmlspecialchars($r['descricao'] ?: $r['id']) ?></td><td><?= htmlspecialchars($r['fornecedor'] ?: '-') ?></td><td><?= htmlspecialchars($r['categoria'] ?: '-') ?></td><td><?= htmlspecialchars($r['banco_nome'] ?: '-') ?></td><td class="text-end fw-bold text-danger"><?= fmtBrlFin($r['valor_pago'] ?: $r['valor']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>

        <div class="card shadow-sm border-0 mb-3"><div class="card-header bg-dark text-white fw-bold">Transferências internas</div><div class="table-responsive"><table class="table table-sm table-relatorio align-middle mb-0"><thead><tr><th>Data</th><th>Origem</th><th>Destino</th><th>Descrição</th><th>Responsável</th><th class="text-end">Valor</th></tr></thead><tbody>
        <?php if(empty($transferencias)): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma transferência interna no período.</td></tr><?php endif; ?>
        <?php foreach($transferencias as $r): ?><tr><td><?= date('d/m/Y', strtotime($r['data_movimento'])) ?></td><td><?= htmlspecialchars($r['origem_nome'] ?: '-') ?></td><td><?= htmlspecialchars($r['destino_nome'] ?: '-') ?></td><td><?= htmlspecialchars($r['descricao'] ?: 'Transferência interna') ?></td><td><?= htmlspecialchars($r['usuario_nome'] ?: '-') ?></td><td class="text-end fw-bold"><?= fmtBrlFin($r['valor']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>

        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="kpi-card p-3"><div class="small-title">Saldo do período geral</div><div class="big-number <?= $saldoPeriodoGeral>=0?'text-primary':'text-danger' ?>"><?= fmtBrlFin($saldoPeriodoGeral) ?></div><small class="text-muted">Caixa + bancos no período</small></div></div>
            <div class="col-md-4"><div class="kpi-card p-3"><div class="small-title">Caixa físico</div><div class="big-number <?= $saldoPeriodoCaixa>=0?'text-success':'text-danger' ?>"><?= fmtBrlFin($saldoPeriodoCaixa) ?></div><small class="text-muted">Entradas/saídas que afetaram o caixa</small></div></div>
            <div class="col-md-4"><div class="kpi-card p-3"><div class="small-title">Bancos</div><div class="big-number <?= $saldoPeriodoBancos>=0?'text-success':'text-danger' ?>"><?= fmtBrlFin($saldoPeriodoBancos) ?></div><small class="text-muted">Entradas/saídas que afetaram bancos</small></div></div>
        </div>

        <div class="mt-4 small text-muted d-none d-print-block">
            <div style="display:flex;gap:40px;margin-top:30px;">
                <div style="flex:1;border-top:1px solid #333;text-align:center;padding-top:6px;">Responsável pelo fechamento</div>
                <div style="flex:1;border-top:1px solid #333;text-align:center;padding-top:6px;">Conferência administrativa</div>
            </div>
        </div>
    </div>
    <?php return; }


if ($acao === 'movimentacao_bancos') {
    $bancos = financeiroListaBancos($conn, false);
    $movs = [];
    $resMov = $conn->query("SELECT m.*, COALESCE(bo.nome, m.origem_outros, 'OUTROS') AS origem_nome, COALESCE(bd.nome, m.destino_outros, 'OUTROS') AS destino_nome FROM bancos_movimentacoes m LEFT JOIN bancos_caixa bo ON bo.id=m.banco_origem_id LEFT JOIN bancos_caixa bd ON bd.id=m.banco_destino_id ORDER BY m.data_movimento DESC, m.id DESC LIMIT 80");
    if ($resMov) while($r=$resMov->fetch_assoc()) $movs[]=$r;
    ?>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div><h2 class="fw-bold text-primary"><i class="bi bi-arrow-left-right"></i> Movimentação Bancária e Caixa</h2><p class="text-muted mb-0">Controle o que ficou em caixa físico, PIX e bancos da empresa.</p></div>
            <div class="d-flex gap-2"><a href="?mod=financeiro&acao=bancos" class="btn btn-outline-dark"><i class="bi bi-bank"></i> Cadastrar bancos</a><a href="?mod=financeiro" class="btn btn-outline-secondary">Voltar</a></div>
        </div>
        <?= $msg ?>
        <div class="row g-3 mb-3">
            <?php if(empty($bancos)): ?><div class="col-12"><div class="alert alert-warning">Cadastre pelo menos uma conta em Bancos/Caixa.</div></div><?php endif; ?>
            <?php foreach($bancos as $b): $saldoBanco = financeiroSaldoBanco($conn, (int)$b['id']); ?>
                <div class="col-md-3"><div class="card shadow-sm border-0 h-100"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="text-muted small text-uppercase"><?= htmlspecialchars($b['tipo']) ?></div><h5 class="fw-bold mb-1"><?= htmlspecialchars($b['nome']) ?></h5><small class="text-muted"><?= htmlspecialchars(trim(($b['banco']??'') . ' ' . ($b['conta']??'')) ?: '-') ?></small></div><i class="bi bi-bank fs-2 text-primary opacity-50"></i></div><div class="fs-4 fw-bold mt-3 <?= $saldoBanco>=0?'text-success':'text-danger' ?>"><?= fmtBrlFin($saldoBanco) ?></div><small class="text-muted">Saldo atual estimado</small></div></div></div>
            <?php endforeach; ?>
        </div>
        <div class="card shadow-sm border-0 mb-3"><div class="card-header bg-dark text-white fw-bold">Transferir entre Caixa/Bancos/Outros</div><div class="card-body"><form method="post" class="row g-3" id="formTransferenciaBanco"><input type="hidden" name="transferir_banco" value="1"><div class="col-md-3"><label class="form-label">Data</label><input type="date" name="data_movimento" class="form-control" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-3"><label class="form-label">Origem</label><select name="banco_origem_id" id="banco_origem_id" class="form-select" required><option value="">Selecione...</option><?php foreach($bancos as $b): ?><option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['nome']) ?></option><?php endforeach; ?><option value="OUTROS">OUTROS</option></select><input name="origem_outros" id="origem_outros" class="form-control mt-2 d-none" placeholder="Descreva a origem"></div><div class="col-md-3"><label class="form-label">Destino</label><select name="banco_destino_id" id="banco_destino_id" class="form-select" required><option value="">Selecione...</option><?php foreach($bancos as $b): ?><option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['nome']) ?></option><?php endforeach; ?><option value="OUTROS">OUTROS</option></select><input name="destino_outros" id="destino_outros" class="form-control mt-2 d-none" placeholder="Descreva o destino"></div><div class="col-md-3"><label class="form-label">Valor</label><input name="valor_transferencia" id="valor_transferencia" class="form-control" inputmode="decimal" autocomplete="off" placeholder="Ex.: 1.000,00" required><div class="form-text">Digite 1000,00 ou 1.000,00.</div></div><div class="col-md-9"><label class="form-label">Descrição</label><input name="descricao_transferencia" class="form-control" placeholder="Ex.: depósito do caixa físico na conta corrente"></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-check-circle"></i> Registrar transferência</button></div></form><script>(function(){function t(sel,input){var s=document.getElementById(sel),i=document.getElementById(input);if(!s||!i)return;function u(){i.classList.toggle('d-none',s.value!=='OUTROS');if(s.value!=='OUTROS')i.value='';}s.addEventListener('change',u);u();}t('banco_origem_id','origem_outros');t('banco_destino_id','destino_outros');var v=document.getElementById('valor_transferencia');if(v){v.addEventListener('blur',function(){var x=this.value.replace(/[^0-9,.]/g,''); if(!x)return; var n=x; if(n.indexOf(',')>=0){n=n.replace(/\./g,'').replace(',','.');} var f=parseFloat(n); if(!isNaN(f)){this.value=f.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}});}})();</script></div></div>
        <div class="card shadow-sm border-0"><div class="card-header bg-dark text-white fw-bold">Histórico de transferências</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Data</th><th>Origem</th><th>Destino</th><th>Descrição</th><th>Responsável</th><th class="text-end">Valor</th></tr></thead><tbody><?php if(empty($movs)): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma transferência registrada.</td></tr><?php endif; foreach($movs as $m): ?><tr><td><?= date('d/m/Y', strtotime($m['data_movimento'])) ?></td><td><?= htmlspecialchars($m['origem_nome'] ?: '-') ?></td><td><?= htmlspecialchars($m['destino_nome'] ?: '-') ?></td><td><?= htmlspecialchars($m['descricao'] ?: '-') ?></td><td><?= htmlspecialchars($m['usuario_nome'] ?: '-') ?></td><td class="text-end fw-bold"><?= fmtBrlFin($m['valor']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
    <?php return; }

if ($acao === 'bancos') {
    $bancos = financeiroListaBancos($conn, false);
    ?>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start mb-3"><div><h2 class="fw-bold text-primary"><i class="bi bi-bank"></i> Bancos / Caixa</h2><p class="text-muted mb-0">Cadastre onde o dinheiro entra ou sai: caixa, PIX, banco, conta corrente ou poupança.</p></div><div class="d-flex gap-2"><a href="?mod=financeiro&acao=movimentacao_bancos" class="btn btn-outline-success"><i class="bi bi-arrow-left-right"></i> Movimentação</a><a href="?mod=financeiro" class="btn btn-outline-secondary">Voltar</a></div></div>
        <?= $msg ?>
        <div class="card shadow-sm border-0 mb-3"><div class="card-header bg-dark text-white fw-bold">Novo Banco/Caixa</div><div class="card-body"><form method="post" class="row g-3"><input type="hidden" name="salvar_banco" value="1"><div class="col-md-4"><label class="form-label">Nome *</label><input name="nome" class="form-control" placeholder="Ex.: Caixa Escritório, PIX Itaú, Banco do Brasil" required></div><div class="col-md-2"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option>Caixa</option><option>PIX</option><option>Conta Corrente</option><option>Poupança</option><option>Cartão</option></select></div><div class="col-md-2"><label class="form-label">Banco</label><input name="banco" class="form-control"></div><div class="col-md-1"><label class="form-label">Agência</label><input name="agencia" class="form-control"></div><div class="col-md-2"><label class="form-label">Conta</label><input name="conta" class="form-control"></div><div class="col-md-1"><label class="form-label">Ativo</label><div class="form-check mt-2"><input type="checkbox" class="form-check-input" name="ativo" checked></div></div><div class="col-md-3"><label class="form-label">Saldo inicial</label><input name="saldo_inicial" class="form-control" placeholder="0,00"></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-save"></i> Salvar</button></div></form></div></div>
        <div class="card shadow-sm border-0"><div class="card-header bg-dark text-white fw-bold">Contas cadastradas</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nome</th><th>Tipo</th><th>Banco</th><th>Agência</th><th>Conta</th><th>Saldo inicial</th><th>Status</th></tr></thead><tbody><?php if(empty($bancos)): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhum banco/caixa cadastrado.</td></tr><?php endif; foreach($bancos as $b): ?><tr><td class="fw-semibold"><?= htmlspecialchars($b['nome']) ?></td><td><?= htmlspecialchars($b['tipo']) ?></td><td><?= htmlspecialchars($b['banco'] ?: '-') ?></td><td><?= htmlspecialchars($b['agencia'] ?: '-') ?></td><td><?= htmlspecialchars($b['conta'] ?: '-') ?></td><td><?= fmtBrlFin($b['saldo_inicial']) ?></td><td><span class="badge bg-<?= $b['ativo']?'success':'secondary' ?>"><?= $b['ativo']?'Ativo':'Inativo' ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
    <?php return; }

?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h2 class="mb-1"><i class="bi bi-cash-coin"></i> Financeiro</h2>
            <p class="text-muted mb-0">Controle de contas a pagar, contas a receber, parcelas e fluxo financeiro do escritório.</p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?mod=financeiro&aba=cp&acao=novo_cp" class="btn btn-outline-danger"><i class="bi bi-plus-circle"></i> Nova despesa</a>
            <a href="?mod=financeiro&acao=movimentacao_bancos" class="btn btn-outline-success"><i class="bi bi-arrow-left-right"></i> Movimentação</a><a href="?mod=financeiro&acao=bancos" class="btn btn-outline-dark"><i class="bi bi-bank"></i> Bancos/Caixa</a>
            <a href="?mod=financeiro&aba=cr&acao=novo_cr" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Novo recebimento</a>
        </div>
    </div>

    <?= $msg ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">A receber em aberto</div>
                <div class="fs-4 fw-bold text-primary"><?= fmtBrlFin($resumoFinanceiro['receber_aberto']) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">A pagar em aberto</div>
                <div class="fs-4 fw-bold text-danger"><?= fmtBrlFin($resumoFinanceiro['pagar_aberto']) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Recebido no mês</div>
                <div class="fs-4 fw-bold text-success"><?= fmtBrlFin($resumoFinanceiro['recebido_mes']) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100"><div class="card-body">
                <div class="text-muted small text-uppercase">Alertas vencidos</div>
                <div class="fs-4 fw-bold text-warning"><?= (int)($resumoFinanceiro['vencidas_pagar'] + $resumoFinanceiro['vencidas_receber']) ?></div>
                <div class="small text-muted">pagar: <?= (int)$resumoFinanceiro['vencidas_pagar'] ?> | receber: <?= (int)$resumoFinanceiro['vencidas_receber'] ?></div>
            </div></div>
        </div>
    </div>

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
                                        <th>Banco/Caixa</th>
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
                                            <td><?= htmlspecialchars($row['banco_nome'] ?? '-') ?></td>
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
                                                    <?php if (in_array(($row['status'] ?? ''), ['Pendente','Parcial'], true)): ?>
                                                        <a href="?mod=financeiro&aba=cp&pagar_cp=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrf_token_fin) ?>"
                                                           class="btn btn-sm btn-success"
                                                           onclick="return confirm('Confirmar pagamento da conta <?= htmlspecialchars($row['id']) ?>?')"
                                                           title="Marcar como pago">💸</a>
                                                    <?php endif; ?>
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
                    'banco_id'        => '',
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
                                <label class="form-label">Forma de Pagamento</label>
                                <select name="forma_pagamento" class="form-select">
                                    <?php foreach (['','PIX','Dinheiro','Cartão','Transferência','Boleto','Cheque','Outro'] as $forma): ?>
                                        <option value="<?= htmlspecialchars($forma) ?>" <?= ($conta_cp['forma_pagamento'] ?? '') === $forma ? 'selected' : '' ?>>
                                            <?= $forma === '' ? 'Selecione' : htmlspecialchars($forma) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Banco/Caixa</label>
                                <?= financeiroSelectBanco($conn, $conta_cp['banco_id'] ?? null) ?>
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
                                        <th>Cliente</th>
                                        <th>Valor</th>
                                        <th>Vencimento</th>
                                        <th>Banco/Caixa</th>
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
                                            <td><?= htmlspecialchars($row['cliente_nome'] ?? '-') ?></td>
                                            <td><?= fmtBrlFin($row['valor'] ?? 0) ?></td>
                                            <td><?= !empty($row['data_vencimento']) ? date('d/m/Y', strtotime($row['data_vencimento'])) : '-' ?></td>
                                            <td><?= htmlspecialchars($row['banco_nome'] ?? '-') ?></td>
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
                                                    <?php if (in_array(($row['status'] ?? ''), ['Pendente','Parcial'], true)): ?>
                                                        <a href="?mod=financeiro&aba=cr&receber_cr=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrf_token_fin) ?>"
                                                           class="btn btn-sm btn-success"
                                                           onclick="return confirm('Confirmar recebimento e gerar recibo da conta <?= htmlspecialchars($row['id']) ?>?')"
                                                           title="Receber e gerar recibo">💵</a>
                                                    <?php else: ?>
                                                        <?php $reciboVinculado = buscarReciboPorContaReceber($conn, (string)$row['id']); ?>
                                                        <?php if ($reciboVinculado): ?>
                                                            <a href="?mod=recibos&acao=imprimir&id=<?= urlencode($reciboVinculado['id']) ?>"
                                                               class="btn btn-sm btn-outline-primary"
                                                               title="Ver recibo <?= htmlspecialchars($reciboVinculado['numero']) ?>">🧾</a>
                                                        <?php else: ?>
                                                            <a href="?mod=financeiro&aba=cr&gerar_recibo_cr=<?= urlencode($row['id']) ?>&csrf_token=<?= urlencode($csrf_token_fin) ?>"
                                                               class="btn btn-sm btn-outline-success"
                                                               onclick="return confirm('Gerar recibo para a conta <?= htmlspecialchars($row['id']) ?>?')"
                                                               title="Gerar recibo">🧾+</a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
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
                                        <td colspan="7" class="text-center text-muted py-4">
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
                    'forma_recebimento'=> '',
                    'observacoes'      => '',
                    'banco_id'          => '',
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
                            <div class="col-md-4">
                                <label class="form-label">Forma de Recebimento</label>
                                <select name="forma_recebimento" class="form-select">
                                    <?php foreach (['','PIX','Dinheiro','Cartão','Transferência','Boleto','Cheque','Outro'] as $forma): ?>
                                        <option value="<?= htmlspecialchars($forma) ?>" <?= ($conta_cr['forma_recebimento'] ?? '') === $forma ? 'selected' : '' ?>>
                                            <?= $forma === '' ? 'Selecione' : htmlspecialchars($forma) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Banco/Caixa</label>
                                <?= financeiroSelectBanco($conn, $conta_cr['banco_id'] ?? null) ?>
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
        <img src="/sgl_advocacia/assets/img/logo_custom.png" alt="SGL" class="logo">
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