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




if (!function_exists('rojex_kb_limpar_termo_processo')) {
    function rojex_kb_limpar_termo_processo(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');
        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(processo|processos|número|numero|nº|localizar|buscar|pesquisar|procure|encontre|cadastro|mostrar|mostre|qual|quais|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim((string)preg_replace('/\s+/u', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_processos_por_termo')) {
    function rojex_kb_processos_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 5
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'processos')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '') {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_processo($termoOriginal);
        if ($termoLimpo === '') {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'processos');
        if (!in_array('id', $colunas, true)) {
            return [];
        }

        $temClientes = rojex_kb_tabela_existe($conn, 'clientes')
            && in_array('cliente_id', $colunas, true);

        $temAdvogados = rojex_kb_tabela_existe($conn, 'advogados')
            && in_array('advogado_id', $colunas, true);

        $joins = [];
        if ($temClientes) {
            $joins[] = 'LEFT JOIN clientes c ON c.id = p.cliente_id';
        }
        if ($temAdvogados) {
            $joins[] = 'LEFT JOIN advogados a ON a.id = p.advogado_id';
        }

        $where = [];
        $params = [];
        $types = '';
        $likeTexto = '%' . $termoLimpo . '%';
        $digitos = rojex_kb_somente_digitos($termoOriginal);

        foreach ([
            'id',
            'numero_processo',
            'tipo_processo',
            'vara',
            'comarca',
            'fase_atual',
            'status',
            'observacoes'
        ] as $campo) {
            if (!in_array($campo, $colunas, true)) {
                continue;
            }

            $where[] = "p.`{$campo}` LIKE ?";
            $params[] = $likeTexto;
            $types .= 's';

            if ($digitos !== '' && in_array($campo, ['id', 'numero_processo'], true)) {
                $where[] = rojex_kb_sql_only_digits("COALESCE(p.`{$campo}`, '')") . ' LIKE ?';
                $params[] = '%' . $digitos . '%';
                $types .= 's';
            }
        }

        if ($temClientes) {
            $where[] = 'c.nome LIKE ?';
            $params[] = $likeTexto;
            $types .= 's';
        }

        if ($temAdvogados) {
            $where[] = 'a.nome LIKE ?';
            $params[] = $likeTexto;
            $types .= 's';
        }

        if ($where === []) {
            return [];
        }

        $select = [
            'p.id',
            in_array('numero_processo', $colunas, true) ? 'p.numero_processo' : "'' AS numero_processo",
            in_array('cliente_id', $colunas, true) ? 'p.cliente_id' : "NULL AS cliente_id",
            $temClientes ? "COALESCE(c.nome, '') AS cliente_nome" : "'' AS cliente_nome",
            in_array('advogado_id', $colunas, true) ? 'p.advogado_id' : "NULL AS advogado_id",
            $temAdvogados ? "COALESCE(a.nome, '') AS advogado_nome" : "'' AS advogado_nome",
            in_array('tipo_processo', $colunas, true) ? 'p.tipo_processo' : "'' AS tipo_processo",
            in_array('vara', $colunas, true) ? 'p.vara' : "'' AS vara",
            in_array('comarca', $colunas, true) ? 'p.comarca' : "'' AS comarca",
            in_array('data_distribuicao', $colunas, true) ? 'p.data_distribuicao' : "NULL AS data_distribuicao",
            in_array('fase_atual', $colunas, true) ? 'p.fase_atual' : "'' AS fase_atual",
            in_array('valor_causa', $colunas, true) ? 'p.valor_causa' : '0 AS valor_causa',
            in_array('proximo_prazo', $colunas, true) ? 'p.proximo_prazo' : "NULL AS proximo_prazo",
            in_array('status', $colunas, true) ? 'p.status' : "'' AS status",
            in_array('observacoes', $colunas, true) ? 'p.observacoes' : "'' AS observacoes",
        ];

        $filtroExcluido = in_array('status', $colunas, true)
            ? " AND COALESCE(p.status, '') <> 'Excluído'"
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 5, 50);

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM processos p '
            . implode(' ', $joins)
            . ' WHERE (' . implode(' OR ', $where) . ')'
            . $filtroExcluido
            . ' ORDER BY '
            . (in_array('proximo_prazo', $colunas, true)
                ? "COALESCE(p.proximo_prazo, '2999-12-31') ASC, "
                : '')
            . 'p.id DESC'
            . ' LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_processo_por_numero')) {
    function rojex_kb_processo_por_numero(
        mysqli $conn,
        string $numero
    ): ?array {
        $resultados = rojex_kb_processos_por_termo($conn, $numero, 1);
        return $resultados[0] ?? null;
    }
}

if (!function_exists('rojex_kb_processos_por_prazo')) {
    function rojex_kb_processos_por_prazo(
        mysqli $conn,
        string $dataInicio,
        string $dataFim,
        int $limite = 20
    ): array {
        if (
            !rojex_kb_tabela_existe($conn, 'processos')
            || !rojex_kb_coluna_existe($conn, 'processos', 'proximo_prazo')
        ) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'processos');
        $temClientes = rojex_kb_tabela_existe($conn, 'clientes')
            && in_array('cliente_id', $colunas, true);

        $joinCliente = $temClientes
            ? 'LEFT JOIN clientes c ON c.id = p.cliente_id'
            : '';

        $clienteNome = $temClientes
            ? "COALESCE(c.nome, '') AS cliente_nome"
            : "'' AS cliente_nome";

        $filtroStatus = in_array('status', $colunas, true)
            ? " AND p.status = 'Em Andamento'"
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 20, 100);

        $sql = "SELECT
                    p.id,
                    p.numero_processo,
                    {$clienteNome},
                    p.tipo_processo,
                    p.fase_atual,
                    p.proximo_prazo,
                    p.status
                FROM processos p
                {$joinCliente}
                WHERE p.proximo_prazo BETWEEN ? AND ?
                {$filtroStatus}
                ORDER BY p.proximo_prazo ASC, p.id DESC
                LIMIT {$limiteSeguro}";

        return rojex_kb_consultar(
            $conn,
            $sql,
            'ss',
            [$dataInicio, $dataFim]
        );
    }
}

if (!function_exists('rojex_kb_resumo_processos')) {
    function rojex_kb_resumo_processos(
        mysqli $conn,
        ?string $dataInicioPrazo = null,
        ?string $dataFimPrazo = null
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'processos')) {
            return [
                'total' => 0,
                'em_andamento' => 0,
                'prazos_periodo' => 0,
                'valor_causas' => 0.0,
            ];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'processos');
        $whereBase = in_array('status', $colunas, true)
            ? " WHERE status <> 'Excluído'"
            : ' WHERE 1 = 1';

        $total = (int)rojex_kb_total(
            $conn,
            'SELECT COUNT(*) AS total FROM processos' . $whereBase
        );

        $emAndamento = 0;
        if (in_array('status', $colunas, true)) {
            $emAndamento = (int)rojex_kb_total(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM processos
                 WHERE status = 'Em Andamento'"
            );
        }

        $prazosPeriodo = 0;
        if (
            $dataInicioPrazo !== null
            && $dataFimPrazo !== null
            && in_array('proximo_prazo', $colunas, true)
        ) {
            $sqlPrazo = 'SELECT COUNT(*) AS total
                         FROM processos
                         WHERE proximo_prazo BETWEEN ? AND ?';

            if (in_array('status', $colunas, true)) {
                $sqlPrazo .= " AND status = 'Em Andamento'";
            }

            $prazosPeriodo = (int)rojex_kb_total(
                $conn,
                $sqlPrazo,
                'ss',
                [$dataInicioPrazo, $dataFimPrazo]
            );
        }

        $valorCausas = 0.0;
        if (in_array('valor_causa', $colunas, true)) {
            $valorCausas = rojex_kb_total(
                $conn,
                'SELECT COALESCE(SUM(valor_causa), 0) AS total
                 FROM processos'
                 . $whereBase
            );
        }

        return [
            'total' => $total,
            'em_andamento' => $emAndamento,
            'prazos_periodo' => $prazosPeriodo,
            'valor_causas' => $valorCausas,
        ];
    }
}




if (!function_exists('rojex_kb_limpar_termo_agenda')) {
    /**
     * Remove palavras de comando comuns antes da pesquisa na agenda.
     */
    function rojex_kb_limpar_termo_agenda(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');

        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(agenda|compromisso|compromissos|evento|eventos|localizar|buscar|pesquisar|procure|encontre|mostrar|mostre|qual|quais|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim((string)preg_replace('/\s+/u', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_agenda_por_termo')) {
    /**
     * Pesquisa compromissos por ID, cliente, processo, advogado,
     * tipo, local, status, prazo fatal ou observações.
     */
    function rojex_kb_agenda_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 10
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'agenda')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '') {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_agenda($termoOriginal);
        if ($termoLimpo === '') {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'agenda');
        if (!in_array('id', $colunas, true)) {
            return [];
        }

        $temClientes = rojex_kb_tabela_existe($conn, 'clientes')
            && in_array('cliente_id', $colunas, true);

        $temAdvogados = rojex_kb_tabela_existe($conn, 'advogados')
            && in_array('advogado_id', $colunas, true);

        $joins = [];
        if ($temClientes) {
            $joins[] = 'LEFT JOIN clientes c ON c.id = ag.cliente_id';
        }
        if ($temAdvogados) {
            $joins[] = 'LEFT JOIN advogados adv ON adv.id = ag.advogado_id';
        }

        $where = [];
        $params = [];
        $types = '';
        $like = '%' . $termoLimpo . '%';

        foreach ([
            'id',
            'nome_cliente',
            'numero_processo',
            'tipo_compromisso',
            'local',
            'status',
            'prazo_fatal',
            'observacoes'
        ] as $campo) {
            if (!in_array($campo, $colunas, true)) {
                continue;
            }

            $where[] = "ag.`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        if ($temClientes) {
            $where[] = 'c.nome LIKE ?';
            $params[] = $like;
            $types .= 's';
        }

        if ($temAdvogados) {
            $where[] = 'adv.nome LIKE ?';
            $params[] = $like;
            $types .= 's';
        }

        if ($where === []) {
            return [];
        }

        $select = [
            'ag.id',
            in_array('data_evento', $colunas, true) ? 'ag.data_evento' : "NULL AS data_evento",
            in_array('horario', $colunas, true) ? 'ag.horario' : "NULL AS horario",
            in_array('tipo_compromisso', $colunas, true) ? 'ag.tipo_compromisso' : "'' AS tipo_compromisso",
            in_array('cliente_id', $colunas, true) ? 'ag.cliente_id' : "NULL AS cliente_id",
            $temClientes ? "COALESCE(NULLIF(ag.nome_cliente,''), c.nome, '') AS cliente_nome" : (in_array('nome_cliente', $colunas, true) ? "COALESCE(ag.nome_cliente,'') AS cliente_nome" : "'' AS cliente_nome"),
            in_array('numero_processo', $colunas, true) ? 'ag.numero_processo' : "'' AS numero_processo",
            in_array('local', $colunas, true) ? 'ag.`local` AS local' : "'' AS local",
            in_array('advogado_id', $colunas, true) ? 'ag.advogado_id' : "NULL AS advogado_id",
            $temAdvogados ? "COALESCE(adv.nome, '') AS advogado_nome" : "'' AS advogado_nome",
            in_array('status', $colunas, true) ? 'ag.status' : "'' AS status",
            in_array('prazo_fatal', $colunas, true) ? 'ag.prazo_fatal' : "'' AS prazo_fatal",
            in_array('observacoes', $colunas, true) ? 'ag.observacoes' : "'' AS observacoes",
        ];

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(ag.deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 10, 100);

        $ordem = [];
        if (in_array('data_evento', $colunas, true)) {
            $ordem[] = "COALESCE(ag.data_evento, '2999-12-31') ASC";
        }
        if (in_array('horario', $colunas, true)) {
            $ordem[] = "COALESCE(ag.horario, '23:59:59') ASC";
        }
        $ordem[] = 'ag.id DESC';

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM agenda ag '
            . implode(' ', $joins)
            . ' WHERE (' . implode(' OR ', $where) . ')'
            . $filtroLixeira
            . ' ORDER BY ' . implode(', ', $ordem)
            . ' LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_agenda_por_periodo')) {
    /**
     * Retorna compromissos dentro de um período.
     */
    function rojex_kb_agenda_por_periodo(
        mysqli $conn,
        string $dataInicio,
        string $dataFim,
        int $limite = 50
    ): array {
        if (
            !rojex_kb_tabela_existe($conn, 'agenda')
            || !rojex_kb_coluna_existe($conn, 'agenda', 'data_evento')
        ) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'agenda');
        $temAdvogados = rojex_kb_tabela_existe($conn, 'advogados')
            && in_array('advogado_id', $colunas, true);

        $joinAdvogado = $temAdvogados
            ? 'LEFT JOIN advogados adv ON adv.id = ag.advogado_id'
            : '';

        $advogadoNome = $temAdvogados
            ? "COALESCE(adv.nome, '') AS advogado_nome"
            : "'' AS advogado_nome";

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(ag.deletado, 0) = 0'
            : '';

        $filtroCancelado = in_array('status', $colunas, true)
            ? " AND COALESCE(ag.status, '') <> 'Cancelado'"
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 50, 200);

        $sql = "SELECT
                    ag.id,
                    ag.data_evento,
                    ag.horario,
                    ag.tipo_compromisso,
                    ag.nome_cliente AS cliente_nome,
                    ag.numero_processo,
                    ag.`local` AS local,
                    {$advogadoNome},
                    ag.status,
                    ag.prazo_fatal,
                    ag.observacoes
                FROM agenda ag
                {$joinAdvogado}
                WHERE ag.data_evento BETWEEN ? AND ?
                {$filtroLixeira}
                {$filtroCancelado}
                ORDER BY ag.data_evento ASC, ag.horario ASC, ag.id DESC
                LIMIT {$limiteSeguro}";

        return rojex_kb_consultar(
            $conn,
            $sql,
            'ss',
            [$dataInicio, $dataFim]
        );
    }
}

if (!function_exists('rojex_kb_agenda_hoje')) {
    /**
     * Retorna compromissos ativos do dia informado.
     */
    function rojex_kb_agenda_hoje(
        mysqli $conn,
        ?string $data = null,
        int $limite = 50
    ): array {
        $dataConsulta = $data ?: date('Y-m-d');

        return rojex_kb_agenda_por_periodo(
            $conn,
            $dataConsulta,
            $dataConsulta,
            $limite
        );
    }
}

if (!function_exists('rojex_kb_resumo_agenda')) {
    /**
     * Retorna indicadores básicos da agenda.
     */
    function rojex_kb_resumo_agenda(
        mysqli $conn,
        ?string $dataReferencia = null
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'agenda')) {
            return [
                'total' => 0,
                'hoje' => 0,
                'proximos_7_dias' => 0,
                'prazos_fatais' => 0,
            ];
        }

        $dataReferencia = $dataReferencia ?: date('Y-m-d');
        $dataFim = date('Y-m-d', strtotime($dataReferencia . ' +7 days'));
        $colunas = rojex_kb_colunas_tabela($conn, 'agenda');

        $whereBase = in_array('deletado', $colunas, true)
            ? ' WHERE COALESCE(deletado, 0) = 0'
            : ' WHERE 1 = 1';

        $total = (int)rojex_kb_total(
            $conn,
            'SELECT COUNT(*) AS total FROM agenda' . $whereBase
        );

        $hoje = 0;
        $proximos = 0;

        if (in_array('data_evento', $colunas, true)) {
            $hoje = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total FROM agenda'
                . $whereBase
                . ' AND data_evento = ?',
                's',
                [$dataReferencia]
            );

            $proximos = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total FROM agenda'
                . $whereBase
                . ' AND data_evento BETWEEN ? AND ?',
                'ss',
                [$dataReferencia, $dataFim]
            );
        }

        $prazosFatais = 0;
        if (in_array('prazo_fatal', $colunas, true)) {
            $sqlFatal = 'SELECT COUNT(*) AS total FROM agenda'
                . $whereBase
                . " AND prazo_fatal = 'Sim'";

            if (in_array('status', $colunas, true)) {
                $sqlFatal .= " AND status IN ('Pendente','Confirmado')";
            }

            $prazosFatais = (int)rojex_kb_total($conn, $sqlFatal);
        }

        return [
            'total' => $total,
            'hoje' => $hoje,
            'proximos_7_dias' => $proximos,
            'prazos_fatais' => $prazosFatais,
        ];
    }
}




if (!function_exists('rojex_kb_limpar_termo_honorario')) {
    /**
     * Remove palavras de comando comuns antes da pesquisa de honorários.
     */
    function rojex_kb_limpar_termo_honorario(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');

        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(honorário|honorario|honorários|honorarios|contrato|contratos|parcela|parcelas|localizar|buscar|pesquisar|procure|encontre|mostrar|mostre|qual|quais|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim((string)preg_replace('/\s+/u', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_honorarios_por_termo')) {
    /**
     * Pesquisa honorários por ID, cliente, processo, tipo, status,
     * forma de pagamento ou observações.
     */
    function rojex_kb_honorarios_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 10
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'honorarios')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '') {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_honorario($termoOriginal);
        if ($termoLimpo === '') {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'honorarios');
        if (!in_array('id', $colunas, true)) {
            return [];
        }

        $where = [];
        $params = [];
        $types = '';
        $like = '%' . $termoLimpo . '%';

        foreach ([
            'id',
            'cliente_id',
            'nome_cliente',
            'numero_processo',
            'tipo_honorario',
            'forma_pagamento',
            'status',
            'observacoes'
        ] as $campo) {
            if (!in_array($campo, $colunas, true)) {
                continue;
            }

            $where[] = "`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        if ($where === []) {
            return [];
        }

        $camposDesejados = [
            'id',
            'cliente_id',
            'nome_cliente',
            'numero_processo',
            'tipo_honorario',
            'valor_total',
            'qtd_parcelas',
            'valor_parcela',
            'data_vencimento',
            'forma_pagamento',
            'status',
            'valor_pago',
            'valor_pendente',
            'observacoes'
        ];

        $camposRetorno = array_values(array_intersect($camposDesejados, $colunas));

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 10, 100);

        $ordem = in_array('data_vencimento', $colunas, true)
            ? "COALESCE(data_vencimento, '2999-12-31') ASC, id DESC"
            : 'id DESC';

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $camposRetorno
        )) . '
                FROM honorarios
                WHERE (' . implode(' OR ', $where) . ')'
                . $filtroLixeira . '
                ORDER BY ' . $ordem . '
                LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_honorario_por_id')) {
    /**
     * Localiza um honorário pelo ID.
     */
    function rojex_kb_honorario_por_id(
        mysqli $conn,
        string $id
    ): ?array {
        $resultados = rojex_kb_honorarios_por_termo($conn, $id, 1);
        return $resultados[0] ?? null;
    }
}

if (!function_exists('rojex_kb_honorarios_vencidos')) {
    /**
     * Retorna honorários vencidos e ainda não quitados.
     */
    function rojex_kb_honorarios_vencidos(
        mysqli $conn,
        ?string $dataReferencia = null,
        int $limite = 50
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'honorarios')) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'honorarios');

        if (
            !in_array('data_vencimento', $colunas, true)
            || !in_array('status', $colunas, true)
        ) {
            return [];
        }

        $dataReferencia = $dataReferencia ?: date('Y-m-d');
        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 50, 200);

        $camposDesejados = [
            'id',
            'cliente_id',
            'nome_cliente',
            'numero_processo',
            'tipo_honorario',
            'valor_total',
            'data_vencimento',
            'status',
            'valor_pago',
            'valor_pendente'
        ];
        $camposRetorno = array_values(array_intersect($camposDesejados, $colunas));

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $camposRetorno
        )) . "
                FROM honorarios
                WHERE data_vencimento < ?
                  AND status NOT IN ('Pago','Quitada','Cancelado')"
                . $filtroLixeira . '
                ORDER BY data_vencimento ASC, id DESC
                LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar(
            $conn,
            $sql,
            's',
            [$dataReferencia]
        );
    }
}

if (!function_exists('rojex_kb_parcelas_honorario')) {
    /**
     * Retorna as parcelas de um honorário.
     */
    function rojex_kb_parcelas_honorario(
        mysqli $conn,
        string $honorarioId,
        int $limite = 120
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'honorarios_parcelas')) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'honorarios_parcelas');
        if (!in_array('honorario_id', $colunas, true)) {
            return [];
        }

        $camposDesejados = [
            'id',
            'honorario_id',
            'cliente_id',
            'nome_cliente',
            'numero_processo',
            'parcela_numero',
            'valor_parcela',
            'data_vencimento',
            'forma_pagamento',
            'status_pagamento',
            'valor_pago',
            'saldo_devedor',
            'observacoes'
        ];

        $camposRetorno = array_values(array_intersect($camposDesejados, $colunas));
        $limiteSeguro = rojex_kb_limite($limite, 120, 500);

        $ordem = in_array('parcela_numero', $colunas, true)
            ? 'parcela_numero ASC'
            : 'id ASC';

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn(string $campo): string => "`{$campo}`",
            $camposRetorno
        )) . '
                FROM honorarios_parcelas
                WHERE honorario_id = ?
                ORDER BY ' . $ordem . '
                LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar(
            $conn,
            $sql,
            's',
            [$honorarioId]
        );
    }
}

if (!function_exists('rojex_kb_resumo_honorarios')) {
    /**
     * Retorna indicadores básicos dos honorários.
     */
    function rojex_kb_resumo_honorarios(mysqli $conn): array
    {
        if (!rojex_kb_tabela_existe($conn, 'honorarios')) {
            return [
                'total' => 0,
                'pendentes' => 0,
                'valor_total' => 0.0,
                'valor_pago' => 0.0,
                'saldo_aberto' => 0.0,
                'vencidos' => 0,
            ];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'honorarios');
        $whereBase = in_array('deletado', $colunas, true)
            ? ' WHERE COALESCE(deletado, 0) = 0'
            : ' WHERE 1 = 1';

        $total = (int)rojex_kb_total(
            $conn,
            'SELECT COUNT(*) AS total FROM honorarios' . $whereBase
        );

        $pendentes = 0;
        if (in_array('status', $colunas, true)) {
            $pendentes = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total
                 FROM honorarios'
                . $whereBase
                . " AND status IN ('Pendente','Parcial')"
            );
        }

        $valorTotal = in_array('valor_total', $colunas, true)
            ? rojex_kb_total(
                $conn,
                'SELECT COALESCE(SUM(valor_total), 0) AS total
                 FROM honorarios' . $whereBase
            )
            : 0.0;

        $valorPago = in_array('valor_pago', $colunas, true)
            ? rojex_kb_total(
                $conn,
                'SELECT COALESCE(SUM(valor_pago), 0) AS total
                 FROM honorarios' . $whereBase
            )
            : 0.0;

        $saldoAberto = in_array('valor_pendente', $colunas, true)
            ? rojex_kb_total(
                $conn,
                'SELECT COALESCE(SUM(valor_pendente), 0) AS total
                 FROM honorarios' . $whereBase
            )
            : 0.0;

        $vencidos = 0;
        if (
            in_array('data_vencimento', $colunas, true)
            && in_array('status', $colunas, true)
        ) {
            $vencidos = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total
                 FROM honorarios'
                . $whereBase
                . " AND data_vencimento < CURDATE()
                    AND status NOT IN ('Pago','Quitada','Cancelado')"
            );
        }

        return [
            'total' => $total,
            'pendentes' => $pendentes,
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'saldo_aberto' => $saldoAberto,
            'vencidos' => $vencidos,
        ];
    }
}




if (!function_exists('rojex_kb_limpar_termo_documento')) {
    /**
     * Remove palavras de comando comuns antes da pesquisa de documentos.
     */
    function rojex_kb_limpar_termo_documento(string $termo): string
    {
        $limpo = mb_strtolower(trim($termo), 'UTF-8');

        if ($limpo === '') {
            return '';
        }

        $limpo = preg_replace(
            '/\b(documento|documentos|arquivo|arquivos|mostrar|mostre|localizar|buscar|pesquisar|procure|encontre|qual|quais|rojex|ai)\b/iu',
            ' ',
            $limpo
        );

        return trim((string)preg_replace('/\s+/u', ' ', (string)$limpo));
    }
}

if (!function_exists('rojex_kb_documentos_por_termo')) {
    /**
     * Pesquisa documentos por código, título, categoria, cliente, processo,
     * descrição, nome original, extensão, MIME, usuário ou status.
     */
    function rojex_kb_documentos_por_termo(
        mysqli $conn,
        string $termo,
        int $limite = 10
    ): array {
        if (!rojex_kb_tabela_existe($conn, 'documentos_arquivos')) {
            return [];
        }

        $termoOriginal = trim($termo);
        if ($termoOriginal === '') {
            return [];
        }

        $termoLimpo = rojex_kb_limpar_termo_documento($termoOriginal);
        if ($termoLimpo === '') {
            $termoLimpo = $termoOriginal;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'documentos_arquivos');
        if (!in_array('id', $colunas, true)) {
            return [];
        }

        $temClientes = rojex_kb_tabela_existe($conn, 'clientes')
            && in_array('cliente_id', $colunas, true);

        $joinCliente = $temClientes
            ? 'LEFT JOIN clientes c ON c.id = d.cliente_id'
            : '';

        $where = [];
        $params = [];
        $types = '';
        $like = '%' . $termoLimpo . '%';

        foreach ([
            'codigo',
            'titulo',
            'categoria',
            'cliente_id',
            'processo_id',
            'numero_processo',
            'descricao',
            'nome_original',
            'nome_arquivo',
            'extensao',
            'mime_type',
            'usuario_nome',
            'status'
        ] as $campo) {
            if (!in_array($campo, $colunas, true)) {
                continue;
            }

            $where[] = "d.`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        if ($temClientes) {
            $where[] = 'c.nome LIKE ?';
            $params[] = $like;
            $types .= 's';
        }

        if ($where === []) {
            return [];
        }

        $select = [
            'd.id',
            in_array('codigo', $colunas, true) ? 'd.codigo' : "'' AS codigo",
            in_array('titulo', $colunas, true) ? 'd.titulo' : "'' AS titulo",
            in_array('categoria', $colunas, true) ? 'd.categoria' : "'' AS categoria",
            in_array('cliente_id', $colunas, true) ? 'd.cliente_id' : "NULL AS cliente_id",
            $temClientes ? "COALESCE(c.nome, '') AS cliente_nome" : "'' AS cliente_nome",
            in_array('processo_id', $colunas, true) ? 'd.processo_id' : "NULL AS processo_id",
            in_array('numero_processo', $colunas, true) ? 'd.numero_processo' : "'' AS numero_processo",
            in_array('descricao', $colunas, true) ? 'd.descricao' : "'' AS descricao",
            in_array('nome_original', $colunas, true) ? 'd.nome_original' : "'' AS nome_original",
            in_array('nome_arquivo', $colunas, true) ? 'd.nome_arquivo' : "'' AS nome_arquivo",
            in_array('caminho', $colunas, true) ? 'd.caminho' : "'' AS caminho",
            in_array('extensao', $colunas, true) ? 'd.extensao' : "'' AS extensao",
            in_array('mime_type', $colunas, true) ? 'd.mime_type' : "'' AS mime_type",
            in_array('tamanho_bytes', $colunas, true) ? 'd.tamanho_bytes' : '0 AS tamanho_bytes',
            in_array('usuario_id', $colunas, true) ? 'd.usuario_id' : 'NULL AS usuario_id',
            in_array('usuario_nome', $colunas, true) ? 'd.usuario_nome' : "'' AS usuario_nome",
            in_array('status', $colunas, true) ? 'd.status' : "'' AS status",
            in_array('criado_em', $colunas, true) ? 'd.criado_em' : 'NULL AS criado_em',
        ];

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(d.deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 10, 100);

        $ordem = in_array('criado_em', $colunas, true)
            ? 'd.criado_em DESC, d.id DESC'
            : 'd.id DESC';

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM documentos_arquivos d '
            . $joinCliente
            . ' WHERE (' . implode(' OR ', $where) . ')'
            . $filtroLixeira
            . ' ORDER BY ' . $ordem
            . ' LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_documento_por_id')) {
    /**
     * Localiza um documento pelo ID numérico.
     */
    function rojex_kb_documento_por_id(
        mysqli $conn,
        int $id
    ): ?array {
        if ($id <= 0 || !rojex_kb_tabela_existe($conn, 'documentos_arquivos')) {
            return null;
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'documentos_arquivos');
        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        return rojex_kb_consultar_um(
            $conn,
            'SELECT *
             FROM documentos_arquivos
             WHERE id = ?'
             . $filtroLixeira
             . ' LIMIT 1',
            'i',
            [$id]
        );
    }
}

if (!function_exists('rojex_kb_documentos_por_cliente')) {
    /**
     * Retorna documentos ativos vinculados a um cliente.
     */
    function rojex_kb_documentos_por_cliente(
        mysqli $conn,
        string $clienteId,
        int $limite = 100
    ): array {
        if (
            $clienteId === ''
            || !rojex_kb_tabela_existe($conn, 'documentos_arquivos')
            || !rojex_kb_coluna_existe($conn, 'documentos_arquivos', 'cliente_id')
        ) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'documentos_arquivos');
        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 100, 500);

        return rojex_kb_consultar(
            $conn,
            'SELECT *
             FROM documentos_arquivos
             WHERE cliente_id = ?'
             . $filtroLixeira
             . ' ORDER BY criado_em DESC, id DESC
                LIMIT ' . $limiteSeguro,
            's',
            [$clienteId]
        );
    }
}

if (!function_exists('rojex_kb_documentos_por_processo')) {
    /**
     * Retorna documentos ativos vinculados a um processo ou número de processo.
     */
    function rojex_kb_documentos_por_processo(
        mysqli $conn,
        string $processo,
        int $limite = 100
    ): array {
        if (
            trim($processo) === ''
            || !rojex_kb_tabela_existe($conn, 'documentos_arquivos')
        ) {
            return [];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'documentos_arquivos');
        $where = [];
        $params = [];
        $types = '';

        if (in_array('processo_id', $colunas, true)) {
            $where[] = 'processo_id = ?';
            $params[] = $processo;
            $types .= 's';
        }

        if (in_array('numero_processo', $colunas, true)) {
            $where[] = 'numero_processo LIKE ?';
            $params[] = '%' . $processo . '%';
            $types .= 's';
        }

        if ($where === []) {
            return [];
        }

        $filtroLixeira = in_array('deletado', $colunas, true)
            ? ' AND COALESCE(deletado, 0) = 0'
            : '';

        $limiteSeguro = rojex_kb_limite($limite, 100, 500);

        $sql = 'SELECT *
                FROM documentos_arquivos
                WHERE (' . implode(' OR ', $where) . ')'
                . $filtroLixeira
                . ' ORDER BY criado_em DESC, id DESC
                    LIMIT ' . $limiteSeguro;

        return rojex_kb_consultar($conn, $sql, $types, $params);
    }
}

if (!function_exists('rojex_kb_resumo_documentos')) {
    /**
     * Retorna indicadores básicos dos documentos.
     */
    function rojex_kb_resumo_documentos(mysqli $conn): array
    {
        if (!rojex_kb_tabela_existe($conn, 'documentos_arquivos')) {
            return [
                'total' => 0,
                'enviados_mes' => 0,
                'provas' => 0,
                'armazenamento_bytes' => 0,
            ];
        }

        $colunas = rojex_kb_colunas_tabela($conn, 'documentos_arquivos');
        $whereBase = in_array('deletado', $colunas, true)
            ? ' WHERE COALESCE(deletado, 0) = 0'
            : ' WHERE 1 = 1';

        $total = (int)rojex_kb_total(
            $conn,
            'SELECT COUNT(*) AS total
             FROM documentos_arquivos'
             . $whereBase
        );

        $enviadosMes = 0;
        if (in_array('criado_em', $colunas, true)) {
            $enviadosMes = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total
                 FROM documentos_arquivos'
                 . $whereBase
                 . ' AND YEAR(criado_em) = YEAR(CURDATE())
                     AND MONTH(criado_em) = MONTH(CURDATE())'
            );
        }

        $provas = 0;
        if (in_array('categoria', $colunas, true)) {
            $provas = (int)rojex_kb_total(
                $conn,
                'SELECT COUNT(*) AS total
                 FROM documentos_arquivos'
                 . $whereBase
                 . " AND categoria = 'Prova'"
            );
        }

        $armazenamento = 0;
        if (in_array('tamanho_bytes', $colunas, true)) {
            $armazenamento = (int)rojex_kb_total(
                $conn,
                'SELECT COALESCE(SUM(tamanho_bytes), 0) AS total
                 FROM documentos_arquivos'
                 . $whereBase
            );
        }

        return [
            'total' => $total,
            'enviados_mes' => $enviadosMes,
            'provas' => $provas,
            'armazenamento_bytes' => $armazenamento,
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