<?php
/**
 * Integrações internas do ROJEX.AI ERP Jurídico Enterprise.
 *
 * Objetivo: manter os módulos conversando entre si sem reescrever a arquitetura atual.
 * Fluxo inicial implementado:
 * Honorários -> Financeiro/Contas a Receber -> Recibos.
 */




/**
 * Cache interno por requisição/conexão.
 *
 * Evita repetir verificações de compatibilidade dentro da mesma execução PHP.
 * Alterações estruturais são executadas exclusivamente por migrações SQL.
 */
if (!function_exists('sgl_int_cache_key')) {
    function sgl_int_cache_key(mysqli $conn, string $sufixo): string
    {
        return spl_object_id($conn) . ':' . $sufixo;
    }
}

if (!function_exists('sgl_int_log_erro')) {
    function sgl_int_log_erro(string $contexto, Throwable|string $erro): void
    {
        $mensagem = $erro instanceof Throwable ? $erro->getMessage() : $erro;
        error_log('[ROJEX INTEGRAÇÕES][' . $contexto . '] ' . $mensagem);
    }
}

if (!function_exists('sgl_int_eh_colisao_chave')) {
    function sgl_int_eh_colisao_chave(mysqli_sql_exception|Throwable $e): bool
    {
        $codigo = (int)$e->getCode();
        $mensagem = mb_strtolower($e->getMessage(), 'UTF-8');

        return $codigo === 1062
            || str_contains($mensagem, 'duplicate entry')
            || str_contains($mensagem, 'duplicada')
            || str_contains($mensagem, 'unique constraint');
    }
}

if (!function_exists('sgl_garantir_logs')) {
    function sgl_garantir_logs(mysqli $conn): void
    {
        static $garantido = [];
        $cacheKey = sgl_int_cache_key($conn, 'logs');

        if (!empty($garantido[$cacheKey])) {
            return;
        }

        try {
            if (function_exists('sgl_int_add_coluna')) {
                sgl_int_add_coluna($conn, 'logs_sistema', 'tenant_id', "tenant_id VARCHAR(80) NULL AFTER id");
                sgl_int_add_coluna($conn, 'logs_sistema', 'escritorio_id', "escritorio_id BIGINT NULL AFTER tenant_id");
                sgl_int_add_coluna($conn, 'logs_sistema', 'escopo', "escopo VARCHAR(20) NOT NULL DEFAULT 'LEGADO' AFTER escritorio_id");
                sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_nome', "usuario_nome VARCHAR(150) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_login', "usuario_login VARCHAR(80) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'usuario_perfil', "usuario_perfil VARCHAR(80) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'tipo_acao', "tipo_acao VARCHAR(50) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'modulo', "modulo VARCHAR(100) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'dados_anteriores', "dados_anteriores LONGTEXT NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'dados_novos', "dados_novos LONGTEXT NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'origem', "origem VARCHAR(80) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'resultado', "resultado VARCHAR(30) NOT NULL DEFAULT 'SUCESSO'");
                sgl_int_add_coluna($conn, 'logs_sistema', 'nivel', "nivel VARCHAR(20) NOT NULL DEFAULT 'INFO'");
                sgl_int_add_coluna($conn, 'logs_sistema', 'sessao_id', "sessao_id VARCHAR(128) NULL");
                sgl_int_add_coluna($conn, 'logs_sistema', 'user_agent', "user_agent VARCHAR(255) NULL");
            }

            $garantido[$cacheKey] = true;
        } catch (Throwable $e) {
            sgl_int_log_erro('GARANTIR_LOGS', $e);
        }
    }
}

if (!function_exists('sgl_log_contexto_multi_tenant')) {
    /**
     * O escopo do LOG é obtido exclusivamente do servidor e da sessão.
     * Dados enviados pelo navegador ou pelo chamador nunca definem o tenant.
     *
     * @return array{tenant_id:?string,escritorio_id:?int,escopo:string}
     */
    function sgl_log_contexto_multi_tenant(): array
    {
        if (function_exists('rojexModoPlataforma') && rojexModoPlataforma()) {
            return [
                'tenant_id' => null,
                'escritorio_id' => null,
                'escopo' => 'PLATAFORMA',
            ];
        }

        $tenantId = function_exists('rojexTenantId')
            ? trim((string)rojexTenantId())
            : trim((string)($_SESSION['tenant_id'] ?? ''));
        $escritorioId = function_exists('rojexEscritorioId')
            ? (int)rojexEscritorioId()
            : (int)($_SESSION['escritorio_id'] ?? 0);

        $contextoValido = function_exists('rojexContextoTenantValido')
            ? rojexContextoTenantValido()
            : ($tenantId !== '' && $escritorioId > 0);

        if ($contextoValido && $tenantId !== '' && $escritorioId > 0) {
            return [
                'tenant_id' => $tenantId,
                'escritorio_id' => $escritorioId,
                'escopo' => 'TENANT',
            ];
        }

        // Tentativas pré-login e eventos sem escritório identificado pertencem
        // exclusivamente à auditoria da plataforma.
        return [
            'tenant_id' => null,
            'escritorio_id' => null,
            'escopo' => 'PLATAFORMA',
        ];
    }
}

if (!function_exists('sgl_log_normalizar_json')) {
    function sgl_log_normalizar_json(mixed $dados): ?string
    {
        if ($dados === null || $dados === '') {
            return null;
        }

        if (is_string($dados)) {
            return mb_substr($dados, 0, 65000, 'UTF-8');
        }

        $json = json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? null : mb_substr($json, 0, 65000, 'UTF-8');
    }
}

if (!function_exists('sgl_registrar_log')) {
    /**
     * Núcleo oficial de auditoria do ROJEX.AI.
     *
     * Os cinco primeiros parâmetros preservam compatibilidade com chamadas antigas.
     * O sexto parâmetro permite enriquecer o evento sem alterar os módulos existentes.
     */
    function sgl_registrar_log(
        mysqli $conn,
        string $acao,
        ?string $tabela = null,
        ?string $registro_id = null,
        ?string $detalhes = null,
        array $contexto = []
    ): void {
        try {
            sgl_garantir_logs($conn);

            $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $usuario_nome = trim((string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Sistema'));
            $usuario_login = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : null;
            $usuario_perfil = isset($_SESSION['perfil']) ? trim((string)$_SESSION['perfil']) : null;

            $tipo_acao = strtoupper(trim((string)($contexto['tipo_acao'] ?? 'EVENTO')));
            $modulo = trim((string)($contexto['modulo'] ?? ($tabela ?: 'Sistema')));
            $origem = trim((string)($contexto['origem'] ?? 'Aplicação'));
            $resultado = strtoupper(trim((string)($contexto['resultado'] ?? 'SUCESSO')));
            $nivel = strtoupper(trim((string)($contexto['nivel'] ?? 'INFO')));

            $tiposPermitidos = [
                'INCLUSAO', 'EDICAO', 'EXCLUSAO', 'RESTAURACAO',
                'EXCLUSAO_PERMANENTE', 'LOGIN', 'LOGOUT',
                'RECIBO_AUTOMATICO', 'SINCRONIZACAO', 'EVENTO'
            ];
            if (!in_array($tipo_acao, $tiposPermitidos, true)) {
                $tipo_acao = 'EVENTO';
            }

            if (!in_array($resultado, ['SUCESSO', 'FALHA', 'NEGADO', 'PARCIAL'], true)) {
                $resultado = 'SUCESSO';
            }

            if (!in_array($nivel, ['INFO', 'AVISO', 'ERRO', 'CRITICO'], true)) {
                $nivel = 'INFO';
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $sessao_id = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
            $user_agent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');

            $dados_anteriores = sgl_log_normalizar_json($contexto['dados_anteriores'] ?? null);
            $dados_novos = sgl_log_normalizar_json($contexto['dados_novos'] ?? null);
            $contextoMultiTenant = sgl_log_contexto_multi_tenant();
            $tenant_id = $contextoMultiTenant['tenant_id'];
            $escritorio_id = $contextoMultiTenant['escritorio_id'];
            $escopo = $contextoMultiTenant['escopo'];

            $acao = mb_substr(trim($acao), 0, 120, 'UTF-8');
            $tabela = $tabela !== null ? mb_substr(trim($tabela), 0, 80, 'UTF-8') : null;
            $registro_id = $registro_id !== null ? mb_substr(trim($registro_id), 0, 80, 'UTF-8') : null;
            $detalhes = $detalhes !== null ? mb_substr(trim($detalhes), 0, 65000, 'UTF-8') : null;
            $modulo = mb_substr($modulo, 0, 100, 'UTF-8');
            $origem = mb_substr($origem, 0, 80, 'UTF-8');

            $sql = "INSERT INTO logs_sistema (
                        tenant_id, escritorio_id, escopo,
                        usuario_id, usuario_nome, usuario_login, usuario_perfil,
                        acao, tipo_acao, modulo, tabela, registro_id, detalhes,
                        dados_anteriores, dados_novos, origem, resultado, nivel,
                        ip, sessao_id, user_agent
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar o registro de auditoria.');
            }

            $tiposBind = 'sisi' . str_repeat('s', 17);
            $stmt->bind_param(
                $tiposBind,
                $tenant_id,
                $escritorio_id,
                $escopo,
                $usuario_id,
                $usuario_nome,
                $usuario_login,
                $usuario_perfil,
                $acao,
                $tipo_acao,
                $modulo,
                $tabela,
                $registro_id,
                $detalhes,
                $dados_anteriores,
                $dados_novos,
                $origem,
                $resultado,
                $nivel,
                $ip,
                $sessao_id,
                $user_agent
            );

            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error ?: 'Falha ao inserir o registro de auditoria.');
            }

            $stmt->close();
        } catch (Throwable $e) {
            // O LOG nunca pode interromper a operação principal.
            error_log('[ROJEX LOG ENTERPRISE] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sgl_completar_logs_sem_responsavel')) {
    /**
     * Mantida para compatibilidade com chamadas antigas.
     * O backfill foi retirado do caminho HTTP; a migração RA-06 gera o relatório
     * de origem antes de qualquer correção controlada.
     */
    function sgl_completar_logs_sem_responsavel(mysqli $conn): void
    {
        // Intencionalmente sem mutação. Consulte a migração RA-06 central.
    }
}

if (!function_exists('sgl_int_coluna_existe')) {
    function sgl_int_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
    {
        static $cache = [];

        $tabela = trim($tabela);
        $coluna = trim($coluna);
        $cacheKey = sgl_int_cache_key($conn, 'coluna:' . mb_strtolower($tabela . '.' . $coluna, 'UTF-8'));

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $tabela) || !preg_match('/^[A-Za-z0-9_ ]+$/', $coluna)) {
            $cache[$cacheKey] = false;
            return false;
        }

        try {
            $tabelaSql = str_replace('`', '``', $tabela);
            $stmt = $conn->prepare("SHOW COLUMNS FROM `{$tabelaSql}` LIKE ?");
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar consulta de coluna.');
            }

            $stmt->bind_param('s', $coluna);

            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error ?: 'Falha ao consultar coluna.');
            }

            $res = $stmt->get_result();
            $cache[$cacheKey] = $res && $res->num_rows > 0;
            $stmt->close();

            return $cache[$cacheKey];
        } catch (Throwable $e) {
            sgl_int_log_erro('COLUNA_EXISTE', $e);
            $cache[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('sgl_int_add_coluna')) {
    function sgl_int_add_coluna(mysqli $conn, string $tabela, string $coluna, string $definicao): void
    {
        if (sgl_int_coluna_existe($conn, $tabela, $coluna)) {
            return;
        }

        // Compatibilidade com chamadas antigas: apenas detecta estrutura ausente.
        // DDL é proibido no caminho HTTP e deve ser aplicado por migração.
        sgl_int_log_erro(
            'MIGRACAO_PENDENTE',
            "Estrutura ausente: {$tabela}.{$coluna}. Execute a migração RA-06 central."
        );
    }
}

if (!function_exists('sgl_int_contexto_multi_tenant')) {
    /**
     * Retorna exclusivamente o contexto operacional autenticado.
     * Integrações financeiras nunca podem operar no modo Plataforma ou sem
     * tenant_id + escritorio_id válidos.
     *
     * @return array{tenant_id:string,escritorio_id:int}
     */
    function sgl_int_contexto_multi_tenant(): array
    {
        if (function_exists('rojexExigirContextoTenant')) {
            rojexExigirContextoTenant();
        }

        if (function_exists('rojexModoPlataforma') && rojexModoPlataforma()) {
            throw new RuntimeException('Integração bloqueada no Modo Plataforma.');
        }

        $tenantId = function_exists('rojexTenantId')
            ? trim((string)rojexTenantId())
            : trim((string)($_SESSION['tenant_id'] ?? ''));
        $escritorioId = function_exists('rojexEscritorioId')
            ? (int)rojexEscritorioId()
            : (int)($_SESSION['escritorio_id'] ?? 0);

        if ($tenantId === '' || $escritorioId <= 0) {
            throw new RuntimeException(
                'Integração bloqueada: contexto Multi-Tenant incompleto.'
            );
        }

        return [
            'tenant_id' => $tenantId,
            'escritorio_id' => $escritorioId,
        ];
    }
}

if (!function_exists('sgl_integracao_garantir_financeiro')) {
    function sgl_integracao_garantir_financeiro(mysqli $conn): void
    {
        static $garantido = [];
        $cacheKey = sgl_int_cache_key($conn, 'financeiro');

        if (!empty($garantido[$cacheKey])) {
            return;
        }

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

        $garantido[$cacheKey] = true;
    }
}

if (!function_exists('sgl_integracao_garantir_recibos')) {
    function sgl_integracao_garantir_recibos(mysqli $conn): void
    {
        static $garantido = [];
        $cacheKey = sgl_int_cache_key($conn, 'recibos');

        if (!empty($garantido[$cacheKey])) {
            return;
        }

        try {
            sgl_int_add_coluna($conn, 'recibos', 'tenant_id', "tenant_id VARCHAR(80) NULL AFTER id");
            sgl_int_add_coluna($conn, 'recibos', 'escritorio_id', "escritorio_id BIGINT NULL AFTER tenant_id");
            sgl_int_add_coluna($conn, 'recibos', 'conta_receber_id', "conta_receber_id VARCHAR(20) NULL");
            $garantido[$cacheKey] = true;
        } catch (Throwable $e) {
            sgl_int_log_erro('GARANTIR_RECIBOS', $e);
        }
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
    /**
     * Sincroniza Honorários -> Contas a Receber -> Recibos.
     *
     * O retorno estruturado preserva compatibilidade: chamadas antigas podem
     * continuar ignorando o resultado.
     *
     * @return array{
     *   ok: bool,
     *   resultado: string,
     *   honorario_id: string,
     *   parcelas_processadas: int,
     *   contas_criadas: int,
     *   contas_atualizadas: int,
     *   contas_restauradas: int,
     *   recibos_gerados: int,
     *   falhas: array<int,string>
     * }
     */
    function sgl_sincronizar_honorario_financeiro(mysqli $conn, string $honorario_id): array
    {
        $retorno = [
            'ok' => false,
            'resultado' => 'FALHA',
            'honorario_id' => $honorario_id,
            'parcelas_processadas' => 0,
            'contas_criadas' => 0,
            'contas_atualizadas' => 0,
            'contas_restauradas' => 0,
            'recibos_gerados' => 0,
            'falhas' => [],
        ];

        try {
            sgl_integracao_garantir_financeiro($conn);
            $contextoTenant = sgl_int_contexto_multi_tenant();
            $tenantId = $contextoTenant['tenant_id'];
            $escritorioId = $contextoTenant['escritorio_id'];

            $stmtHonorario = $conn->prepare(
                "SELECT id, cliente_id, nome_cliente, forma_pagamento, observacoes
                 FROM honorarios
                 WHERE id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                 LIMIT 1"
            );
            if (!$stmtHonorario) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar honorário.');
            }

            $stmtHonorario->bind_param('ssi', $honorario_id, $tenantId, $escritorioId);
            if (!$stmtHonorario->execute()) {
                throw new RuntimeException($stmtHonorario->error ?: 'Falha ao consultar honorário.');
            }

            $resHonorario = $stmtHonorario->get_result();
            $h = $resHonorario ? $resHonorario->fetch_assoc() : null;
            $stmtHonorario->close();

            if (!$h) {
                $retorno['falhas'][] = 'Honorário não encontrado.';
                return $retorno;
            }

            $temDataPagamento = sgl_int_coluna_existe($conn, 'honorarios_parcelas', 'data_pagamento');

            $stmtParcelas = $conn->prepare(
                "SELECT
                    id, cliente_id, nome_cliente, numero_processo, parcela_numero,
                    valor_parcela, valor_pago, saldo_devedor, status_pagamento,
                    data_vencimento, forma_pagamento, observacoes"
                    . ($temDataPagamento ? ", data_pagamento" : "") . "
                 FROM honorarios_parcelas
                 WHERE honorario_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                 ORDER BY parcela_numero ASC"
            );
            if (!$stmtParcelas) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar parcelas.');
            }

            $stmtParcelas->bind_param('ssi', $honorario_id, $tenantId, $escritorioId);
            if (!$stmtParcelas->execute()) {
                throw new RuntimeException($stmtParcelas->error ?: 'Falha ao consultar parcelas.');
            }

            $resParcelas = $stmtParcelas->get_result();

            while ($p = $resParcelas->fetch_assoc()) {
                $retorno['parcelas_processadas']++;

                try {
                    $parcelaId = (string)$p['id'];
                    $valor = round((float)($p['valor_parcela'] ?? 0), 2);
                    $valorPago = round((float)($p['valor_pago'] ?? 0), 2);
                    $saldo = round((float)($p['saldo_devedor'] ?? max(0, $valor - $valorPago)), 2);
                    $statusParcela = (string)($p['status_pagamento'] ?? 'Pendente');

                    $statusCR = match ($statusParcela) {
                        'Pago' => 'Recebido',
                        'Parcial' => 'Parcial',
                        default => 'Pendente',
                    };

                    $dataRecebimento = null;
                    if ($statusCR === 'Recebido') {
                        $dataRecebimento = $temDataPagamento && !empty($p['data_pagamento'])
                            ? (string)$p['data_pagamento']
                            : date('Y-m-d');
                    }

                    $nomeCliente = trim((string)($p['nome_cliente'] ?? ''))
                        ?: trim((string)($h['nome_cliente'] ?? ''))
                        ?: 'Cliente';

                    $descricao = 'Honorários - ' . $nomeCliente . ' - Parcela ' . (int)$p['parcela_numero'];
                    if (!empty($p['numero_processo'])) {
                        $descricao .= ' - Proc. ' . $p['numero_processo'];
                    }

                    $obs = (string)($p['observacoes'] ?? ($h['observacoes'] ?? ''));
                    $clienteId = $p['cliente_id'] ?? ($h['cliente_id'] ?? null);
                    $forma = (string)($p['forma_pagamento'] ?? ($h['forma_pagamento'] ?? ''));
                    $dataVenc = $p['data_vencimento'] ?? null;

                    $stmtExiste = $conn->prepare(
                        "SELECT id, deletado
                         FROM contas_receber
                         WHERE honorario_id = ?
                           AND parcela_id = ?
                           AND tenant_id = ?
                           AND escritorio_id = ?
                         LIMIT 1"
                    );
                    if (!$stmtExiste) {
                        throw new RuntimeException($conn->error ?: 'Falha ao preparar busca da conta.');
                    }

                    $stmtExiste->bind_param(
                        'sssi',
                        $honorario_id,
                        $parcelaId,
                        $tenantId,
                        $escritorioId
                    );
                    if (!$stmtExiste->execute()) {
                        throw new RuntimeException($stmtExiste->error ?: 'Falha ao localizar conta vinculada.');
                    }

                    $resExiste = $stmtExiste->get_result();
                    $existente = $resExiste ? $resExiste->fetch_assoc() : null;
                    $stmtExiste->close();

                    if ($existente) {
                        $crId = (string)$existente['id'];
                        $estavaDeletado = (int)($existente['deletado'] ?? 0) === 1;

                        $stmt = $conn->prepare(
                            "UPDATE contas_receber SET
                                cliente_id = ?,
                                descricao = ?,
                                valor = ?,
                                valor_parcela = ?,
                                valor_pago = ?,
                                valor_pendente = ?,
                                data_vencimento = ?,
                                data_recebimento = ?,
                                forma_recebimento = ?,
                                status = ?,
                                observacoes = ?,
                                origem = 'honorarios',
                                deletado = 0
                             WHERE id = ?
                               AND tenant_id = ?
                               AND escritorio_id = ?"
                        );
                        if (!$stmt) {
                            throw new RuntimeException($conn->error ?: 'Falha ao preparar atualização financeira.');
                        }

                        $stmt->bind_param(
                            'ssddddsssssssi',
                            $clienteId,
                            $descricao,
                            $valor,
                            $valor,
                            $valorPago,
                            $saldo,
                            $dataVenc,
                            $dataRecebimento,
                            $forma,
                            $statusCR,
                            $obs,
                            $crId,
                            $tenantId,
                            $escritorioId
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException($stmt->error ?: 'Falha ao atualizar conta a receber.');
                        }

                        $stmt->close();
                        $retorno['contas_atualizadas']++;

                        if ($estavaDeletado) {
                            $retorno['contas_restauradas']++;
                        }
                    } else {
                        $crId = '';
                        $inserido = false;

                        for ($tentativa = 1; $tentativa <= 3 && !$inserido; $tentativa++) {
                            $crId = sgl_integracao_gerar_id_cr($conn);

                            try {
                                $stmt = $conn->prepare(
                                    "INSERT INTO contas_receber (
                                        id, tenant_id, escritorio_id,
                                        cliente_id, descricao, valor, qtd_parcelas,
                                        valor_parcela, valor_pago, valor_pendente,
                                        data_vencimento, data_recebimento, forma_recebimento,
                                        status, observacoes, deletado, honorario_id,
                                        parcela_id, origem
                                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'honorarios')"
                                );
                                if (!$stmt) {
                                    throw new RuntimeException($conn->error ?: 'Falha ao preparar criação financeira.');
                                }

                                $stmt->bind_param(
                                    'ssissddddsssssss',
                                    $crId,
                                    $tenantId,
                                    $escritorioId,
                                    $clienteId,
                                    $descricao,
                                    $valor,
                                    $valor,
                                    $valorPago,
                                    $saldo,
                                    $dataVenc,
                                    $dataRecebimento,
                                    $forma,
                                    $statusCR,
                                    $obs,
                                    $honorario_id,
                                    $parcelaId
                                );

                                if (!$stmt->execute()) {
                                    $erro = new mysqli_sql_exception(
                                        $stmt->error ?: 'Falha ao criar conta a receber.',
                                        $stmt->errno
                                    );
                                    $stmt->close();
                                    throw $erro;
                                }

                                $stmt->close();
                                $inserido = true;
                                $retorno['contas_criadas']++;
                            } catch (Throwable $e) {
                                if ($tentativa < 3 && sgl_int_eh_colisao_chave($e)) {
                                    usleep(20000 * $tentativa);
                                    continue;
                                }
                                throw $e;
                            }
                        }

                        if (!$inserido) {
                            throw new RuntimeException('Não foi possível gerar uma conta a receber única.');
                        }
                    }

                    if ($statusCR === 'Recebido') {
                        $reciboId = sgl_gerar_recibo_de_conta_receber($conn, $crId);
                        if ($reciboId !== null) {
                            $retorno['recibos_gerados']++;
                        }
                    }
                } catch (Throwable $e) {
                    $retorno['falhas'][] = 'Parcela ' . ((string)($p['id'] ?? '?')) . ': ' . $e->getMessage();
                    sgl_int_log_erro('SINCRONIZAR_PARCELA', $e);
                }
            }

            $stmtParcelas->close();

            $retorno['ok'] = empty($retorno['falhas']);
            $retorno['resultado'] = $retorno['ok'] ? 'SUCESSO' : 'PARCIAL';

            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Sincronização de honorário com o Financeiro',
                    'honorarios',
                    $honorario_id,
                    'Sincronização de parcelas, contas a receber e recibos.',
                    [
                        'tipo_acao' => 'SINCRONIZACAO',
                        'modulo' => 'Honorários / Financeiro',
                        'origem' => 'Integração interna',
                        'resultado' => $retorno['resultado'],
                        'nivel' => $retorno['ok'] ? 'INFO' : 'AVISO',
                        'dados_novos' => $retorno,
                    ]
                );
            }

            return $retorno;
        } catch (Throwable $e) {
            $retorno['falhas'][] = $e->getMessage();
            sgl_int_log_erro('SINCRONIZAR_HONORARIO', $e);

            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Falha na sincronização de honorário com o Financeiro',
                    'honorarios',
                    $honorario_id,
                    'A sincronização não foi concluída.',
                    [
                        'tipo_acao' => 'SINCRONIZACAO',
                        'modulo' => 'Honorários / Financeiro',
                        'origem' => 'Integração interna',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_novos' => $retorno,
                    ]
                );
            }

            return $retorno;
        }
    }
}


if (!function_exists('sgl_sincronizar_conta_receber_honorario')) {
    /**
     * Sincroniza uma Conta a Receber vinculada de volta para sua parcela
     * e para o total global do Honorário.
     *
     * Contas independentes do Financeiro são ignoradas com sucesso, pois
     * nem toda receita representa honorários advocatícios.
     *
     * @return array{
     *   ok: bool,
     *   resultado: string,
     *   vinculado: bool,
     *   conta_receber_id: string,
     *   honorario_id: ?string,
     *   parcela_id: ?string,
     *   parcela_atualizada: bool,
     *   honorario_recalculado: bool,
     *   mensagem: string,
     *   falhas: array<int,string>
     * }
     */
    function sgl_sincronizar_conta_receber_honorario(
        mysqli $conn,
        string $conta_receber_id,
        bool $usarTransacao = true
    ): array {
        $retorno = [
            'ok' => false,
            'resultado' => 'FALHA',
            'vinculado' => false,
            'conta_receber_id' => $conta_receber_id,
            'honorario_id' => null,
            'parcela_id' => null,
            'parcela_atualizada' => false,
            'honorario_recalculado' => false,
            'mensagem' => '',
            'falhas' => [],
        ];

        $transacaoIniciada = false;

        try {
            sgl_integracao_garantir_financeiro($conn);
            $contextoTenant = sgl_int_contexto_multi_tenant();
            $tenantId = $contextoTenant['tenant_id'];
            $escritorioId = $contextoTenant['escritorio_id'];

            if ($usarTransacao) {
                $conn->begin_transaction();
                $transacaoIniciada = true;
            }

            $stmtConta = $conn->prepare(
                "SELECT
                    id, origem, honorario_id, parcela_id, valor, valor_parcela,
                    valor_pago, valor_pendente, status, data_recebimento,
                    forma_recebimento, deletado
                 FROM contas_receber
                 WHERE id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$stmtConta) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar a conta a receber.');
            }

            $stmtConta->bind_param('ssi', $conta_receber_id, $tenantId, $escritorioId);

            if (!$stmtConta->execute()) {
                throw new RuntimeException($stmtConta->error ?: 'Falha ao consultar a conta a receber.');
            }

            $resConta = $stmtConta->get_result();
            $conta = $resConta ? $resConta->fetch_assoc() : null;
            $stmtConta->close();

            if (!$conta) {
                throw new RuntimeException('Conta a receber não encontrada.');
            }

            $origem = mb_strtolower(trim((string)($conta['origem'] ?? '')), 'UTF-8');
            $honorarioId = trim((string)($conta['honorario_id'] ?? ''));
            $parcelaId = trim((string)($conta['parcela_id'] ?? ''));

            $retorno['honorario_id'] = $honorarioId !== '' ? $honorarioId : null;
            $retorno['parcela_id'] = $parcelaId !== '' ? $parcelaId : null;

            if (
                $origem !== 'honorarios'
                || $honorarioId === ''
                || $parcelaId === ''
            ) {
                if ($transacaoIniciada) {
                    $conn->commit();
                    $transacaoIniciada = false;
                }

                $retorno['ok'] = true;
                $retorno['resultado'] = 'IGNORADO';
                $retorno['mensagem'] = 'Conta independente do módulo de Honorários.';
                return $retorno;
            }

            $retorno['vinculado'] = true;

            if ((int)($conta['deletado'] ?? 0) === 1) {
                throw new RuntimeException('Conta vinculada está na lixeira.');
            }

            $statusConta = trim((string)($conta['status'] ?? 'Pendente'));

            if ($statusConta === 'Cancelado') {
                if ($transacaoIniciada) {
                    $conn->commit();
                    $transacaoIniciada = false;
                }

                $retorno['ok'] = true;
                $retorno['resultado'] = 'IGNORADO';
                $retorno['mensagem'] = 'Conta cancelada não altera automaticamente o Honorário.';
                return $retorno;
            }

            $stmtParcela = $conn->prepare(
                "SELECT
                    id, honorario_id, valor_parcela, valor_pago,
                    saldo_devedor, status_pagamento, forma_pagamento
                 FROM honorarios_parcelas
                 WHERE id = ?
                   AND honorario_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$stmtParcela) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar a parcela de honorário.');
            }

            $stmtParcela->bind_param(
                'sssi',
                $parcelaId,
                $honorarioId,
                $tenantId,
                $escritorioId
            );

            if (!$stmtParcela->execute()) {
                throw new RuntimeException($stmtParcela->error ?: 'Falha ao consultar a parcela de honorário.');
            }

            $resParcela = $stmtParcela->get_result();
            $parcela = $resParcela ? $resParcela->fetch_assoc() : null;
            $stmtParcela->close();

            if (!$parcela) {
                throw new RuntimeException('Parcela vinculada ao honorário não foi encontrada.');
            }

            $valorParcela = round((float)($parcela['valor_parcela'] ?? 0), 2);
            if ($valorParcela <= 0) {
                throw new RuntimeException('A parcela vinculada possui valor inválido.');
            }

            $valorPago = round((float)($conta['valor_pago'] ?? 0), 2);
            $valorPago = max(0.0, min($valorParcela, $valorPago));
            $saldo = round(max(0.0, $valorParcela - $valorPago), 2);

            $statusParcela = 'Pendente';
            if ($saldo <= 0.01 && $valorPago > 0) {
                $statusParcela = 'Pago';
                $saldo = 0.0;
            } elseif ($valorPago > 0) {
                $statusParcela = 'Parcial';
            }

            $formaRecebimento = trim((string)($conta['forma_recebimento'] ?? ''));
            $dataRecebimento = !empty($conta['data_recebimento'])
                ? (string)$conta['data_recebimento']
                : null;

            $temDataPagamento = sgl_int_coluna_existe(
                $conn,
                'honorarios_parcelas',
                'data_pagamento'
            );

            if ($temDataPagamento) {
                $stmtAtualizaParcela = $conn->prepare(
                    "UPDATE honorarios_parcelas
                     SET valor_pago = ?,
                         saldo_devedor = ?,
                         status_pagamento = ?,
                         data_pagamento = ?,
                         forma_pagamento = ?
                     WHERE id = ?
                       AND honorario_id = ?
                       AND tenant_id = ?
                       AND escritorio_id = ?"
                );

                if (!$stmtAtualizaParcela) {
                    throw new RuntimeException($conn->error ?: 'Falha ao preparar atualização da parcela.');
                }

                $dataPagamento = $valorPago > 0
                    ? ($dataRecebimento ?: date('Y-m-d'))
                    : null;

                $stmtAtualizaParcela->bind_param(
                    'ddssssssi',
                    $valorPago,
                    $saldo,
                    $statusParcela,
                    $dataPagamento,
                    $formaRecebimento,
                    $parcelaId,
                    $honorarioId,
                    $tenantId,
                    $escritorioId
                );
            } else {
                $stmtAtualizaParcela = $conn->prepare(
                    "UPDATE honorarios_parcelas
                     SET valor_pago = ?,
                         saldo_devedor = ?,
                         status_pagamento = ?,
                         forma_pagamento = ?
                     WHERE id = ?
                       AND honorario_id = ?
                       AND tenant_id = ?
                       AND escritorio_id = ?"
                );

                if (!$stmtAtualizaParcela) {
                    throw new RuntimeException($conn->error ?: 'Falha ao preparar atualização da parcela.');
                }

                $stmtAtualizaParcela->bind_param(
                    'ddsssssi',
                    $valorPago,
                    $saldo,
                    $statusParcela,
                    $formaRecebimento,
                    $parcelaId,
                    $honorarioId,
                    $tenantId,
                    $escritorioId
                );
            }

            if (!$stmtAtualizaParcela->execute()) {
                throw new RuntimeException(
                    $stmtAtualizaParcela->error ?: 'Falha ao atualizar a parcela.'
                );
            }

            $stmtAtualizaParcela->close();
            $retorno['parcela_atualizada'] = true;

            $stmtTotais = $conn->prepare(
                "SELECT
                    COALESCE(SUM(valor_pago), 0) AS total_pago,
                    COALESCE(SUM(saldo_devedor), 0) AS total_saldo
                 FROM honorarios_parcelas
                 WHERE honorario_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?"
            );

            if (!$stmtTotais) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar o recálculo do honorário.');
            }

            $stmtTotais->bind_param('ssi', $honorarioId, $tenantId, $escritorioId);

            if (!$stmtTotais->execute()) {
                throw new RuntimeException($stmtTotais->error ?: 'Falha ao recalcular o honorário.');
            }

            $resTotais = $stmtTotais->get_result();
            $totais = $resTotais ? $resTotais->fetch_assoc() : null;
            $stmtTotais->close();

            if (!$totais) {
                throw new RuntimeException('Totais do honorário não foram encontrados.');
            }

            $totalPago = round((float)$totais['total_pago'], 2);
            $totalSaldo = round((float)$totais['total_saldo'], 2);

            $statusHonorario = 'Pendente';
            if ($totalSaldo <= 0.01) {
                $statusHonorario = 'Pago';
                $totalSaldo = 0.0;
            } elseif ($totalPago > 0) {
                $statusHonorario = 'Parcial';
            }

            $stmtHonorario = $conn->prepare(
                "UPDATE honorarios
                 SET valor_pago = ?,
                     valor_pendente = ?,
                     status = ?,
                     forma_pagamento = ?
                 WHERE id = ?
                   AND deletado = 0
                   AND tenant_id = ?
                   AND escritorio_id = ?"
            );

            if (!$stmtHonorario) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar atualização do honorário.');
            }

            $stmtHonorario->bind_param(
                'ddssssi',
                $totalPago,
                $totalSaldo,
                $statusHonorario,
                $formaRecebimento,
                $honorarioId,
                $tenantId,
                $escritorioId
            );

            if (!$stmtHonorario->execute()) {
                throw new RuntimeException($stmtHonorario->error ?: 'Falha ao atualizar o honorário.');
            }

            $stmtHonorario->close();
            $retorno['honorario_recalculado'] = true;

            if ($transacaoIniciada) {
                $conn->commit();
                $transacaoIniciada = false;
            }

            $retorno['ok'] = true;
            $retorno['resultado'] = 'SUCESSO';
            $retorno['mensagem'] = 'Conta, parcela e honorário sincronizados.';

            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Conta a receber sincronizada com Honorários',
                    'contas_receber',
                    $conta_receber_id,
                    'Pagamento financeiro refletido na parcela e no honorário global.',
                    [
                        'tipo_acao' => 'SINCRONIZACAO',
                        'modulo' => 'Financeiro / Honorários',
                        'origem' => 'Sincronização reversa',
                        'resultado' => 'SUCESSO',
                        'nivel' => 'INFO',
                        'dados_anteriores' => [
                            'parcela_id' => $parcelaId,
                            'honorario_id' => $honorarioId,
                            'valor_pago' => (float)($parcela['valor_pago'] ?? 0),
                            'saldo_devedor' => (float)($parcela['saldo_devedor'] ?? 0),
                            'status_pagamento' => (string)($parcela['status_pagamento'] ?? ''),
                            'forma_pagamento' => (string)($parcela['forma_pagamento'] ?? ''),
                        ],
                        'dados_novos' => [
                            'conta_receber_id' => $conta_receber_id,
                            'parcela_id' => $parcelaId,
                            'honorario_id' => $honorarioId,
                            'valor_pago' => $valorPago,
                            'saldo_devedor' => $saldo,
                            'status_pagamento' => $statusParcela,
                            'forma_pagamento' => $formaRecebimento,
                            'honorario_valor_pago' => $totalPago,
                            'honorario_valor_pendente' => $totalSaldo,
                            'honorario_status' => $statusHonorario,
                        ],
                    ]
                );
            }

            return $retorno;
        } catch (Throwable $e) {
            if ($transacaoIniciada) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackErro) {
                    sgl_int_log_erro('SINCRONIZAR_CR_HONORARIO_ROLLBACK', $rollbackErro);
                }
            }

            $retorno['falhas'][] = $e->getMessage();
            $retorno['mensagem'] = 'A sincronização reversa não foi concluída.';
            sgl_int_log_erro('SINCRONIZAR_CR_HONORARIO', $e);

            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Falha ao sincronizar Conta a Receber com Honorários',
                    'contas_receber',
                    $conta_receber_id,
                    'A parcela e o honorário não foram atualizados.',
                    [
                        'tipo_acao' => 'SINCRONIZACAO',
                        'modulo' => 'Financeiro / Honorários',
                        'origem' => 'Sincronização reversa',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                        'dados_novos' => $retorno,
                    ]
                );
            }

            return $retorno;
        }
    }
}

if (!function_exists('sgl_gerar_recibo_de_conta_receber')) {
    function sgl_gerar_recibo_de_conta_receber(mysqli $conn, string $conta_receber_id): ?string
    {
        try {
            sgl_integracao_garantir_recibos($conn);
            $contextoTenant = sgl_int_contexto_multi_tenant();
            $tenantId = $contextoTenant['tenant_id'];
            $escritorioId = $contextoTenant['escritorio_id'];

            $stmtJa = $conn->prepare(
                "SELECT id
                 FROM recibos
                 WHERE conta_receber_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                   AND status <> 'Cancelado'
                   AND deletado = 0
                 ORDER BY data_emissao DESC, id DESC
                 LIMIT 1"
            );
            if (!$stmtJa) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar busca de recibo.');
            }

            $stmtJa->bind_param('ssi', $conta_receber_id, $tenantId, $escritorioId);
            if (!$stmtJa->execute()) {
                throw new RuntimeException($stmtJa->error ?: 'Falha ao buscar recibo.');
            }

            $resJa = $stmtJa->get_result();
            $existente = $resJa ? $resJa->fetch_assoc() : null;
            $stmtJa->close();

            if ($existente) {
                return (string)$existente['id'];
            }

            $stmtConta = $conn->prepare(
                "SELECT
                    cr.id, cr.cliente_id, cr.descricao, cr.valor, cr.valor_pago,
                    cr.data_recebimento, cr.forma_recebimento, cr.honorario_id,
                    cr.parcela_id, c.nome AS cliente_nome, c.cpf_cnpj
                 FROM contas_receber cr
                 LEFT JOIN clientes c ON c.id = cr.cliente_id
                    AND c.tenant_id = cr.tenant_id
                    AND c.escritorio_id = cr.escritorio_id
                 WHERE cr.id = ?
                   AND cr.tenant_id = ?
                   AND cr.escritorio_id = ?
                 LIMIT 1"
            );
            if (!$stmtConta) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar conta para recibo.');
            }

            $stmtConta->bind_param('ssi', $conta_receber_id, $tenantId, $escritorioId);
            if (!$stmtConta->execute()) {
                throw new RuntimeException($stmtConta->error ?: 'Falha ao consultar conta para recibo.');
            }

            $resConta = $stmtConta->get_result();
            $cr = $resConta ? $resConta->fetch_assoc() : null;
            $stmtConta->close();

            if (!$cr) {
                return null;
            }

            $nomeCliente = trim((string)($cr['cliente_nome'] ?? '')) ?: 'Cliente não informado';
            $cpfCnpj = (string)($cr['cpf_cnpj'] ?? '');
            $valor = (float)($cr['valor_pago'] ?? 0);
            if ($valor <= 0) {
                $valor = (float)($cr['valor'] ?? 0);
            }
            if ($valor <= 0) {
                return null;
            }

            $dataHoje = date('Y-m-d');
            $dataPagamento = !empty($cr['data_recebimento'])
                ? (string)$cr['data_recebimento']
                : $dataHoje;
            $referente = trim((string)($cr['descricao'] ?? '')) ?: 'Recebimento de honorários';
            $forma = (string)($cr['forma_recebimento'] ?? '');
            $honorarioId = $cr['honorario_id'] ?? null;
            $parcelaId = $cr['parcela_id'] ?? null;
            $clienteId = $cr['cliente_id'] ?? null;
            $obs = 'Recibo gerado automaticamente pelo Centro Financeiro.';

            for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
                $id = sgl_integracao_gerar_id_recibo($conn);
                $numero = sgl_integracao_gerar_numero_recibo($conn);
                $chave = hash('sha256', $numero . $nomeCliente . microtime(true) . random_int(1000, 999999));

                try {
                    $stmt = $conn->prepare(
                        "INSERT INTO recibos (
                            id, tenant_id, escritorio_id,
                            numero, cliente_id, nome_cliente, cpf_cnpj,
                            honorario_id, parcela_id, conta_receber_id,
                            data_emissao, data_pagamento, referente,
                            forma_pagamento, valor, observacoes, chave_validacao
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if (!$stmt) {
                        throw new RuntimeException($conn->error ?: 'Falha ao preparar criação de recibo.');
                    }

                    $stmt->bind_param(
                        'ssisssssssssssdss',
                        $id,
                        $tenantId,
                        $escritorioId,
                        $numero,
                        $clienteId,
                        $nomeCliente,
                        $cpfCnpj,
                        $honorarioId,
                        $parcelaId,
                        $conta_receber_id,
                        $dataHoje,
                        $dataPagamento,
                        $referente,
                        $forma,
                        $valor,
                        $obs,
                        $chave
                    );

                    if (!$stmt->execute()) {
                        $erro = new mysqli_sql_exception(
                            $stmt->error ?: 'Falha ao inserir recibo.',
                            $stmt->errno
                        );
                        $stmt->close();
                        throw $erro;
                    }

                    $stmt->close();

                    if (function_exists('sgl_registrar_log')) {
                        sgl_registrar_log(
                            $conn,
                            'Gerou recibo automático',
                            'recibos',
                            $id,
                            'Conta a receber vinculada: ' . $conta_receber_id,
                            [
                                'tipo_acao' => 'RECIBO_AUTOMATICO',
                                'modulo' => 'Financeiro / Recibos',
                                'origem' => 'Integração interna',
                                'resultado' => 'SUCESSO',
                                'nivel' => 'INFO',
                                'dados_novos' => [
                                    'recibo_id' => $id,
                                    'numero' => $numero,
                                    'conta_receber_id' => $conta_receber_id,
                                    'valor' => $valor,
                                    'data_pagamento' => $dataPagamento,
                                ],
                            ]
                        );
                    }

                    return $id;
                } catch (Throwable $e) {
                    if ($tentativa < 3 && sgl_int_eh_colisao_chave($e)) {
                        usleep(20000 * $tentativa);
                        continue;
                    }

                    throw $e;
                }
            }

            return null;
        } catch (Throwable $e) {
            sgl_int_log_erro('GERAR_RECIBO', $e);

            if (function_exists('sgl_registrar_log')) {
                sgl_registrar_log(
                    $conn,
                    'Falha ao gerar recibo automático',
                    'contas_receber',
                    $conta_receber_id,
                    'O recibo automático não foi criado.',
                    [
                        'tipo_acao' => 'RECIBO_AUTOMATICO',
                        'modulo' => 'Financeiro / Recibos',
                        'origem' => 'Integração interna',
                        'resultado' => 'FALHA',
                        'nivel' => 'ERRO',
                    ]
                );
            }

            return null;
        }
    }
}

if (!function_exists('buscarReciboPorContaReceber')) {
    /**
     * Retorna o recibo ativo vinculado a uma conta a receber.
     * Mantida com este nome para compatibilidade com financeiro.php.
     */
    function buscarReciboPorContaReceber(mysqli $conn, string $conta_receber_id): ?array
    {
        try {
            sgl_integracao_garantir_recibos($conn);
            $contextoTenant = sgl_int_contexto_multi_tenant();
            $tenantId = $contextoTenant['tenant_id'];
            $escritorioId = $contextoTenant['escritorio_id'];

            $stmt = $conn->prepare(
                "SELECT *
                 FROM recibos
                 WHERE conta_receber_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?
                   AND deletado = 0
                   AND status <> 'Cancelado'
                 ORDER BY data_emissao DESC, id DESC
                 LIMIT 1"
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar busca de recibo.');
            }

            $stmt->bind_param('ssi', $conta_receber_id, $tenantId, $escritorioId);
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error ?: 'Falha ao buscar recibo.');
            }

            $res = $stmt->get_result();
            $recibo = $res && $res->num_rows > 0 ? $res->fetch_assoc() : null;
            $stmt->close();

            return $recibo;
        } catch (Throwable $e) {
            sgl_int_log_erro('BUSCAR_RECIBO', $e);
            return null;
        }
    }
}

if (!function_exists('sgl_buscar_recibo_por_conta_receber')) {
    function sgl_buscar_recibo_por_conta_receber(mysqli $conn, string $conta_receber_id): ?array
    {
        return buscarReciboPorContaReceber($conn, $conta_receber_id);
    }
}

if (!function_exists('marcarContaPagarPaga')) {
    /**
     * Informa se a sessão MySQL/MariaDB já está dentro de uma transação.
     *
     * mysqli não expõe uma propriedade pública e portável para esse estado.
     * A variável de sessão In_transaction é consultada diretamente no servidor,
     * mantendo compatibilidade com MySQL/MariaDB e PHP 8+.
     */
    function sgl_int_transacao_ativa(mysqli $conn): bool
    {
        try {
            $resultado = $conn->query("SHOW SESSION STATUS LIKE 'In_transaction'");
            if (!$resultado) {
                return false;
            }

            $linha = $resultado->fetch_assoc();
            $resultado->free();

            return strtoupper((string)($linha['Value'] ?? 'OFF')) === 'ON';
        } catch (Throwable $e) {
            sgl_int_log_erro('VERIFICAR_TRANSACAO', $e);
            return false;
        }
    }

    /**
     * Marca uma conta a pagar como paga, atualizando também suas parcelas quando existirem.
     */
    function marcarContaPagarPaga(mysqli $conn, string $conta_id, ?string $data_pagamento = null): bool
    {
        $dataPagamento = $data_pagamento ?: date('Y-m-d');
        $transacaoIniciadaAqui = false;

        try {
            $contextoTenant = sgl_int_contexto_multi_tenant();
            $tenantId = $contextoTenant['tenant_id'];
            $escritorioId = $contextoTenant['escritorio_id'];

            if (!sgl_int_transacao_ativa($conn)) {
                $conn->begin_transaction();
                $transacaoIniciadaAqui = true;
            }

            $stmtConta = $conn->prepare(
                "SELECT valor
                 FROM contas_pagar
                 WHERE id = ?
                   AND deletado = 0
                   AND tenant_id = ?
                   AND escritorio_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$stmtConta) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar conta a pagar.');
            }

            $stmtConta->bind_param('ssi', $conta_id, $tenantId, $escritorioId);
            if (!$stmtConta->execute()) {
                throw new RuntimeException($stmtConta->error ?: 'Falha ao consultar conta a pagar.');
            }

            $res = $stmtConta->get_result();
            $conta = $res ? $res->fetch_assoc() : null;
            $stmtConta->close();

            if (!$conta) {
                if ($transacaoIniciadaAqui) {
                    $conn->rollback();
                }
                return false;
            }

            $valor = round((float)($conta['valor'] ?? 0), 2);

            $stmtAtualiza = $conn->prepare(
                "UPDATE contas_pagar
                 SET valor_pago = ?,
                     valor_pendente = 0,
                     status = 'Pago',
                     data_pagamento = ?
                 WHERE id = ?
                   AND deletado = 0
                   AND tenant_id = ?
                   AND escritorio_id = ?"
            );
            if (!$stmtAtualiza) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar pagamento da conta.');
            }

            $stmtAtualiza->bind_param(
                'dsssi',
                $valor,
                $dataPagamento,
                $conta_id,
                $tenantId,
                $escritorioId
            );
            if (!$stmtAtualiza->execute()) {
                throw new RuntimeException($stmtAtualiza->error ?: 'Falha ao marcar conta como paga.');
            }
            $stmtAtualiza->close();

            $stmtParcelas = $conn->prepare(
                "UPDATE contas_pagar_parcelas
                 SET valor_pago = valor_parcela,
                     saldo_devedor = 0,
                     status_pagamento = 'Pago'
                 WHERE conta_id = ?
                   AND tenant_id = ?
                   AND escritorio_id = ?"
            );
            if (!$stmtParcelas) {
                throw new RuntimeException($conn->error ?: 'Falha ao preparar parcelas da conta.');
            }

            $stmtParcelas->bind_param('ssi', $conta_id, $tenantId, $escritorioId);
            if (!$stmtParcelas->execute()) {
                throw new RuntimeException($stmtParcelas->error ?: 'Falha ao atualizar parcelas da conta.');
            }
            $stmtParcelas->close();

            if ($transacaoIniciadaAqui) {
                $conn->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($transacaoIniciadaAqui) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackErro) {
                    sgl_int_log_erro('PAGAR_CONTA_ROLLBACK', $rollbackErro);
                }
            }

            sgl_int_log_erro('PAGAR_CONTA', $e);
            return false;
        }
    }
}