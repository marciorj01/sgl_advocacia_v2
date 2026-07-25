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
        /*
         * Mantém compatibilidade com os dois nomes de helper encontrados nas
         * versões do projeto, sem alterar a autenticação homologada do RC1.
         */
        if (function_exists('exigirLogin')) {
            exigirLogin();
        } elseif (function_exists('exigir_login')) {
            exigir_login();
        }

        /*
         * A autorização oficial da Plataforma SaaS é a mesma utilizada pelo
         * index.php. Ela contempla o Administrador Master identificado pela
         * sessão e pela regra central de autorização.
         */
        if (function_exists('rojexEhMasterSaas')) {
            if (rojexEhMasterSaas()) {
                return;
            }

            throw new FinanceiroCorporativoException(
                'Acesso restrito ao MASTER.',
                'ACESSO_NEGADO'
            );
        }

        /*
         * Fallback conservador para uso isolado do serviço, testes ou rotinas
         * antigas que carreguem esta classe fora do index.php.
         */
        $perfil = mb_strtolower(
            trim((string)($_SESSION['perfil'] ?? $_SESSION['user_perfil'] ?? '')),
            'UTF-8'
        );

        $isMaster = !empty($_SESSION['is_master'])
            || in_array(
                $perfil,
                ['master', 'administrador master'],
                true
            );

        if (!$isMaster) {
            throw new FinanceiroCorporativoException(
                'Acesso restrito ao MASTER.',
                'ACESSO_NEGADO'
            );
        }
    }

    protected function usuarioIdAtual(): ?int
    {
        $id = $_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? null;

        return is_numeric($id) && (int)$id > 0
            ? (int)$id
            : null;
    }

    protected function sucesso(
        string $mensagem,
        string $codigo,
        array $dados = []
    ): array {
        return [
            'sucesso' => true,
            'mensagem' => $mensagem,
            'dados' => $dados,
            'codigo' => $codigo,
        ];
    }

    protected function erroInterno(): array
    {
        return [
            'sucesso' => false,
            'mensagem' => 'Não foi possível concluir a operação.',
            'erros' => [],
            'codigo' => 'ERRO_INTERNO',
        ];
    }

    protected function executarSeguro(
        string $acaoLog,
        string $entidade,
        ?string $codigo,
        callable $operacao
    ): array {
        try {
            $this->exigirMaster();

            return $operacao();
        } catch (FinanceiroCorporativoException $e) {
            return $e->paraRetorno();
        } catch (Throwable $e) {
            $this->log->registrarErro(
                $acaoLog,
                $entidade,
                $codigo,
                $e
            );

            return $this->erroInterno();
        }
    }

    protected function buscarUm(
        string $sql,
        string $types = '',
        array $params = []
    ): ?array {
        $stmt = $this->prepararExecutar($sql, $types, $params);

        try {
            $resultado = $stmt->get_result();

            if (!$resultado) {
                throw new RuntimeException(
                    'Falha ao obter o resultado da consulta.'
                );
            }

            return $resultado->fetch_assoc() ?: null;
        } finally {
            $stmt->close();
        }
    }

    protected function listarSql(
        string $sql,
        string $types = '',
        array $params = []
    ): array {
        $stmt = $this->prepararExecutar($sql, $types, $params);

        try {
            $resultado = $stmt->get_result();

            if (!$resultado) {
                throw new RuntimeException(
                    'Falha ao obter o resultado da consulta.'
                );
            }

            return $resultado->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }
    }

    protected function escritorio(int $id): array
    {
        if ($id <= 0) {
            throw new FinanceiroCorporativoException(
                'Escritório inválido.',
                'ESCRITORIO_INVALIDO'
            );
        }

        $row = $this->buscarUm(
            'SELECT id, tenant_id, nome
             FROM escritorios_saas
             WHERE id = ?
             LIMIT 1',
            'i',
            [$id]
        );

        if (!$row) {
            throw new FinanceiroCorporativoException(
                'Escritório não encontrado.',
                'ESCRITORIO_NAO_ENCONTRADO'
            );
        }

        return $row;
    }

    protected function entidadePorCodigo(
        string $tabela,
        string $codigo
    ): array {
        $permitidas = [
            'saas_financeiro_cobrancas',
            'saas_financeiro_pagamentos',
            'saas_financeiro_creditos',
            'saas_financeiro_descontos',
            'saas_financeiro_negociacoes',
            'saas_financeiro_lancamentos',
            'saas_financeiro_transferencias',
            'saas_financeiro_assinaturas',
        ];

        if (!in_array($tabela, $permitidas, true)) {
            throw new LogicException('Tabela não permitida.');
        }

        $codigo = trim($codigo);

        if ($codigo === '') {
            throw new FinanceiroCorporativoException(
                'Código inválido.',
                'CODIGO_INVALIDO'
            );
        }

        /*
         * O nome da tabela não vem diretamente do usuário: ele é validado
         * pela lista fechada acima. O código permanece parametrizado.
         */
        $row = $this->buscarUm(
            "SELECT *
             FROM {$tabela}
             WHERE codigo = ?
             LIMIT 1",
            's',
            [$codigo]
        );

        if (!$row) {
            throw new FinanceiroCorporativoException(
                'Registro não encontrado.',
                'REGISTRO_NAO_ENCONTRADO'
            );
        }

        return $row;
    }

    private function prepararExecutar(
        string $sql,
        string $types = '',
        array $params = []
    ): mysqli_stmt {
        if (($types === '') !== ($params === [])) {
            throw new InvalidArgumentException(
                'Tipos e parâmetros devem ser informados em conjunto.'
            );
        }

        if ($types !== '' && strlen($types) !== count($params)) {
            throw new InvalidArgumentException(
                'A quantidade de tipos não corresponde aos parâmetros.'
            );
        }

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'Falha ao preparar consulta.'
            );
        }

        try {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Falha ao executar consulta.'
                );
            }

            return $stmt;
        } catch (Throwable $e) {
            $stmt->close();
            throw $e;
        }
    }
}