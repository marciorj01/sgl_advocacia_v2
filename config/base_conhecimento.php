<?php
/**
 * config/base_conhecimento.php
 * Base de Conhecimento do ROJEX.AI
 * Sprint 4.1.1 — Fundação inicial
 *
 * Objetivo:
 * - Centralizar funções reutilizáveis de leitura do banco.
 * - Preparar consultas inteligentes para clientes, advogados, processos e demais módulos.
 * - Evitar duplicação de SQL entre CIJ, Gerador de Peças, Revisor e futuras IAs.
 *
 * Importante:
 * - Este arquivo não altera tabelas.
 * - Este arquivo não executa INSERT, UPDATE ou DELETE.
 * - Nenhum módulo existente depende dele nesta primeira etapa.
 */

declare(strict_types=1);

if (!function_exists('rojex_kb_identificador_valido')) {
    /**
     * Valida nomes de tabelas e colunas antes de utilizá-los em SQL dinâmico.
     */
    function rojex_kb_identificador_valido(string $identificador): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $identificador);
    }
}

if (!function_exists('rojex_kb_tabela_existe')) {
    /**
     * Verifica se uma tabela existe no banco atual.
     */
    function rojex_kb_tabela_existe(mysqli $conn, string $tabela): bool
    {
        if (!rojex_kb_identificador_valido($tabela)) {
            return false;
        }

        try {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('s', $tabela);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return ((int)($row['total'] ?? 0)) > 0;
        } catch (Throwable $e) {
            error_log('[ROJEX KB tabela] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rojex_kb_coluna_existe')) {
    /**
     * Verifica se uma coluna existe em determinada tabela.
     */
    function rojex_kb_coluna_existe(mysqli $conn, string $tabela, string $coluna): bool
    {
        if (
            !rojex_kb_identificador_valido($tabela)
            || !rojex_kb_identificador_valido($coluna)
        ) {
            return false;
        }

        try {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('ss', $tabela, $coluna);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return ((int)($row['total'] ?? 0)) > 0;
        } catch (Throwable $e) {
            error_log('[ROJEX KB coluna] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rojex_kb_colunas_tabela')) {
    /**
     * Retorna a lista de colunas existentes em uma tabela.
     */
    function rojex_kb_colunas_tabela(mysqli $conn, string $tabela): array
    {
        if (
            !rojex_kb_identificador_valido($tabela)
            || !rojex_kb_tabela_existe($conn, $tabela)
        ) {
            return [];
        }

        try {
            $stmt = $conn->prepare(
                'SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION'
            );

            if (!$stmt) {
                return [];
            }

            $stmt->bind_param('s', $tabela);
            $stmt->execute();
            $res = $stmt->get_result();

            $colunas = [];
            while ($row = $res->fetch_assoc()) {
                $colunas[] = (string)$row['COLUMN_NAME'];
            }

            $stmt->close();
            return $colunas;
        } catch (Throwable $e) {
            error_log('[ROJEX KB colunas] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rojex_kb_normalizar_texto')) {
    /**
     * Normaliza um termo para interpretação textual.
     */
    function rojex_kb_normalizar_texto(?string $valor): string
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        $valor = mb_strtolower($valor, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $valor));
    }
}

if (!function_exists('rojex_kb_somente_digitos')) {
    /**
     * Remove máscaras de CPF, CNPJ, telefone, OAB e número de processo.
     */
    function rojex_kb_somente_digitos(?string $valor): string
    {
        return (string)preg_replace('/\D+/', '', (string)$valor);
    }
}

if (!function_exists('rojex_kb_limite')) {
    /**
     * Mantém limites de consultas dentro de uma faixa segura.
     */
    function rojex_kb_limite(int $limite, int $padrao = 10, int $maximo = 100): int
    {
        if ($limite <= 0) {
            return $padrao;
        }

        return min($limite, $maximo);
    }
}

if (!function_exists('rojex_kb_bind_params')) {
    /**
     * Faz o bind de parâmetros de forma reutilizável.
     */
    function rojex_kb_bind_params(mysqli_stmt $stmt, string $tipos, array $parametros): bool
    {
        if ($tipos === '') {
            return true;
        }

        if (strlen($tipos) !== count($parametros)) {
            return false;
        }

        $referencias = [];
        foreach ($parametros as $indice => $valor) {
            $referencias[$indice] = &$parametros[$indice];
        }

        return $stmt->bind_param($tipos, ...$referencias);
    }
}

if (!function_exists('rojex_kb_consultar')) {
    /**
     * Executa uma consulta SELECT preparada e retorna todas as linhas.
     */
    function rojex_kb_consultar(
        mysqli $conn,
        string $sql,
        string $tipos = '',
        array $parametros = []
    ): array {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('[ROJEX KB prepare] ' . $conn->error);
                return [];
            }

            if (!rojex_kb_bind_params($stmt, $tipos, $parametros)) {
                $stmt->close();
                return [];
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $dados = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            return $dados;
        } catch (Throwable $e) {
            error_log('[ROJEX KB consulta] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rojex_kb_consultar_um')) {
    /**
     * Executa uma consulta SELECT preparada e retorna somente a primeira linha.
     */
    function rojex_kb_consultar_um(
        mysqli $conn,
        string $sql,
        string $tipos = '',
        array $parametros = []
    ): ?array {
        $dados = rojex_kb_consultar($conn, $sql, $tipos, $parametros);
        return $dados[0] ?? null;
    }
}

if (!function_exists('rojex_kb_total')) {
    /**
     * Retorna um valor numérico de uma consulta agregada com alias "total".
     */
    function rojex_kb_total(
        mysqli $conn,
        string $sql,
        string $tipos = '',
        array $parametros = []
    ): float {
        $row = rojex_kb_consultar_um($conn, $sql, $tipos, $parametros);
        return (float)($row['total'] ?? 0);
    }
}

if (!function_exists('rojex_kb_filtro_deletado')) {
    /**
     * Gera o filtro de registros ativos somente quando a coluna deletado existir.
     *
     * Exemplo de retorno:
     * AND COALESCE(c.deletado, 0) = 0
     */
    function rojex_kb_filtro_deletado(
        mysqli $conn,
        string $tabela,
        string $alias = ''
    ): string {
        if (!rojex_kb_coluna_existe($conn, $tabela, 'deletado')) {
            return '';
        }

        $prefixo = '';
        if ($alias !== '' && rojex_kb_identificador_valido($alias)) {
            $prefixo = $alias . '.';
        }

        return " AND COALESCE({$prefixo}`deletado`, 0) = 0";
    }
}

if (!function_exists('rojex_kb_status')) {
    /**
     * Retorna informações básicas da Base de Conhecimento.
     * Útil para diagnóstico futuro sem consultar dados sensíveis.
     */
    function rojex_kb_status(mysqli $conn): array
    {
        $tabelas = [
            'clientes',
            'advogados',
            'processos',
            'agenda',
            'honorarios',
            'contas_receber',
            'contas_pagar',
            'documentos_arquivos',
            'logs_sistema',
        ];

        $disponiveis = [];
        foreach ($tabelas as $tabela) {
            $disponiveis[$tabela] = rojex_kb_tabela_existe($conn, $tabela);
        }

        return [
            'nome' => 'Base de Conhecimento ROJEX.AI',
            'versao' => '4.1.1-fundacao',
            'somente_leitura' => true,
            'tabelas' => $disponiveis,
        ];
    }
}