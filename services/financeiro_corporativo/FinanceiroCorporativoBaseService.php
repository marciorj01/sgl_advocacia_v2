<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/FinanceiroCorporativoException.php';
require_once __DIR__ . '/FinanceiroCorporativoValidator.php';
require_once __DIR__ . '/FinanceiroCorporativoCodigo.php';
require_once __DIR__ . '/FinanceiroCorporativoTransaction.php';
require_once __DIR__ . '/FinanceiroCorporativoLogService.php';

abstract class FinanceiroCorporativoBaseService
{
    protected FinanceiroCorporativoLogService $log;

    public function __construct(protected mysqli $conn)
    {
        $this->log = new FinanceiroCorporativoLogService($conn);
    }

    protected function exigirMaster(): void
    {
        if (function_exists('exigir_login')) {
            exigir_login();
        }
        $perfil = strtolower((string)($_SESSION['perfil'] ?? $_SESSION['user_perfil'] ?? ''));
        $isMaster = !empty($_SESSION['is_master']) || in_array($perfil, ['master', 'administrador master'], true);
        if (!$isMaster) {
            throw new FinanceiroCorporativoException('Acesso restrito ao MASTER.', 'ACESSO_NEGADO');
        }
    }

    protected function usuarioIdAtual(): ?int
    {
        $id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;
        return is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }

    protected function sucesso(string $mensagem, string $codigo, array $dados = []): array
    {
        return ['sucesso' => true, 'mensagem' => $mensagem, 'dados' => $dados, 'codigo' => $codigo];
    }

    protected function erroInterno(): array
    {
        return ['sucesso' => false, 'mensagem' => 'Não foi possível concluir a operação.', 'erros' => [], 'codigo' => 'ERRO_INTERNO'];
    }

    protected function executarSeguro(string $acaoLog, string $entidade, ?string $codigo, callable $operacao): array
    {
        try {
            $this->exigirMaster();
            return $operacao();
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro($acaoLog, $entidade, $codigo, $e);
            return $this->erroInterno();
        }
    }

    protected function buscarUm(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar consulta.');
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    protected function listarSql(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar consulta.');
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    protected function escritorio(int $id): array
    {
        $row = $this->buscarUm('SELECT id, tenant_id, nome FROM escritorios_saas WHERE id = ? LIMIT 1', 'i', [$id]);
        if (!$row) throw new FinanceiroCorporativoException('Escritório não encontrado.', 'ESCRITORIO_NAO_ENCONTRADO');
        return $row;
    }

    protected function entidadePorCodigo(string $tabela, string $codigo): array
    {
        $permitidas = ['saas_financeiro_cobrancas','saas_financeiro_pagamentos','saas_financeiro_creditos','saas_financeiro_descontos','saas_financeiro_negociacoes','saas_financeiro_lancamentos','saas_financeiro_transferencias','saas_financeiro_assinaturas'];
        if (!in_array($tabela, $permitidas, true)) throw new LogicException('Tabela não permitida.');
        $row = $this->buscarUm("SELECT * FROM {$tabela} WHERE codigo = ? LIMIT 1", 's', [$codigo]);
        if (!$row) throw new FinanceiroCorporativoException('Registro não encontrado.', 'REGISTRO_NAO_ENCONTRADO');
        return $row;
    }
}
