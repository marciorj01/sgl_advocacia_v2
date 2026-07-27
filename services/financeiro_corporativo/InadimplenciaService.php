<?php
declare(strict_types=1);

require_once __DIR__ . '/FinanceiroCorporativoBaseService.php';

/**
 * Analisa cobranças SaaS para alertas e bloqueio assistido.
 *
 * Esta classe NÃO suspende licenças automaticamente. Ela classifica a situação
 * financeira e devolve a ação recomendada para o Dashboard e para o MASTER.
 */
final class InadimplenciaService extends FinanceiroCorporativoBaseService
{
    public static function criarComConexaoOficial(): self
    {
        return new self(conectar());
    }

    /**
     * @return array<string,mixed>
     */
    public function analisar(?int $escritorioId = null): array
    {
        return $this->executarSeguro(
            'Falha na análise de inadimplência',
            'inadimplencia',
            null,
            function () use ($escritorioId): array {
                $configuracoes = $this->carregarConfiguracoes();
                $diasAviso = max(1, (int)($configuracoes['inadimplencia_dias_aviso'] ?? 5));
                $diasCarencia = max(1, (int)($configuracoes['inadimplencia_dias_carencia'] ?? 7));

                $sql = "SELECT c.*
                          FROM saas_financeiro_cobrancas c
                         WHERE c.status IN ('emitida','aberta','parcial','vencida')
                           AND c.saldo_aberto > 0";
                $tipos = '';
                $parametros = [];

                if ($escritorioId !== null && $escritorioId > 0) {
                    $sql .= ' AND c.escritorio_id = ?';
                    $tipos = 'i';
                    $parametros[] = $escritorioId;
                }

                $sql .= ' ORDER BY c.vencimento_em ASC, c.id ASC';
                $cobrancas = $this->listarSql($sql, $tipos, $parametros);
                $hoje = new DateTimeImmutable('today');
                $alertas = [];

                foreach ($cobrancas as $cobranca) {
                    $vencimentoTexto = trim((string)($cobranca['vencimento_em'] ?? ''));
                    if ($vencimentoTexto === '') {
                        continue;
                    }

                    try {
                        $vencimento = new DateTimeImmutable($vencimentoTexto);
                    } catch (Throwable $e) {
                        continue;
                    }

                    $diasUteisParaVencimento = $this->diasUteisEntre($hoje, $vencimento);
                    $diasUteisAtraso = $this->diasUteisEntre($vencimento, $hoje);
                    $fase = 'regular';
                    $acao = 'nenhuma';
                    $elegivelBloqueio = false;

                    if ($vencimento >= $hoje && $diasUteisParaVencimento <= $diasAviso) {
                        $fase = 'vencimento_proximo';
                        $acao = 'avisar_vencimento';
                    } elseif ($vencimento < $hoje && $diasUteisAtraso <= $diasCarencia) {
                        $fase = 'inadimplente_em_carencia';
                        $acao = 'avisar_pendencia';
                    } elseif ($vencimento < $hoje && $diasUteisAtraso > $diasCarencia) {
                        $fase = 'bloqueio_recomendado';
                        $acao = 'avaliar_bloqueio';
                        $elegivelBloqueio = true;
                    }

                    if ($fase === 'regular') {
                        continue;
                    }

                    $cobranca['fase_financeira'] = $fase;
                    $cobranca['acao_recomendada'] = $acao;
                    $cobranca['dias_uteis_para_vencimento'] = max(0, $diasUteisParaVencimento);
                    $cobranca['dias_uteis_atraso'] = max(0, $diasUteisAtraso);
                    $cobranca['elegivel_bloqueio'] = $elegivelBloqueio;
                    $cobranca['bloqueio_automatico_executado'] = false;
                    $alertas[] = $cobranca;
                }

                $this->log->registrarEvento(
                    'Executou análise de inadimplência',
                    'inadimplencia',
                    null,
                    'Análise de vencimento, carência e bloqueio recomendado; nenhuma suspensão automática foi executada.'
                );

                return $this->sucesso(
                    'Análise concluída.',
                    'INADIMPLENCIA_ANALISADA',
                    [
                        'configuracoes' => [
                            'dias_aviso' => $diasAviso,
                            'dias_carencia' => $diasCarencia,
                            'contagem' => 'dias_uteis_sem_feriados',
                            'bloqueio_automatico' => false,
                        ],
                        'cobrancas' => $alertas,
                    ]
                );
            }
        );
    }

    public function atualizarVencidas(): array
    {
        return $this->executarSeguro(
            'Falha ao atualizar vencidas',
            'inadimplencia',
            null,
            function (): array {
                $this->conn->query(
                    "UPDATE saas_financeiro_cobrancas
                        SET status = 'vencida'
                      WHERE status IN ('emitida','aberta','parcial')
                        AND saldo_aberto > 0
                        AND vencimento_em < CURDATE()"
                );

                return $this->sucesso(
                    'Cobranças vencidas atualizadas.',
                    'COBRANCAS_VENCIDAS_ATUALIZADAS',
                    ['afetadas' => $this->conn->affected_rows]
                );
            }
        );
    }

    /** @return array<string,string> */
    private function carregarConfiguracoes(): array
    {
        $configuracoes = [];
        foreach ($this->listarSql(
            "SELECT chave, valor
               FROM saas_financeiro_configuracoes
              WHERE grupo = 'inadimplencia'"
        ) as $registro) {
            $configuracoes[(string)$registro['chave']] = (string)$registro['valor'];
        }

        return $configuracoes;
    }

    /**
     * Conta segunda a sexta entre duas datas, sem incluir a data inicial.
     * Feriados poderão ser incorporados quando houver calendário oficial cadastrado.
     */
    private function diasUteisEntre(DateTimeImmutable $inicio, DateTimeImmutable $fim): int
    {
        if ($fim <= $inicio) {
            return 0;
        }

        $dias = 0;
        for ($data = $inicio->modify('+1 day'); $data <= $fim; $data = $data->modify('+1 day')) {
            $diaSemana = (int)$data->format('N');
            if ($diaSemana <= 5) {
                $dias++;
            }
        }

        return $dias;
    }
}