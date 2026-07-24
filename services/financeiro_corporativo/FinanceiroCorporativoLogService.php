<?php
/**
 * Adaptador do Financeiro Corporativo para o LOG Enterprise e histórico funcional.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/integracoes.php';

final class FinanceiroCorporativoLogService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function registrarEvento(
        string $acao,
        string $entidade,
        ?string $codigoPublico,
        ?string $detalhes = null,
        array $contexto = []
    ): void {
        sgl_registrar_log(
            $this->conn,
            $acao,
            'saas_financeiro_' . $entidade,
            $codigoPublico,
            $detalhes,
            array_merge([
                'tipo_acao' => 'EVENTO',
                'modulo' => 'Financeiro Corporativo MASTER SaaS',
                'origem' => 'FinanceiroCorporativoService',
                'resultado' => 'SUCESSO',
                'nivel' => 'INFO',
            ], $contexto)
        );
    }

    public function registrarErro(string $acao, string $entidade, ?string $codigoPublico, Throwable $erro): void
    {
        sgl_registrar_log(
            $this->conn,
            $acao,
            'saas_financeiro_' . $entidade,
            $codigoPublico,
            'A operação financeira não pôde ser concluída.',
            [
                'tipo_acao' => 'EVENTO',
                'modulo' => 'Financeiro Corporativo MASTER SaaS',
                'origem' => 'FinanceiroCorporativoService',
                'resultado' => 'FALHA',
                'nivel' => 'ERRO',
            ]
        );
        error_log('[ROJEX FINANCEIRO CORPORATIVO] ' . $erro->getMessage());
    }

    public function registrarStatus(
        string $entidade,
        int $entidadeId,
        ?int $escritorioId,
        ?string $tenantId,
        ?string $statusAnterior,
        string $statusNovo,
        ?string $motivo = null,
        array $dadosContexto = []
    ): void {
        $json = $dadosContexto === [] ? null : json_encode(
            $dadosContexto,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $usuarioId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $stmt = $this->conn->prepare(
            'INSERT INTO saas_financeiro_status_historico
                (entidade, entidade_id, escritorio_id, tenant_id, status_anterior,
                 status_novo, motivo, dados_contexto, alterado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar histórico financeiro.');
        }
        $stmt->bind_param(
            'siisssssi',
            $entidade,
            $entidadeId,
            $escritorioId,
            $tenantId,
            $statusAnterior,
            $statusNovo,
            $motivo,
            $json,
            $usuarioId
        );
        $stmt->execute();
        $stmt->close();
    }
}
