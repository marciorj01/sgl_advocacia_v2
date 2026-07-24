<?php
/**
 * Serviço de Assinaturas do Financeiro Corporativo MASTER SaaS.
 *
 * Não altera licenças diretamente e não modifica tabelas homologadas do RC1.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/FinanceiroCorporativoException.php';
require_once __DIR__ . '/FinanceiroCorporativoValidator.php';
require_once __DIR__ . '/FinanceiroCorporativoCodigo.php';
require_once __DIR__ . '/FinanceiroCorporativoTransaction.php';
require_once __DIR__ . '/FinanceiroCorporativoLogService.php';

final class AssinaturaService
{
    private FinanceiroCorporativoLogService $log;

    public function __construct(private mysqli $conn)
    {
        $this->log = new FinanceiroCorporativoLogService($conn);
    }

    public static function criarComConexaoOficial(): self
    {
        return new self(conectar());
    }

    public function criar(array $dados, array $itens = []): array
    {
        $this->exigirMaster();

        try {
            $escritorioId = FinanceiroCorporativoValidator::inteiroPositivo(
                $dados['escritorio_id'] ?? null,
                'escritorio_id'
            );
            $licencaId = FinanceiroCorporativoValidator::inteiroOpcional(
                $dados['licenca_id'] ?? null,
                'licenca_id'
            );
            $planoId = FinanceiroCorporativoValidator::inteiroOpcional(
                $dados['plano_id'] ?? null,
                'plano_id'
            );
            $periodicidade = FinanceiroCorporativoValidator::enum(
                $dados['periodicidade'] ?? '',
                'periodicidade',
                ['mensal', 'anual', 'trimestral', 'semestral', 'unica']
            );
            $inicioEm = FinanceiroCorporativoValidator::dataObrigatoria(
                $dados['inicio_em'] ?? '',
                'inicio_em'
            );
            $fimEm = FinanceiroCorporativoValidator::dataOpcional($dados['fim_em'] ?? null, 'fim_em');
            $proximaCobrancaEm = FinanceiroCorporativoValidator::dataOpcional(
                $dados['proxima_cobranca_em'] ?? null,
                'proxima_cobranca_em'
            );
            $renovacaoEm = FinanceiroCorporativoValidator::dataOpcional(
                $dados['renovacao_em'] ?? null,
                'renovacao_em'
            );
            $valorBase = FinanceiroCorporativoValidator::dinheiro(
                $dados['valor_base'] ?? '0.00',
                'valor_base'
            );
            $valorDescontos = FinanceiroCorporativoValidator::dinheiro(
                $dados['valor_descontos'] ?? '0.00',
                'valor_descontos'
            );
            $diaVencimento = $dados['dia_vencimento'] ?? null;
            if ($diaVencimento !== null && $diaVencimento !== '') {
                $diaVencimento = filter_var(
                    $diaVencimento,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 31]]
                );
                if ($diaVencimento === false) {
                    throw new FinanceiroCorporativoException(
                        'Dia de vencimento inválido.',
                        'VALIDACAO_FALHOU',
                        ['dia_vencimento' => 'Informe um dia entre 1 e 31.']
                    );
                }
                $diaVencimento = (int)$diaVencimento;
            } else {
                $diaVencimento = null;
            }

            if ($fimEm !== null && $fimEm < $inicioEm) {
                throw new FinanceiroCorporativoException(
                    'A data final não pode ser anterior à data inicial.',
                    'VALIDACAO_FALHOU'
                );
            }
            if (FinanceiroCorporativoValidator::compararDinheiro($valorDescontos, $valorBase) > 0 && $itens === []) {
                throw new FinanceiroCorporativoException(
                    'O desconto não pode superar o valor contratado.',
                    'VALIDACAO_FALHOU'
                );
            }

            $escritorio = $this->buscarEscritorio($escritorioId);
            $licenca = $licencaId !== null ? $this->buscarLicenca($licencaId, $escritorioId) : null;
            $plano = $planoId !== null ? $this->buscarPlano($planoId) : null;
            $codigo = FinanceiroCorporativoCodigo::gerar('ASS');
            $usuarioId = $this->usuarioIdAtual();
            $observacoes = FinanceiroCorporativoValidator::textoOpcional($dados['observacoes'] ?? null, 65000);
            $renovacaoAutomatica = !array_key_exists('renovacao_automatica', $dados)
                || filter_var($dados['renovacao_automatica'], FILTER_VALIDATE_BOOLEAN);

            $assinatura = FinanceiroCorporativoTransaction::executar(
                $this->conn,
                function () use (
                    $codigo,
                    $escritorio,
                    $licenca,
                    $plano,
                    $periodicidade,
                    $valorBase,
                    $valorDescontos,
                    $diaVencimento,
                    $inicioEm,
                    $fimEm,
                    $proximaCobrancaEm,
                    $renovacaoEm,
                    $renovacaoAutomatica,
                    $observacoes,
                    $usuarioId,
                    $itens
                ): array {
                    $valorItens = '0.00';
                    foreach ($itens as $item) {
                        $itemNormalizado = $this->normalizarItem($item);
                        $valorItens = FinanceiroCorporativoValidator::somarDinheiro(
                            $valorItens,
                            $itemNormalizado['valor_total']
                        );
                    }
                    $bruto = FinanceiroCorporativoValidator::somarDinheiro($valorBase, $valorItens);
                    if (FinanceiroCorporativoValidator::compararDinheiro($valorDescontos, $bruto) > 0) {
                        throw new FinanceiroCorporativoException(
                            'O desconto não pode superar o valor contratado.',
                            'VALIDACAO_FALHOU'
                        );
                    }
                    $valorFinal = FinanceiroCorporativoValidator::subtrairDinheiro($bruto, $valorDescontos);
                    $licencaIdLocal = $licenca['id'] ?? null;
                    $planoIdLocal = $plano['id'] ?? null;
                    $planoCodigo = $plano['codigo'] ?? null;
                    $planoNome = $plano['nome'] ?? null;
                    $renovacaoAutomaticaInt = $renovacaoAutomatica ? 1 : 0;

                    $stmt = $this->conn->prepare(
                        'INSERT INTO saas_financeiro_assinaturas
                            (codigo, escritorio_id, tenant_id, licenca_id, plano_id,
                             plano_codigo_snapshot, plano_nome_snapshot, periodicidade,
                             status, valor_base, valor_itens, valor_descontos, valor_final,
                             moeda, dia_vencimento, inicio_em, fim_em, proxima_cobranca_em,
                             renovacao_em, renovacao_automatica, observacoes, criado_por, atualizado_por)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'rascunho\', ?, ?, ?, ?, \'BRL\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Falha ao preparar criação da assinatura.');
                    }
                    $stmt->bind_param(
                        'sisiisssssssissssisii',
                        $codigo,
                        $escritorio['id'],
                        $escritorio['tenant_id'],
                        $licencaIdLocal,
                        $planoIdLocal,
                        $planoCodigo,
                        $planoNome,
                        $periodicidade,
                        $valorBase,
                        $valorItens,
                        $valorDescontos,
                        $valorFinal,
                        $diaVencimento,
                        $inicioEm,
                        $fimEm,
                        $proximaCobrancaEm,
                        $renovacaoEm,
                        $renovacaoAutomaticaInt,
                        $observacoes,
                        $usuarioId,
                        $usuarioId
                    );
                    $stmt->execute();
                    $assinaturaId = (int)$this->conn->insert_id;
                    $stmt->close();

                    foreach ($itens as $item) {
                        $this->inserirItem($assinaturaId, $item, $usuarioId);
                    }

                    $this->log->registrarStatus(
                        'assinatura',
                        $assinaturaId,
                        (int)$escritorio['id'],
                        (string)$escritorio['tenant_id'],
                        null,
                        'rascunho',
                        'Assinatura criada.'
                    );

                    return $this->buscarPorId($assinaturaId);
                }
            );

            $this->log->registrarEvento(
                'Criou assinatura financeira SaaS',
                'assinaturas',
                $codigo,
                'Assinatura criada em rascunho.',
                ['dados_novos' => ['codigo' => $codigo, 'escritorio_id' => $escritorioId]]
            );

            return $this->sucesso('Assinatura criada com sucesso.', 'ASSINATURA_CRIADA', $assinatura);
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro('Falha ao criar assinatura financeira SaaS', 'assinaturas', null, $e);
            return $this->erroInterno();
        }
    }

    public function adicionarItem(string $codigoAssinatura, array $item): array
    {
        return $this->alterarItens($codigoAssinatura, function (int $assinaturaId) use ($item): void {
            $this->inserirItem($assinaturaId, $item, $this->usuarioIdAtual());
        }, 'Item adicionado à assinatura.', 'ASSINATURA_ITEM_ADICIONADO');
    }

    public function removerItem(string $codigoAssinatura, int $itemId, ?string $motivo = null): array
    {
        return $this->alterarItens($codigoAssinatura, function (int $assinaturaId) use ($itemId): void {
            $stmt = $this->conn->prepare(
                'UPDATE saas_financeiro_assinatura_itens
                    SET ativo = 0, fim_em = COALESCE(fim_em, CURRENT_DATE)
                  WHERE id = ? AND assinatura_id = ? AND ativo = 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar remoção do item.');
            }
            $stmt->bind_param('ii', $itemId, $assinaturaId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new FinanceiroCorporativoException('Item não encontrado ou já removido.', 'ITEM_NAO_ENCONTRADO');
            }
            $stmt->close();
        }, $motivo ?: 'Item removido da assinatura.', 'ASSINATURA_ITEM_REMOVIDO');
    }

    public function atualizarRascunho(string $codigo, array $dados): array
    {
        $this->exigirMaster();
        try {
            $assinatura = $this->buscarPorCodigoInterno($codigo, true);
            $this->exigirStatus($assinatura, ['rascunho']);

            $periodicidade = array_key_exists('periodicidade', $dados)
                ? FinanceiroCorporativoValidator::enum($dados['periodicidade'], 'periodicidade', ['mensal', 'anual', 'trimestral', 'semestral', 'unica'])
                : $assinatura['periodicidade'];
            $valorBase = array_key_exists('valor_base', $dados)
                ? FinanceiroCorporativoValidator::dinheiro($dados['valor_base'], 'valor_base')
                : $assinatura['valor_base'];
            $valorDescontos = array_key_exists('valor_descontos', $dados)
                ? FinanceiroCorporativoValidator::dinheiro($dados['valor_descontos'], 'valor_descontos')
                : $assinatura['valor_descontos'];
            $inicioEm = array_key_exists('inicio_em', $dados)
                ? FinanceiroCorporativoValidator::dataObrigatoria($dados['inicio_em'], 'inicio_em')
                : $assinatura['inicio_em'];
            $observacoes = array_key_exists('observacoes', $dados)
                ? FinanceiroCorporativoValidator::textoOpcional($dados['observacoes'], 65000)
                : $assinatura['observacoes'];
            $valorFinal = FinanceiroCorporativoValidator::subtrairDinheiro(
                FinanceiroCorporativoValidator::somarDinheiro($valorBase, $assinatura['valor_itens']),
                $valorDescontos
            );
            if (FinanceiroCorporativoValidator::compararDinheiro($valorFinal, '0.00') < 0) {
                throw new FinanceiroCorporativoException('O desconto não pode gerar valor negativo.', 'VALIDACAO_FALHOU');
            }
            $usuarioId = $this->usuarioIdAtual();

            $stmt = $this->conn->prepare(
                'UPDATE saas_financeiro_assinaturas
                    SET periodicidade = ?, valor_base = ?, valor_descontos = ?, valor_final = ?,
                        inicio_em = ?, observacoes = ?, atualizado_por = ?
                  WHERE id = ? AND status = \'rascunho\''
            );
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar atualização da assinatura.');
            }
            $stmt->bind_param(
                'ssssssii',
                $periodicidade,
                $valorBase,
                $valorDescontos,
                $valorFinal,
                $inicioEm,
                $observacoes,
                $usuarioId,
                $assinatura['id']
            );
            $stmt->execute();
            $stmt->close();

            $atualizada = $this->buscarPorId((int)$assinatura['id']);
            $this->log->registrarEvento(
                'Atualizou assinatura financeira SaaS em rascunho',
                'assinaturas',
                $codigo,
                'Dados contratuais em rascunho atualizados.',
                ['dados_anteriores' => $assinatura, 'dados_novos' => $atualizada]
            );
            return $this->sucesso('Assinatura atualizada com sucesso.', 'ASSINATURA_ATUALIZADA', $atualizada);
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro('Falha ao atualizar assinatura financeira SaaS', 'assinaturas', $codigo, $e);
            return $this->erroInterno();
        }
    }

    public function ativar(string $codigo, ?string $motivo = null): array
    {
        return $this->alterarStatus($codigo, ['rascunho', 'trial'], 'ativa', $motivo ?: 'Assinatura ativada.', []);
    }

    public function iniciarTrial(string $codigo, string $inicioEm, string $fimEm): array
    {
        $inicioEm = FinanceiroCorporativoValidator::dataObrigatoria($inicioEm, 'trial_inicio_em');
        $fimEm = FinanceiroCorporativoValidator::dataObrigatoria($fimEm, 'trial_fim_em');
        if ($fimEm < $inicioEm) {
            return (new FinanceiroCorporativoException('O fim do trial não pode ser anterior ao início.', 'VALIDACAO_FALHOU'))->paraRetorno();
        }
        return $this->alterarStatus(
            $codigo,
            ['rascunho'],
            'trial',
            'Trial iniciado.',
            ['trial_inicio_em' => $inicioEm, 'trial_fim_em' => $fimEm]
        );
    }

    public function converterTrial(string $codigo): array
    {
        return $this->alterarStatus(
            $codigo,
            ['trial'],
            'ativa',
            'Trial convertido em assinatura ativa.',
            ['trial_convertido_em' => date('Y-m-d H:i:s')]
        );
    }

    public function suspender(string $codigo, string $motivo): array
    {
        return $this->alterarStatus(
            $codigo,
            ['ativa', 'inadimplente'],
            'suspensa',
            FinanceiroCorporativoValidator::textoObrigatorio($motivo, 'motivo', 500),
            ['suspensa_em' => date('Y-m-d H:i:s')]
        );
    }

    public function solicitarCancelamento(string $codigo, string $motivo, ?string $dataEfetiva = null): array
    {
        $campos = ['cancelamento_solicitado_em' => date('Y-m-d H:i:s')];
        if ($dataEfetiva !== null) {
            $campos['cancelamento_efetivo_em'] = FinanceiroCorporativoValidator::dataObrigatoria($dataEfetiva, 'cancelamento_efetivo_em') . ' 00:00:00';
        }
        $campos['motivo_cancelamento'] = FinanceiroCorporativoValidator::textoObrigatorio($motivo, 'motivo', 500);
        return $this->alterarStatus($codigo, ['ativa', 'trial', 'suspensa', 'inadimplente'], 'cancelamento_solicitado', $motivo, $campos);
    }

    public function cancelar(string $codigo, string $motivo): array
    {
        return $this->alterarStatus(
            $codigo,
            ['rascunho', 'trial', 'ativa', 'suspensa', 'inadimplente', 'cancelamento_solicitado'],
            'cancelada',
            FinanceiroCorporativoValidator::textoObrigatorio($motivo, 'motivo', 500),
            [
                'cancelamento_efetivo_em' => date('Y-m-d H:i:s'),
                'motivo_cancelamento' => $motivo,
                'renovacao_automatica' => 0,
            ]
        );
    }

    public function encerrar(string $codigo, string $motivo): array
    {
        return $this->alterarStatus(
            $codigo,
            ['ativa', 'suspensa', 'cancelamento_solicitado'],
            'encerrada',
            FinanceiroCorporativoValidator::textoObrigatorio($motivo, 'motivo', 500),
            ['fim_em' => date('Y-m-d'), 'renovacao_automatica' => 0]
        );
    }

    public function consultarPorCodigo(string $codigo): array
    {
        $this->exigirMaster();
        try {
            return $this->sucesso(
                'Assinatura localizada.',
                'ASSINATURA_LOCALIZADA',
                $this->buscarPorCodigoInterno($codigo, true)
            );
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            return $this->erroInterno();
        }
    }

    public function listarPorEscritorio(int $escritorioId): array
    {
        $this->exigirMaster();
        try {
            $escritorioId = FinanceiroCorporativoValidator::inteiroPositivo($escritorioId, 'escritorio_id');
            $this->buscarEscritorio($escritorioId);
            $stmt = $this->conn->prepare(
                'SELECT a.*, e.nome AS escritorio_nome
                   FROM saas_financeiro_assinaturas a
                   JOIN escritorios_saas e ON e.id = a.escritorio_id AND e.tenant_id = a.tenant_id
                  WHERE a.escritorio_id = ?
                  ORDER BY a.criado_em DESC, a.id DESC'
            );
            $stmt->bind_param('i', $escritorioId);
            $stmt->execute();
            $dados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $this->sucesso('Assinaturas consultadas.', 'ASSINATURAS_LISTADAS', $dados);
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            return $this->erroInterno();
        }
    }

    private function alterarItens(string $codigo, callable $operacao, string $motivo, string $codigoRetorno): array
    {
        $this->exigirMaster();
        try {
            $resultado = FinanceiroCorporativoTransaction::executar($this->conn, function () use ($codigo, $operacao, $motivo): array {
                $assinatura = $this->buscarPorCodigoInterno($codigo, true);
                $this->exigirStatus($assinatura, ['rascunho', 'trial']);
                $operacao((int)$assinatura['id']);
                $this->recalcularInterno((int)$assinatura['id']);
                $atualizada = $this->buscarPorId((int)$assinatura['id']);
                $this->log->registrarStatus(
                    'assinatura',
                    (int)$assinatura['id'],
                    (int)$assinatura['escritorio_id'],
                    (string)$assinatura['tenant_id'],
                    (string)$assinatura['status'],
                    (string)$assinatura['status'],
                    $motivo,
                    ['evento' => 'alteracao_itens']
                );
                return $atualizada;
            });
            $this->log->registrarEvento($motivo, 'assinaturas', $codigo, $motivo);
            return $this->sucesso($motivo, $codigoRetorno, $resultado);
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro('Falha ao alterar itens da assinatura', 'assinaturas', $codigo, $e);
            return $this->erroInterno();
        }
    }

    private function alterarStatus(string $codigo, array $statusPermitidos, string $statusNovo, string $motivo, array $camposExtras): array
    {
        $this->exigirMaster();
        try {
            $resultado = FinanceiroCorporativoTransaction::executar($this->conn, function () use ($codigo, $statusPermitidos, $statusNovo, $motivo, $camposExtras): array {
                $assinatura = $this->buscarPorCodigoInterno($codigo, true);
                $this->exigirStatus($assinatura, $statusPermitidos);

                $permitidos = [
                    'trial_inicio_em', 'trial_fim_em', 'trial_convertido_em', 'suspensa_em',
                    'cancelamento_solicitado_em', 'cancelamento_efetivo_em', 'motivo_cancelamento',
                    'fim_em', 'renovacao_automatica'
                ];
                $sets = ['status = ?', 'atualizado_por = ?'];
                $tipos = 'si';
                $valores = [$statusNovo, $this->usuarioIdAtual()];
                foreach ($camposExtras as $campo => $valor) {
                    if (!in_array($campo, $permitidos, true)) {
                        continue;
                    }
                    $sets[] = $campo . ' = ?';
                    $tipos .= is_int($valor) ? 'i' : 's';
                    $valores[] = $valor;
                }
                $tipos .= 'i';
                $valores[] = (int)$assinatura['id'];
                $sql = 'UPDATE saas_financeiro_assinaturas SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Falha ao preparar alteração de status.');
                }
                $stmt->bind_param($tipos, ...$valores);
                $stmt->execute();
                $stmt->close();

                $this->log->registrarStatus(
                    'assinatura',
                    (int)$assinatura['id'],
                    (int)$assinatura['escritorio_id'],
                    (string)$assinatura['tenant_id'],
                    (string)$assinatura['status'],
                    $statusNovo,
                    $motivo
                );
                return $this->buscarPorId((int)$assinatura['id']);
            });

            $this->log->registrarEvento(
                'Alterou status de assinatura financeira SaaS',
                'assinaturas',
                $codigo,
                $motivo,
                ['dados_novos' => ['status' => $statusNovo]]
            );
            return $this->sucesso('Status da assinatura atualizado.', 'ASSINATURA_STATUS_ATUALIZADO', $resultado);
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro('Falha ao alterar status da assinatura', 'assinaturas', $codigo, $e);
            return $this->erroInterno();
        }
    }

    private function inserirItem(int $assinaturaId, array $item, int $usuarioId): void
    {
        $n = $this->normalizarItem($item);
        $stmt = $this->conn->prepare(
            'INSERT INTO saas_financeiro_assinatura_itens
                (assinatura_id, tipo, referencia_id, codigo_snapshot, descricao_snapshot,
                 quantidade, valor_unitario, valor_desconto, valor_total, recorrente, ativo,
                 inicio_em, fim_em, observacoes, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar item da assinatura.');
        }
        $stmt->bind_param(
            'isissssssisssi',
            $assinaturaId,
            $n['tipo'],
            $n['referencia_id'],
            $n['codigo_snapshot'],
            $n['descricao_snapshot'],
            $n['quantidade'],
            $n['valor_unitario'],
            $n['valor_desconto'],
            $n['valor_total'],
            $n['recorrente'],
            $n['inicio_em'],
            $n['fim_em'],
            $n['observacoes'],
            $usuarioId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function normalizarItem(array $item): array
    {
        $tipo = FinanceiroCorporativoValidator::enum(
            $item['tipo'] ?? '',
            'tipo',
            ['plano', 'modulo', 'servico', 'implantacao', 'ajuste', 'outro']
        );
        $quantidade = trim((string)($item['quantidade'] ?? '1'));
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $quantidade) || $quantidade === '0') {
            throw new FinanceiroCorporativoException('Quantidade inválida.', 'VALIDACAO_FALHOU');
        }
        $valorUnitario = FinanceiroCorporativoValidator::dinheiro($item['valor_unitario'] ?? '0.00', 'valor_unitario');
        $valorDesconto = FinanceiroCorporativoValidator::dinheiro($item['valor_desconto'] ?? '0.00', 'valor_desconto');
        $bruto = FinanceiroCorporativoValidator::multiplicarDinheiro($valorUnitario, $quantidade);
        if (FinanceiroCorporativoValidator::compararDinheiro($valorDesconto, $bruto) > 0) {
            throw new FinanceiroCorporativoException('O desconto do item não pode superar seu valor bruto.', 'VALIDACAO_FALHOU');
        }
        return [
            'tipo' => $tipo,
            'referencia_id' => FinanceiroCorporativoValidator::inteiroOpcional($item['referencia_id'] ?? null, 'referencia_id'),
            'codigo_snapshot' => FinanceiroCorporativoValidator::textoOpcional($item['codigo_snapshot'] ?? null, 80),
            'descricao_snapshot' => FinanceiroCorporativoValidator::textoObrigatorio($item['descricao_snapshot'] ?? '', 'descricao_snapshot', 255),
            'quantidade' => $quantidade,
            'valor_unitario' => $valorUnitario,
            'valor_desconto' => $valorDesconto,
            'valor_total' => FinanceiroCorporativoValidator::subtrairDinheiro($bruto, $valorDesconto),
            'recorrente' => !array_key_exists('recorrente', $item) || filter_var($item['recorrente'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'inicio_em' => FinanceiroCorporativoValidator::dataOpcional($item['inicio_em'] ?? null, 'inicio_em'),
            'fim_em' => FinanceiroCorporativoValidator::dataOpcional($item['fim_em'] ?? null, 'fim_em'),
            'observacoes' => FinanceiroCorporativoValidator::textoOpcional($item['observacoes'] ?? null, 65000),
        ];
    }

    private function recalcularInterno(int $assinaturaId): void
    {
        $stmt = $this->conn->prepare(
            'SELECT COALESCE(SUM(valor_total), 0.00) AS valor_itens
               FROM saas_financeiro_assinatura_itens
              WHERE assinatura_id = ? AND ativo = 1'
        );
        $stmt->bind_param('i', $assinaturaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $valorItens = FinanceiroCorporativoValidator::dinheiro((string)($row['valor_itens'] ?? '0.00'), 'valor_itens');

        $assinatura = $this->buscarPorId($assinaturaId);
        $valorFinal = FinanceiroCorporativoValidator::subtrairDinheiro(
            FinanceiroCorporativoValidator::somarDinheiro($assinatura['valor_base'], $valorItens),
            $assinatura['valor_descontos']
        );
        if (FinanceiroCorporativoValidator::compararDinheiro($valorFinal, '0.00') < 0) {
            throw new FinanceiroCorporativoException('O desconto não pode gerar valor negativo.', 'VALIDACAO_FALHOU');
        }
        $usuarioId = $this->usuarioIdAtual();
        $stmt = $this->conn->prepare(
            'UPDATE saas_financeiro_assinaturas
                SET valor_itens = ?, valor_final = ?, atualizado_por = ?
              WHERE id = ?'
        );
        $stmt->bind_param('ssii', $valorItens, $valorFinal, $usuarioId, $assinaturaId);
        $stmt->execute();
        $stmt->close();
    }

    private function buscarEscritorio(int $id): array
    {
        $stmt = $this->conn->prepare('SELECT id, tenant_id, nome, status FROM escritorios_saas WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || trim((string)$row['tenant_id']) === '') {
            throw new FinanceiroCorporativoException('Escritório não localizado.', 'ESCRITORIO_NAO_ENCONTRADO');
        }
        return $row;
    }

    private function buscarLicenca(int $id, int $escritorioId): array
    {
        $stmt = $this->conn->prepare('SELECT id, escritorio_id, chave_licenca, plano, status FROM licencas_saas WHERE id = ? AND escritorio_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $escritorioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new FinanceiroCorporativoException('Licença não localizada para este escritório.', 'LICENCA_NAO_ENCONTRADA');
        }
        return $row;
    }

    private function buscarPlano(int $id): array
    {
        $stmt = $this->conn->prepare('SELECT id, codigo, nome, ativo FROM planos_saas WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)$row['ativo'] !== 1) {
            throw new FinanceiroCorporativoException('Plano não localizado ou inativo.', 'PLANO_NAO_ENCONTRADO');
        }
        return $row;
    }

    private function buscarPorCodigoInterno(string $codigo, bool $comItens): array
    {
        $codigo = FinanceiroCorporativoValidator::textoObrigatorio($codigo, 'codigo', 50);
        $stmt = $this->conn->prepare(
            'SELECT a.*, e.nome AS escritorio_nome
               FROM saas_financeiro_assinaturas a
               JOIN escritorios_saas e ON e.id = a.escritorio_id AND e.tenant_id = a.tenant_id
              WHERE a.codigo = ? LIMIT 1'
        );
        $stmt->bind_param('s', $codigo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new FinanceiroCorporativoException('Assinatura não localizada.', 'ASSINATURA_NAO_ENCONTRADA');
        }
        if ($comItens) {
            $row['itens'] = $this->listarItens((int)$row['id']);
        }
        return $row;
    }

    private function buscarPorId(int $id): array
    {
        $stmt = $this->conn->prepare('SELECT * FROM saas_financeiro_assinaturas WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new FinanceiroCorporativoException('Assinatura não localizada.', 'ASSINATURA_NAO_ENCONTRADA');
        }
        $row['itens'] = $this->listarItens($id);
        return $row;
    }

    private function listarItens(int $assinaturaId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM saas_financeiro_assinatura_itens WHERE assinatura_id = ? ORDER BY ativo DESC, id ASC'
        );
        $stmt->bind_param('i', $assinaturaId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function exigirStatus(array $assinatura, array $permitidos): void
    {
        if (!in_array((string)$assinatura['status'], $permitidos, true)) {
            throw new FinanceiroCorporativoException(
                'A operação não é permitida no status atual da assinatura.',
                'STATUS_ASSINATURA_INVALIDO',
                ['status' => (string)$assinatura['status']]
            );
        }
    }

    private function exigirMaster(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            iniciarSessaoSegura();
        }
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        $perfil = trim((string)($_SESSION['perfil'] ?? ''));
        $permissoes = $_SESSION['permissoes_tenant'] ?? ($_SESSION['tenant']['permissoes'] ?? []);
        $autorizado = $usuarioId > 0 && (
            $perfil === 'Administrador Master'
            || (is_array($permissoes) && in_array('plataforma_total', $permissoes, true))
        );
        if (!$autorizado) {
            throw new FinanceiroCorporativoException('Acesso não autorizado.', 'ACESSO_NEGADO');
        }
    }

    private function usuarioIdAtual(): int
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            throw new FinanceiroCorporativoException('Usuário responsável não identificado.', 'ACESSO_NEGADO');
        }
        return $id;
    }

    private function sucesso(string $mensagem, string $codigo, mixed $dados): array
    {
        return ['sucesso' => true, 'mensagem' => $mensagem, 'dados' => $dados, 'codigo' => $codigo];
    }

    private function erroInterno(): array
    {
        return [
            'sucesso' => false,
            'mensagem' => 'Não foi possível concluir a operação.',
            'erros' => [],
            'codigo' => 'ERRO_INTERNO',
        ];
    }
}
