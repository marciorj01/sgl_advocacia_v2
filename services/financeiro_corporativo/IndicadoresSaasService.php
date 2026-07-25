<?php
declare(strict_types=1);

require_once __DIR__ . '/FinanceiroCorporativoBaseService.php';

final class IndicadoresSaasService extends FinanceiroCorporativoBaseService
{
    public static function criarComConexaoOficial(): self
    {
        return new self(conectar());
    }

    public function resumo(?string $inicio = null, ?string $fim = null): array
    {
        return $this->executarSeguro(
            'Falha ao calcular indicadores',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = $inicio
                    ? FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio')
                    : date('Y-m-01');
                $fim = $fim
                    ? FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim')
                    : date('Y-m-t');

                $this->validarPeriodo($inicio, $fim);

                $mrr = $this->buscarUm(
                    "SELECT COALESCE(SUM(
                        CASE periodicidade
                            WHEN 'mensal' THEN valor_final
                            WHEN 'trimestral' THEN valor_final / 3
                            WHEN 'semestral' THEN valor_final / 6
                            WHEN 'anual' THEN valor_final / 12
                            ELSE 0
                        END
                    ), 0) AS valor
                    FROM saas_financeiro_assinaturas
                    WHERE status = 'ativa'"
                );

                $recebida = $this->buscarUm(
                    "SELECT COALESCE(SUM(valor_liquido), 0) AS valor
                    FROM saas_financeiro_pagamentos
                    WHERE status IN ('confirmado', 'compensado')
                      AND DATE(COALESCE(compensado_em, recebido_em)) BETWEEN ? AND ?",
                    'ss',
                    [$inicio, $fim]
                );

                $prevista = $this->buscarUm(
                    "SELECT COALESCE(SUM(saldo_aberto), 0) AS valor
                    FROM saas_financeiro_cobrancas
                    WHERE status IN ('emitida', 'aberta', 'parcial', 'vencida')
                      AND vencimento_em BETWEEN ? AND ?",
                    'ss',
                    [$inicio, $fim]
                );

                $inadimplencia = $this->buscarUm(
                    "SELECT COUNT(*) AS quantidade,
                            COALESCE(SUM(saldo_aberto), 0) AS valor
                    FROM saas_financeiro_cobrancas
                    WHERE status = 'vencida'
                      AND saldo_aberto > 0"
                );

                $cobrancas = $this->buscarUm(
                    "SELECT
                        SUM(status IN ('emitida', 'aberta', 'parcial') AND saldo_aberto > 0) AS abertas,
                        SUM(status = 'vencida' AND saldo_aberto > 0) AS vencidas,
                        COALESCE(SUM(CASE
                            WHEN status IN ('emitida', 'aberta', 'parcial') AND saldo_aberto > 0
                            THEN saldo_aberto ELSE 0 END
                        ), 0) AS valor_aberto,
                        COALESCE(SUM(CASE
                            WHEN status = 'vencida' AND saldo_aberto > 0
                            THEN saldo_aberto ELSE 0 END
                        ), 0) AS valor_vencido
                    FROM saas_financeiro_cobrancas"
                );

                $assinaturas = $this->buscarUm(
                    "SELECT COUNT(*) AS total,
                            SUM(status = 'ativa') AS ativas,
                            SUM(status = 'trial') AS trials,
                            SUM(status = 'cancelada') AS canceladas
                    FROM saas_financeiro_assinaturas"
                );

                $creditos = $this->buscarUm(
                    "SELECT COALESCE(SUM(saldo_disponivel), 0) AS valor
                    FROM saas_financeiro_creditos
                    WHERE status = 'disponivel'"
                );

                $mrrValor = (string)($mrr['valor'] ?? '0.00');
                $arr = number_format((float)$mrrValor * 12, 2, '.', '');
                $ticket = (int)($assinaturas['ativas'] ?? 0) > 0
                    ? number_format(
                        (float)$mrrValor / (int)$assinaturas['ativas'],
                        2,
                        '.',
                        ''
                    )
                    : '0.00';

                $this->log->registrarEvento(
                    'Consultou indicadores SaaS',
                    'indicadores',
                    null,
                    'Indicadores calculados sem dashboard.'
                );

                return $this->sucesso(
                    'Indicadores calculados.',
                    'INDICADORES_CALCULADOS',
                    [
                        'periodo' => ['inicio' => $inicio, 'fim' => $fim],
                        'mrr' => $mrrValor,
                        'arr' => $arr,
                        'receita_recebida' => (string)($recebida['valor'] ?? '0.00'),
                        'receita_prevista' => (string)($prevista['valor'] ?? '0.00'),
                        'inadimplencia' => $inadimplencia ?? [
                            'quantidade' => 0,
                            'valor' => '0.00',
                        ],
                        'cobrancas' => $cobrancas ?? [
                            'abertas' => 0,
                            'vencidas' => 0,
                            'valor_aberto' => '0.00',
                            'valor_vencido' => '0.00',
                        ],
                        'assinaturas' => $assinaturas ?? [
                            'total' => 0,
                            'ativas' => 0,
                            'trials' => 0,
                            'canceladas' => 0,
                        ],
                        'creditos_disponiveis' => (string)($creditos['valor'] ?? '0.00'),
                        'ticket_medio' => $ticket,
                    ]
                );
            }
        );
    }

    public function receitaPorPlano(): array
    {
        return $this->executarSeguro(
            'Falha ao calcular receita por plano',
            'indicadores',
            null,
            function () {
                $itens = $this->listarSql(
                    "SELECT COALESCE(plano_nome_snapshot, 'Sem plano') AS plano,
                            COUNT(*) AS assinaturas,
                            COALESCE(SUM(valor_final), 0) AS valor_contratado
                    FROM saas_financeiro_assinaturas
                    WHERE status = 'ativa'
                    GROUP BY plano_nome_snapshot
                    ORDER BY valor_contratado DESC"
                );

                return $this->sucesso(
                    'Receita por plano calculada.',
                    'RECEITA_POR_PLANO',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function receitaPorEscritorio(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular receita por escritório',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim);

                $itens = $this->listarSql(
                    "SELECT p.escritorio_id,
                            COALESCE(e.nome, 'Escritório não identificado') AS escritorio,
                            COALESCE(SUM(p.valor_liquido), 0) AS receita
                    FROM saas_financeiro_pagamentos p
                    LEFT JOIN escritorios_saas e ON e.id = p.escritorio_id
                    WHERE p.status IN ('confirmado', 'compensado')
                      AND DATE(COALESCE(p.compensado_em, p.recebido_em)) BETWEEN ? AND ?
                    GROUP BY p.escritorio_id, e.nome
                    ORDER BY receita DESC",
                    'ss',
                    [$inicio, $fim]
                );

                return $this->sucesso(
                    'Receita por escritório calculada.',
                    'RECEITA_POR_ESCRITORIO',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function receitaMensal(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular receita mensal',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim, 36);

                $linhas = $this->listarSql(
                    "SELECT DATE_FORMAT(
                                DATE(COALESCE(compensado_em, recebido_em)),
                                '%Y-%m'
                            ) AS competencia,
                            COALESCE(SUM(valor_liquido), 0) AS receita
                    FROM saas_financeiro_pagamentos
                    WHERE status IN ('confirmado', 'compensado')
                      AND DATE(COALESCE(compensado_em, recebido_em)) BETWEEN ? AND ?
                    GROUP BY competencia
                    ORDER BY competencia",
                    'ss',
                    [$inicio, $fim]
                );

                $mapa = [];
                foreach ($linhas as $linha) {
                    $mapa[(string)$linha['competencia']] = (string)$linha['receita'];
                }

                $itens = [];
                foreach ($this->competenciasMensais($inicio, $fim) as $competencia) {
                    $itens[] = [
                        'competencia' => $competencia,
                        'receita' => $mapa[$competencia] ?? '0.00',
                    ];
                }

                return $this->sucesso(
                    'Receita mensal calculada.',
                    'RECEITA_MENSAL',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function evolucaoMrrArr(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular evolução do MRR e ARR',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim, 24);

                $itens = [];

                foreach ($this->competenciasMensais($inicio, $fim) as $competencia) {
                    $primeiroDia = $competencia . '-01';
                    $ultimoDia = (new DateTimeImmutable($primeiroDia))
                        ->modify('last day of this month')
                        ->format('Y-m-d');

                    $linha = $this->buscarUm(
                        "SELECT COALESCE(SUM(
                            CASE periodicidade
                                WHEN 'mensal' THEN valor_final
                                WHEN 'trimestral' THEN valor_final / 3
                                WHEN 'semestral' THEN valor_final / 6
                                WHEN 'anual' THEN valor_final / 12
                                ELSE 0
                            END
                        ), 0) AS mrr
                        FROM saas_financeiro_assinaturas
                        WHERE inicio_em <= ?
                          AND (fim_em IS NULL OR fim_em >= ?)",
                        'ss',
                        [$ultimoDia, $primeiroDia]
                    );

                    $mrr = (string)($linha['mrr'] ?? '0.00');
                    $itens[] = [
                        'competencia' => $competencia,
                        'mrr' => $mrr,
                        'arr' => number_format((float)$mrr * 12, 2, '.', ''),
                    ];
                }

                return $this->sucesso(
                    'Evolução do MRR e ARR calculada.',
                    'EVOLUCAO_MRR_ARR',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function receitaPorPeriodicidade(): array
    {
        return $this->executarSeguro(
            'Falha ao calcular receita por periodicidade',
            'indicadores',
            null,
            function () {
                $itens = $this->listarSql(
                    "SELECT periodicidade,
                            COUNT(*) AS assinaturas,
                            COALESCE(SUM(valor_final), 0) AS valor_contratado,
                            COALESCE(SUM(
                                CASE periodicidade
                                    WHEN 'mensal' THEN valor_final
                                    WHEN 'trimestral' THEN valor_final / 3
                                    WHEN 'semestral' THEN valor_final / 6
                                    WHEN 'anual' THEN valor_final / 12
                                    ELSE 0
                                END
                            ), 0) AS mrr_equivalente
                    FROM saas_financeiro_assinaturas
                    WHERE status = 'ativa'
                    GROUP BY periodicidade
                    ORDER BY mrr_equivalente DESC"
                );

                return $this->sucesso(
                    'Receita por periodicidade calculada.',
                    'RECEITA_POR_PERIODICIDADE',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function assinaturasPorStatus(): array
    {
        return $this->executarSeguro(
            'Falha ao calcular assinaturas por status',
            'indicadores',
            null,
            function () {
                $itens = $this->listarSql(
                    "SELECT status,
                            COUNT(*) AS quantidade,
                            COALESCE(SUM(valor_final), 0) AS valor_contratado
                    FROM saas_financeiro_assinaturas
                    GROUP BY status
                    ORDER BY quantidade DESC, status"
                );

                return $this->sucesso(
                    'Assinaturas por status calculadas.',
                    'ASSINATURAS_POR_STATUS',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function inadimplenciaMensal(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular inadimplência mensal',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim, 36);

                $linhas = $this->listarSql(
                    "SELECT DATE_FORMAT(vencimento_em, '%Y-%m') AS competencia,
                            COUNT(*) AS quantidade,
                            COALESCE(SUM(saldo_aberto), 0) AS valor
                    FROM saas_financeiro_cobrancas
                    WHERE status = 'vencida'
                      AND saldo_aberto > 0
                      AND vencimento_em BETWEEN ? AND ?
                    GROUP BY competencia
                    ORDER BY competencia",
                    'ss',
                    [$inicio, $fim]
                );

                $mapa = [];
                foreach ($linhas as $linha) {
                    $mapa[(string)$linha['competencia']] = [
                        'quantidade' => (int)$linha['quantidade'],
                        'valor' => (string)$linha['valor'],
                    ];
                }

                $itens = [];
                foreach ($this->competenciasMensais($inicio, $fim) as $competencia) {
                    $itens[] = [
                        'competencia' => $competencia,
                        'quantidade' => $mapa[$competencia]['quantidade'] ?? 0,
                        'valor' => $mapa[$competencia]['valor'] ?? '0.00',
                    ];
                }

                return $this->sucesso(
                    'Inadimplência mensal calculada.',
                    'INADIMPLENCIA_MENSAL',
                    ['itens' => $itens]
                );
            }
        );
    }

    public function churn(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular churn',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim);

                $inicioBase = $this->buscarUm(
                    "SELECT COUNT(*) AS total
                    FROM saas_financeiro_assinaturas
                    WHERE inicio_em < ?
                      AND (fim_em IS NULL OR fim_em >= ?)",
                    'ss',
                    [$inicio, $inicio]
                );

                $cancelamentos = $this->buscarUm(
                    "SELECT COUNT(*) AS total
                    FROM saas_financeiro_assinaturas
                    WHERE status IN ('cancelada', 'encerrada')
                      AND fim_em BETWEEN ? AND ?",
                    'ss',
                    [$inicio, $fim]
                );

                $base = max(1, (int)($inicioBase['total'] ?? 0));
                $taxa = number_format(
                    ((int)($cancelamentos['total'] ?? 0) / $base) * 100,
                    2,
                    '.',
                    ''
                );

                return $this->sucesso(
                    'Churn calculado.',
                    'CHURN_CALCULADO',
                    [
                        'base_inicial' => (int)($inicioBase['total'] ?? 0),
                        'cancelamentos' => (int)($cancelamentos['total'] ?? 0),
                        'percentual' => $taxa,
                    ]
                );
            }
        );
    }

    public function conversaoTrial(string $inicio, string $fim): array
    {
        return $this->executarSeguro(
            'Falha ao calcular conversão trial',
            'indicadores',
            null,
            function () use ($inicio, $fim) {
                $inicio = FinanceiroCorporativoValidator::dataObrigatoria($inicio, 'inicio');
                $fim = FinanceiroCorporativoValidator::dataObrigatoria($fim, 'fim');
                $this->validarPeriodo($inicio, $fim);

                $resultado = $this->buscarUm(
                    "SELECT COUNT(*) AS trials,
                            SUM(trial_convertido_em IS NOT NULL) AS convertidos
                    FROM saas_financeiro_assinaturas
                    WHERE trial_inicio_em BETWEEN ? AND ?",
                    'ss',
                    [$inicio, $fim]
                ) ?? ['trials' => 0, 'convertidos' => 0];

                $trials = max(1, (int)$resultado['trials']);
                $resultado['percentual'] = number_format(
                    ((int)$resultado['convertidos'] / $trials) * 100,
                    2,
                    '.',
                    ''
                );

                return $this->sucesso(
                    'Conversão calculada.',
                    'CONVERSAO_TRIAL',
                    $resultado
                );
            }
        );
    }

    private function validarPeriodo(string $inicio, string $fim, int $maximoMeses = 120): void
    {
        $dataInicio = new DateTimeImmutable($inicio);
        $dataFim = new DateTimeImmutable($fim);

        if ($dataFim < $dataInicio) {
            throw new FinanceiroCorporativoException(
                'A data final não pode ser anterior à data inicial.',
                'VALIDACAO_FALHOU',
                ['fim' => 'Informe uma data final igual ou posterior à inicial.']
            );
        }

        $meses = (
            ((int)$dataFim->format('Y') - (int)$dataInicio->format('Y')) * 12
        ) + ((int)$dataFim->format('m') - (int)$dataInicio->format('m'));

        if ($meses > $maximoMeses) {
            throw new FinanceiroCorporativoException(
                'O período informado é muito extenso.',
                'VALIDACAO_FALHOU',
                ['periodo' => 'Selecione no máximo ' . $maximoMeses . ' meses.']
            );
        }
    }

    private function competenciasMensais(string $inicio, string $fim): array
    {
        $cursor = (new DateTimeImmutable($inicio))->modify('first day of this month');
        $limite = (new DateTimeImmutable($fim))->modify('first day of this month');
        $competencias = [];

        while ($cursor <= $limite) {
            $competencias[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $competencias;
    }
}