<?php
/**
 * Integrações internas do SGL Advocacia.
 *
 * Objetivo: manter os módulos conversando entre si sem reescrever a arquitetura atual.
 * Fluxo inicial implementado:
 * Honorários -> Financeiro/Contas a Receber -> Recibos.
 */



if (!function_exists('sgl_garantir_logs')) {
    function sgl_garantir_logs(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS logs_sistema (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            usuario_nome VARCHAR(150) NULL,
            usuario_login VARCHAR(80) NULL,
            usuario_perfil VARCHAR(80) NULL,
            acao VARCHAR(100) NOT NULL,
            tabela VARCHAR(80) NULL,
            registro_id VARCHAR(40) NULL,
            detalhes TEXT NULL,
            ip VARCHAR(45) NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_logs_usuario (usuario_id),
            INDEX idx_logs_tabela (tabela),
            INDEX idx_logs_data (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Compatibilidade com instalações que já possuíam logs_sistema sem dados do responsável.
        if (function_exists('sgl_int_add_coluna')) {
            sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_nome', "usuario_nome VARCHAR(150) NULL");
            sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_login', "usuario_login VARCHAR(80) NULL");
            sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_perfil', "usuario_perfil VARCHAR(80) NULL");
        }
    }
}

if (!function_exists('sgl_registrar_log')) {
    function sgl_registrar_log(mysqli $conn, string $acao, ?string $tabela = null, ?string $registro_id = null, ?string $detalhes = null): void
    {
        try {
            sgl_garantir_logs($conn);
            $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $usuario_nome = $_SESSION['nome'] ?? 'Sistema';
            $usuario_login = $_SESSION['username'] ?? null;
            $usuario_perfil = $_SESSION['perfil'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $detalhes = trim((string)($detalhes ?? '') . ' | Responsável: ' . $usuario_nome . ($usuario_perfil ? ' (' . $usuario_perfil . ')' : ''));

            $stmt = $conn->prepare("INSERT INTO logs_sistema (usuario_id, usuario_nome, usuario_login, usuario_perfil, acao, tabela, registro_id, detalhes, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('issssssss', $usuario_id, $usuario_nome, $usuario_login, $usuario_perfil, $acao, $tabela, $registro_id, $detalhes, $ip);
                @$stmt->execute();
                @$stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[SGL LOG] ' . $e->getMessage());
        }
    }
}


if (!function_exists('sgl_completar_logs_sem_responsavel')) {
    function sgl_completar_logs_sem_responsavel(mysqli $conn): void
    {
        try {
            if (empty($_SESSION['user_id']) && empty($_SESSION['nome']) && empty($_SESSION['username'])) return;
            sgl_garantir_logs($conn);
            $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $usuario_nome = $conn->real_escape_string($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário logado');
            $usuario_login = $conn->real_escape_string($_SESSION['username'] ?? '');
            $usuario_perfil = $conn->real_escape_string($_SESSION['perfil'] ?? '');
            $uidSql = $usuario_id > 0 ? (string)$usuario_id : 'NULL';
            $conn->query("UPDATE logs_sistema SET usuario_id = COALESCE(usuario_id, {$uidSql}), usuario_nome = IF(usuario_nome IS NULL OR usuario_nome='' OR usuario_nome='Sistema', '{$usuario_nome}', usuario_nome), usuario_login = IF(usuario_login IS NULL OR usuario_login='', '{$usuario_login}', usuario_login), usuario_perfil = IF(usuario_perfil IS NULL OR usuario_perfil='', '{$usuario_perfil}', usuario_perfil) WHERE usuario_nome IS NULL OR usuario_nome='' OR usuario_nome='Sistema' OR usuario_login IS NULL OR usuario_login=''");
        } catch (Throwable $e) {
            error_log('[SGL LOG BACKFILL] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sgl_int_coluna_existe')) {
    function sgl_int_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
    {
        $tabela = $conn->real_escape_string($tabela);
        $coluna = $conn->real_escape_string($coluna);
        $res = $conn->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('sgl_int_add_coluna')) {
    function sgl_int_add_coluna(mysqli $conn, string $tabela, string $coluna, string $definicao): void
    {
        if (!sgl_int_coluna_existe($conn, $tabela, $coluna)) {
            @$conn->query("ALTER TABLE `{$tabela}` ADD COLUMN {$definicao}");
        }
    }
}

if (!function_exists('sgl_integracao_garantir_financeiro')) {
    function sgl_integracao_garantir_financeiro(mysqli $conn): void
    {
        sgl_int_add_coluna($conn, 'contas_receber', 'honorario_id', "honorario_id VARCHAR(20) NULL");
        sgl_int_add_coluna($conn, 'contas_receber', 'parcela_id', "parcela_id VARCHAR(20) NULL");
        sgl_int_add_coluna($conn, 'contas_receber', 'origem', "origem VARCHAR(50) NULL");
        sgl_int_add_coluna($conn, 'contas_receber', 'forma_recebimento', "forma_recebimento VARCHAR(80) NULL");
        sgl_int_add_coluna($conn, 'contas_receber', 'valor_pago', "valor_pago DECIMAL(12,2) DEFAULT 0");
        sgl_int_add_coluna($conn, 'contas_receber', 'valor_pendente', "valor_pendente DECIMAL(12,2) DEFAULT 0");
        sgl_int_add_coluna($conn, 'contas_receber', 'data_recebimento', "data_recebimento DATE NULL");
        sgl_int_add_coluna($conn, 'contas_receber', 'criado_em', "criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        sgl_int_add_coluna($conn, 'contas_receber', 'atualizado_em', "atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        sgl_int_add_coluna($conn, 'contas_receber', 'deletado', "deletado TINYINT(1) NOT NULL DEFAULT 0");
    }
}

if (!function_exists('sgl_integracao_garantir_recibos')) {
    function sgl_integracao_garantir_recibos(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS recibos (
            id VARCHAR(20) PRIMARY KEY,
            numero VARCHAR(30) NOT NULL UNIQUE,
            cliente_id VARCHAR(10) NULL,
            nome_cliente VARCHAR(150) NOT NULL,
            cpf_cnpj VARCHAR(25) NULL,
            processo_numero VARCHAR(80) NULL,
            honorario_id VARCHAR(20) NULL,
            parcela_id VARCHAR(20) NULL,
            conta_receber_id VARCHAR(20) NULL,
            data_emissao DATE NOT NULL,
            data_pagamento DATE NULL,
            referente VARCHAR(255) NOT NULL,
            forma_pagamento VARCHAR(80) NULL,
            valor DECIMAL(12,2) NOT NULL DEFAULT 0,
            observacoes TEXT NULL,
            status ENUM('Emitido','Cancelado') NOT NULL DEFAULT 'Emitido',
            chave_validacao VARCHAR(80) NULL,
            deletado TINYINT(1) NOT NULL DEFAULT 0,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rec_numero (numero),
            INDEX idx_rec_cliente (cliente_id),
            INDEX idx_rec_status (status),
            INDEX idx_rec_deletado (deletado),
            INDEX idx_rec_data (data_emissao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        sgl_int_add_coluna($conn, 'recibos', 'conta_receber_id', "conta_receber_id VARCHAR(20) NULL");
    }
}

if (!function_exists('sgl_integracao_gerar_id_cr')) {
    function sgl_integracao_gerar_id_cr(mysqli $conn): string
    {
        $res = $conn->query("SELECT id FROM contas_receber WHERE id LIKE 'CR%' ORDER BY CAST(SUBSTRING(id, 3) AS UNSIGNED) DESC LIMIT 1");
        if (!$res || $res->num_rows === 0) return 'CR001';
        $ultimo = (string)$res->fetch_assoc()['id'];
        $num = (int)substr($ultimo, 2) + 1;
        return 'CR' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sgl_integracao_gerar_id_recibo')) {
    function sgl_integracao_gerar_id_recibo(mysqli $conn): string
    {
        $res = $conn->query("SELECT id FROM recibos ORDER BY CAST(SUBSTRING(id, 4) AS UNSIGNED) DESC LIMIT 1");
        if (!$res || $res->num_rows === 0) return 'REC001';
        $num = (int)substr((string)$res->fetch_assoc()['id'], 3) + 1;
        return 'REC' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sgl_integracao_gerar_numero_recibo')) {
    function sgl_integracao_gerar_numero_recibo(mysqli $conn): string
    {
        $ano = date('Y');
        $prefixo = 'REC-' . $ano . '-';
        $prefixoSql = $conn->real_escape_string($prefixo);
        $res = $conn->query("SELECT numero FROM recibos WHERE numero LIKE '{$prefixoSql}%' ORDER BY numero DESC LIMIT 1");
        if (!$res || $res->num_rows === 0) return $prefixo . '0001';
        $seq = (int)substr((string)$res->fetch_assoc()['numero'], -4) + 1;
        return $prefixo . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('sgl_sincronizar_honorario_financeiro')) {
    function sgl_sincronizar_honorario_financeiro(mysqli $conn, string $honorario_id): void
    {
        sgl_integracao_garantir_financeiro($conn);
        $honorario_id_sql = $conn->real_escape_string($honorario_id);

        $res = $conn->query("SELECT * FROM honorarios WHERE id = '{$honorario_id_sql}' LIMIT 1");
        if (!$res || $res->num_rows === 0) return;
        $h = $res->fetch_assoc();

        $resParcelas = $conn->query("SELECT * FROM honorarios_parcelas WHERE honorario_id = '{$honorario_id_sql}' ORDER BY parcela_numero ASC");
        if (!$resParcelas) return;

        while ($p = $resParcelas->fetch_assoc()) {
            $parcelaId = (string)$p['id'];
            $parcelaIdSql = $conn->real_escape_string($parcelaId);

            $valor = (float)($p['valor_parcela'] ?? 0);
            $valorPago = (float)($p['valor_pago'] ?? 0);
            $saldo = (float)($p['saldo_devedor'] ?? max(0, $valor - $valorPago));
            $statusParcela = (string)($p['status_pagamento'] ?? 'Pendente');
            $statusCR = 'Pendente';
            if ($statusParcela === 'Pago') $statusCR = 'Recebido';
            elseif ($statusParcela === 'Parcial') $statusCR = 'Parcial';

            $dataRecebimento = null;
            if ($statusCR === 'Recebido' && sgl_int_coluna_existe($conn, 'honorarios_parcelas', 'data_pagamento')) {
                $dataRecebimento = $p['data_pagamento'] ?: date('Y-m-d');
            } elseif ($statusCR === 'Recebido') {
                $dataRecebimento = date('Y-m-d');
            }

            $descricao = 'Honorários - ' . ($p['nome_cliente'] ?: ($h['nome_cliente'] ?? 'Cliente')) . ' - Parcela ' . (int)$p['parcela_numero'];
            if (!empty($p['numero_processo'])) {
                $descricao .= ' - Proc. ' . $p['numero_processo'];
            }

            $ex = $conn->query("SELECT id FROM contas_receber WHERE honorario_id = '{$honorario_id_sql}' AND parcela_id = '{$parcelaIdSql}' LIMIT 1");
            if ($ex && $ex->num_rows) {
                $crId = $ex->fetch_assoc()['id'];
                $stmt = $conn->prepare("UPDATE contas_receber SET cliente_id=?, descricao=?, valor=?, valor_parcela=?, valor_pago=?, valor_pendente=?, data_vencimento=?, data_recebimento=?, forma_recebimento=?, status=?, observacoes=?, origem='honorarios', deletado=0 WHERE id=?");
                $obs = $p['observacoes'] ?? ($h['observacoes'] ?? '');
                $clienteId = $p['cliente_id'] ?? ($h['cliente_id'] ?? null);
                $forma = $p['forma_pagamento'] ?? ($h['forma_pagamento'] ?? '');
                $dataVenc = $p['data_vencimento'] ?? null;
                $stmt->bind_param('ssddddssssss', $clienteId, $descricao, $valor, $valor, $valorPago, $saldo, $dataVenc, $dataRecebimento, $forma, $statusCR, $obs, $crId);
                @$stmt->execute();
                @$stmt->close();
            } else {
                $crId = sgl_integracao_gerar_id_cr($conn);
                $obs = $p['observacoes'] ?? ($h['observacoes'] ?? '');
                $clienteId = $p['cliente_id'] ?? ($h['cliente_id'] ?? null);
                $forma = $p['forma_pagamento'] ?? ($h['forma_pagamento'] ?? '');
                $dataVenc = $p['data_vencimento'] ?? null;
                $stmt = $conn->prepare("INSERT INTO contas_receber (id, cliente_id, descricao, valor, qtd_parcelas, valor_parcela, valor_pago, valor_pendente, data_vencimento, data_recebimento, forma_recebimento, status, observacoes, deletado, honorario_id, parcela_id, origem) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'honorarios')");
                $stmt->bind_param('sssddddsssssss', $crId, $clienteId, $descricao, $valor, $valor, $valorPago, $saldo, $dataVenc, $dataRecebimento, $forma, $statusCR, $obs, $honorario_id, $parcelaId);
                @$stmt->execute();
                @$stmt->close();
            }

            if ($statusCR === 'Recebido') {
                sgl_gerar_recibo_de_conta_receber($conn, $crId);
            }
        }
    }
}

if (!function_exists('sgl_gerar_recibo_de_conta_receber')) {
    function sgl_gerar_recibo_de_conta_receber(mysqli $conn, string $conta_receber_id): ?string
    {
        sgl_integracao_garantir_recibos($conn);
        $crIdSql = $conn->real_escape_string($conta_receber_id);

        $ja = $conn->query("SELECT id FROM recibos WHERE conta_receber_id = '{$crIdSql}' AND status <> 'Cancelado' AND deletado = 0 LIMIT 1");
        if ($ja && $ja->num_rows) return $ja->fetch_assoc()['id'];

        $res = $conn->query("SELECT cr.*, c.nome AS cliente_nome, c.cpf_cnpj FROM contas_receber cr LEFT JOIN clientes c ON c.id = cr.cliente_id WHERE cr.id = '{$crIdSql}' LIMIT 1");
        if (!$res || $res->num_rows === 0) return null;
        $cr = $res->fetch_assoc();

        $nomeCliente = $cr['cliente_nome'] ?: 'Cliente não informado';
        $cpfCnpj = $cr['cpf_cnpj'] ?? '';
        $valor = (float)($cr['valor_pago'] ?? 0);
        if ($valor <= 0) $valor = (float)($cr['valor'] ?? 0);
        if ($valor <= 0) return null;

        $id = sgl_integracao_gerar_id_recibo($conn);
        $numero = sgl_integracao_gerar_numero_recibo($conn);
        $dataHoje = date('Y-m-d');
        $dataPagamento = $cr['data_recebimento'] ?: $dataHoje;
        $referente = $cr['descricao'] ?: 'Recebimento de honorários';
        $forma = $cr['forma_recebimento'] ?? '';
        $honorarioId = $cr['honorario_id'] ?? null;
        $parcelaId = $cr['parcela_id'] ?? null;
        $clienteId = $cr['cliente_id'] ?? null;
        $chave = hash('sha256', $numero . $nomeCliente . microtime(true));

        $stmt = $conn->prepare("INSERT INTO recibos (id, numero, cliente_id, nome_cliente, cpf_cnpj, honorario_id, parcela_id, conta_receber_id, data_emissao, data_pagamento, referente, forma_pagamento, valor, observacoes, chave_validacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $obs = 'Recibo gerado automaticamente pelo Centro Financeiro.';
        $stmt->bind_param('ssssssssssssdss', $id, $numero, $clienteId, $nomeCliente, $cpfCnpj, $honorarioId, $parcelaId, $conta_receber_id, $dataHoje, $dataPagamento, $referente, $forma, $valor, $obs, $chave);
        if (@$stmt->execute()) {
            @$stmt->close();
            sgl_registrar_log($conn, 'Gerou recibo automático', 'recibos', $id, 'Conta a receber: ' . $conta_receber_id);
                return $id;
        }
        @$stmt->close();
        return null;
    }
}


if (!function_exists('buscarReciboPorContaReceber')) {
    /**
     * Retorna o recibo ativo vinculado a uma conta a receber.
     * Mantida com este nome para compatibilidade com financeiro.php.
     */
    function buscarReciboPorContaReceber(mysqli $conn, string $conta_receber_id): ?array
    {
        sgl_integracao_garantir_recibos($conn);
        $id = $conn->real_escape_string($conta_receber_id);
        $res = $conn->query("SELECT * FROM recibos WHERE conta_receber_id = '{$id}' AND deletado = 0 AND status <> 'Cancelado' ORDER BY data_emissao DESC, id DESC LIMIT 1");
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc();
        }
        return null;
    }
}

if (!function_exists('marcarContaPagarPaga')) {
    /**
     * Marca uma conta a pagar como paga, atualizando também suas parcelas quando existirem.
     */
    function marcarContaPagarPaga(mysqli $conn, string $conta_id, ?string $data_pagamento = null): bool
    {
        $id = $conn->real_escape_string($conta_id);
        $data = $conn->real_escape_string($data_pagamento ?: date('Y-m-d'));
        $res = $conn->query("SELECT valor FROM contas_pagar WHERE id = '{$id}' AND deletado = 0 LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            return false;
        }
        $valor = (float)($res->fetch_assoc()['valor'] ?? 0);
        $valorSql = number_format($valor, 2, '.', '');
        $ok = $conn->query("UPDATE contas_pagar SET valor_pago = {$valorSql}, valor_pendente = 0, status = 'Pago', data_pagamento = '{$data}' WHERE id = '{$id}'");
        if ($ok) {
            @$conn->query("UPDATE contas_pagar_parcelas SET valor_pago = valor_parcela, saldo_devedor = 0, status_pagamento = 'Pago' WHERE conta_id = '{$id}'");
        }
        return (bool)$ok;
    }
}
