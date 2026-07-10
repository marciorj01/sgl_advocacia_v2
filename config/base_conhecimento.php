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



if (!function_exists('rojex_kb_cliente_por_documento')) {
    /**
     * Localiza um cliente ativo por CPF ou CNPJ, com ou sem máscara.
     */
    function rojex_kb_cliente_por_documento(mysqli $conn, string $documento): ?array
    {
        $documento = rojex_kb_somente_digitos($documento);

        if (
            $documento === '' ||
            !in_array(strlen($documento), [11, 14], true) ||
            !rojex_kb_tabela_existe($conn, 'clientes')
        ) {
            return null;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'clientes');
        if (!in_array('cpf_cnpj', $colunas, true)) {
            return null;
        }

        $camposDesejados = [
            'id', 'nome', 'cpf_cnpj', 'tipo_pessoa', 'telefone',
            'celular', 'whatsapp', 'email', 'cidade', 'estado', 'status'
        ];
        $campos = array_values(array_intersect($camposDesejados, $colunas));

        if ($campos === []) {
            return null;
        }

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $campos
        )) . '
                FROM clientes
                WHERE ' . rojex_kb_sql_only_digits('COALESCE(cpf_cnpj, \'\')') . ' = ?'
                . $filtroLixeira . '
                LIMIT 1';

        return rojex_kb_consultar_um($conn, $sql, 's', [$documento]);
    }
}

if (!function_exists('rojex_kb_sql_only_digits')) {
    /**
     * Monta uma expressão SQL para normalizar números armazenados com máscara.
     *
     * Use apenas com expressões internas e controladas pelo sistema.
     */
    function rojex_kb_sql_only_digits(string $expressao): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . $expressao
            . ", '.', ''), '-', ''), '/', ''), '(', ''), ')', ''), ' ', ''), '+', '')";
    }
}

if (!function_exists('rojex_kb_limpar_termo_cliente')) {
    /**
     * Remove palavras de comando comuns antes da pesquisa por cliente.
     */
    function rojex_kb_limpar_termo_cliente(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');

        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(cliente|clientes|localizar|buscar|pesquisar|procure|encontre|cadastro|cpf|cnpj|nome|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim(preg_replace('/\s+/', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_clientes_por_termo')) {
    /**
     * Pesquisa clientes ativos por nome, e-mail ou cidade.
     */
    function rojex_kb_clientes_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 5
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'clientes')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '' || mb_strlen($termoOriginal, 'UTF-8') < 3) {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_cliente($termoOriginal);
        if ($termoLimpo === '' || mb_strlen($termoLimpo, 'UTF-8') < 3) {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'clientes');
        $camposPesquisa = array_values(array_intersect(
            ['nome', 'email', 'cidade'],
            $colunas
        ));

        if ($camposPesquisa === []) {
            return [];
        }

        $camposDesejados = [
            'id', 'nome', 'cpf_cnpj', 'tipo_pessoa', 'telefone',
            'celular', 'whatsapp', 'email', 'cidade', 'estado', 'status'
        ];
        $camposRetorno = array_values(array_intersect($camposDesejados, $colunas));

        if ($camposRetorno === []) {
            return [];
        }

        $where = [];
        $params = [];
        $types = '';
        $like = '%' . $termoLimpo . '%';

        foreach ($camposPesquisa as $campo) {
            $where[] = "`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $ordem = in_array('nome', $colunas, true)
            ? 'nome ASC'
            : 'id DESC';

        $limiteSeguro = rojex_kb_limite($limite, 5, 50);

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $camposRetorno
        )) . '
                FROM clientes
                WHERE (' . implode(' OR ', $where) . ')'
                . $filtroLixeira . '
                ORDER BY ' . $ordem . '
                LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_resumo_clientes')) {
    /**
     * Retorna indicadores básicos de clientes para CIJ e demais módulos.
     */
    function rojex_kb_resumo_clientes(
        mysqli $conn,
        ?string $inicio = null,
        ?string $fim = null
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'clientes')) {
            return [
                'ativos' => 0,
                'novos_periodo' => 0,
            ];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'clientes');
        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' WHERE COALESCE(deletado, 0) = 0'
            : ' WHERE 1 = 1';

        $sqlAtivos = 'SELECT COUNT(*) AS total
                      FROM clientes'
                      . $filtroLixeira;

        if (in_array('status', $colunas, true)) {
            $sqlAtivos .= " AND status = 'Ativo'";
        }

        $ativos = (int)(rojex_kb_total($conn, $sqlAtivos) ?? 0);

        $novos = 0;
        if (
            $inicio !== null &&
            $fim !== null &&
            in_array('data_cadastro', $colunas, true)
        ) {
            $sqlNovos = 'SELECT COUNT(*) AS total
                         FROM clientes'
                         . $filtroLixeira
                         . ' AND data_cadastro BETWEEN ? AND ?';

            $novos = (int)(rojex_kb_total(
                $conn,
                $sqlNovos,
                'ss',
                [$inicio, $fim]
            ) ?? 0);
        }

        return [
            'ativos' => $ativos,
            'novos_periodo' => $novos,
        ];
    }
}




if (!function_exists('rojex_kb_limpar_termo_advogado')) {
    /**
     * Remove palavras de comando comuns antes da pesquisa por advogado.
     */
    function rojex_kb_limpar_termo_advogado(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');

        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(advogado|advogada|advogados|advogadas|doutor|doutora|dr|dra|localizar|buscar|pesquisar|procure|encontre|cadastro|nome|oab|cpf|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim((string)preg_replace('/\s+/u', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_advogados_por_termo')) {
    /**
     * Pesquisa advogados ativos por nome, OAB, CPF, contato, e-mail ou especialidade.
     */
    function rojex_kb_advogados_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 5
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'advogados')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '') {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_advogado($termoOriginal);
        if ($termoLimpo === '') {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'advogados');

        $camposPesquisa = array_values(array_intersect(
            ['nome', 'oab', 'oab_uf', 'cpf', 'telefone', 'celular', 'email', 'especialidade'],
            $colunas
        ));

        if ($camposPesquisa === []) {
            return [];
        }

        $camposDesejados = [
            'id', 'nome', 'oab', 'oab_uf', 'cpf', 'telefone',
            'celular', 'email', 'especialidade', 'status'
        ];
        $camposRetorno = array_values(array_intersect($camposDesejados, $colunas));

        if ($camposRetorno === []) {
            return [];
        }

        $where = [];
        $params = [];
        $types = '';
        $likeTexto = '%' . $termoLimpo . '%';
        $digitos = rojex_kb_somente_digitos($termoOriginal);

        foreach ($camposPesquisa as $campo) {
            $where[] = "`{$campo}` LIKE ?";
            $params[] = $likeTexto;
            $types .= 's';

            if (
                $digitos !== ''
                && in_array($campo, ['oab', 'cpf', 'telefone', 'celular'], true)
            ) {
                $where[] = rojex_kb_sql_only_digits("COALESCE(`{$campo}`, '')") . ' LIKE ?';
                $params[] = '%' . $digitos . '%';
                $types .= 's';
            }
        }

        if (
            in_array('oab', $colunas, true)
            && in_array('oab_uf', $colunas, true)
            && preg_match('/^\s*(\d{2,8})\s*[\/\-]?\s*([a-z]{2})\s*$/iu', $termoOriginal, $partes)
        ) {
            $where[] = '(' . rojex_kb_sql_only_digits("COALESCE(`oab`, '')") . ' LIKE ? AND UPPER(COALESCE(`oab_uf`, \'\')) = ?)';
            $params[] = '%' . rojex_kb_somente_digitos($partes[1]) . '%';
            $params[] = mb_strtoupper($partes[2], 'UTF-8');
            $types .= 'ss';
        }

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $ordem = in_array('nome', $colunas, true)
            ? 'nome ASC'
            : 'id DESC';

        $limiteSeguro = rojex_kb_limite($limite, 5, 50);

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $camposRetorno
        )) . '
                FROM advogados
                WHERE (' . implode(' OR ', $where) . ')'
                . $filtroLixeira . '
                ORDER BY ' . $ordem . '
                LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_advogado_por_documento')) {
    /**
     * Localiza um advogado por CPF ou OAB, com ou sem máscara.
     */
    function rojex_kb_advogado_por_documento(
        mysqli $conn,
        string $documento
    ): ?array {
        if (!rojex_kb_tabela_existe($conn, 'advogados')) {
            return null;
        }

        $documentoOriginal = trim($documento);
        $digitos = rojex_kb_somente_digitos($documentoOriginal);

        if ($documentoOriginal === '' || $digitos === '') {
            return null;
        }

        $resultados = rojex_kb_advogados_por_termo(
            $conn,
            $documentoOriginal,
            1
        );

        return $resultados[0] ?? null;
    }
}

if (!function_exists('rojex_kb_resumo_advogados')) {
    /**
     * Retorna indicadores básicos do cadastro de advogados.
     */
    function rojex_kb_resumo_advogados(mysqli $conn): array
    {
        if (!rojex_kb_tabela_existe($conn, 'advogados')) {
            return [
                'total' => 0,
                'ativos' => 0,
            ];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'advogados');

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' WHERE COALESCE(deletado, 0) = 0'
            : ' WHERE 1 = 1';

        $total = (int)rojex_kb_total(
            $conn,
            'SELECT COUNT(*) AS total FROM advogados' . $filtroLixeira
        );

        $ativos = $total;
        if (in_array('status', $colunas, true)) {
            $ativos = (int)rojex_kb_total(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM advogados"
                 . $filtroLixeira
                 . " AND status = 'Ativo'"
            );
        }

        return [
            'total' => $total,
            'ativos' => $ativos,
        ];
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