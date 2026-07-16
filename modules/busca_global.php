<?php
/**
 * Fase 3.7 — Busca Global Inteligente
 * Pesquisa integrada em clientes, advogados, processos, agenda, honorários,
 * financeiro, recibos, documentos e modelos jurídicos.
 */
$conn = conectar();

if (!function_exists('rojexContextoTenantValido') || !rojexContextoTenantValido()) {
    $conn->close();
    throw new RuntimeException('Contexto Multi-Tenant inválido para a Busca Global.');
}

$tenantId = function_exists('rojexTenantId')
    ? trim((string)rojexTenantId())
    : trim((string)($_SESSION['tenant_id'] ?? ''));

$escritorioId = function_exists('rojexEscritorioId')
    ? (int)rojexEscritorioId()
    : (int)($_SESSION['escritorio_id'] ?? 0);

if ($tenantId === '' || $escritorioId <= 0) {
    $conn->close();
    throw new RuntimeException('Tenant ou escritório não identificado para a Busca Global.');
}

if (!function_exists('h')) {
    function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('brlBusca')) {
    function brlBusca($valor): string { return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.'); }
}
if (!function_exists('dataBusca')) {
    function dataBusca($data): string {
        if (empty($data) || $data === '0000-00-00') return '-';
        $ts = strtotime((string)$data);
        return $ts ? date('d/m/Y', $ts) : '-';
    }
}

function sgl_bg_table_exists(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}
function sgl_bg_col_exists(mysqli $conn, string $table, string $col): bool {
    $safeTable = str_replace('`', '', $table);
    $safeCol = $conn->real_escape_string($col);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    return $res && $res->num_rows > 0;
}
function sgl_bg_has_del(mysqli $conn, string $table): bool { return sgl_bg_col_exists($conn, $table, 'deletado'); }
function sgl_bg_has_tenant_id(mysqli $conn, string $table): bool {
    return sgl_bg_col_exists($conn, $table, 'tenant_id');
}
function sgl_bg_has_escritorio_id(mysqli $conn, string $table): bool {
    return sgl_bg_col_exists($conn, $table, 'escritorio_id');
}
function sgl_bg_table_isolavel(mysqli $conn, string $table): bool {
    return sgl_bg_has_tenant_id($conn, $table) || sgl_bg_has_escritorio_id($conn, $table);
}
function sgl_bg_tenant_sql(mysqli $conn, string $alias, string $table): string {
    $sql = '';
    if (sgl_bg_has_tenant_id($conn, $table)) {
        $sql .= " AND {$alias}.tenant_id = ?";
    }
    if (sgl_bg_has_escritorio_id($conn, $table)) {
        $sql .= " AND {$alias}.escritorio_id = ?";
    }
    return $sql;
}
function sgl_bg_add_tenant_params(mysqli $conn, string $table, array &$params, string &$types, string $tenantId, int $escritorioId): void {
    if (sgl_bg_has_tenant_id($conn, $table)) {
        $params[] = $tenantId;
        $types .= 's';
    }
    if (sgl_bg_has_escritorio_id($conn, $table)) {
        $params[] = $escritorioId;
        $types .= 'i';
    }
}
function sgl_bg_bind_tenant_direto(
    mysqli $conn,
    mysqli_stmt $stmt,
    string $table,
    string $tenantId,
    int $escritorioId
): void {
    $temTenant = sgl_bg_has_tenant_id($conn, $table);
    $temEscritorio = sgl_bg_has_escritorio_id($conn, $table);

    if ($temTenant && $temEscritorio) {
        $stmt->bind_param('si', $tenantId, $escritorioId);
    } elseif ($temTenant) {
        $stmt->bind_param('s', $tenantId);
    } elseif ($temEscritorio) {
        $stmt->bind_param('i', $escritorioId);
    }
}
function sgl_bg_limit(): int { return 8; }
function sgl_bg_like(string $q): string { return '%' . $q . '%'; }
function sgl_bg_digits(string $q): string { return preg_replace('/\D+/', '', $q); }
function sgl_bg_sql_digits(string $expr): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$expr}, '.', ''), '-', ''), '/', ''), '(', ''), ')', ''), ' ', ''), '+', '')";
}
function sgl_bg_add_digits_conditions(mysqli $conn, string $table, string $alias, array $cols, string &$where, array &$params, string &$types, string $digits): void {
    if ($digits === '') return;
    $likeDigits = '%' . $digits . '%';
    foreach ($cols as $col) {
        if (sgl_bg_col_exists($conn, $table, $col)) {
            $where .= " OR " . sgl_bg_sql_digits("{$alias}.`{$col}`") . " LIKE ?";
            $params[] = $likeDigits;
            $types .= 's';
        }
    }
}
function sgl_bg_and_deletado(mysqli $conn, string $alias, string $table): string {
    return sgl_bg_has_del($conn, $table) ? " AND COALESCE({$alias}.deletado,0)=0" : '';
}
function sgl_bg_bind_like(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types !== '') $stmt->bind_param($types, ...$params);
}
function sgl_bg_fetch(mysqli_stmt $stmt): array {
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}


function sgl_bg_cliente_item(array $r, string $q): array {
    return [
        'icone'=>'bi-person-lines-fill','cor'=>'primary','titulo'=>$r['nome'] ?: $r['id'],
        'subtitulo'=>'Cliente • ' . (($r['cpf_cnpj'] ?? '') ?: 'CPF/CNPJ não informado'),
        'detalhe'=>trim((($r['cidade'] ?? '') . '/' . ($r['estado'] ?? '')), '/') . ' • ' . (($r['telefone'] ?? '') ?: ($r['celular'] ?? '') ?: ($r['whatsapp'] ?? '') ?: ($r['email'] ?? '') ?: 'Sem contato'),
        'link'=>'?mod=clientes&busca=' . urlencode(($r['nome'] ?? '') ?: (($r['cpf_cnpj'] ?? '') ?: (($r['id'] ?? '') ?: $q))),
        'badge'=>$r['status'] ?? 'Cliente'
    ];
}

function sgl_bg_norm_php(?string $valor): string {
    return preg_replace('/\D+/', '', (string)$valor);
}

$q = trim((string)(
    $_GET['q'] ??
    $_GET['busca'] ??
    $_GET['pesquisa'] ??
    $_GET['termo'] ??
    $_POST['q'] ??
    $_POST['busca'] ??
    $_POST['pesquisa'] ??
    $_POST['termo'] ??
    ''
));
$like = sgl_bg_like($q);
$digits = sgl_bg_digits($q);
$resultados = [];
$totalGeral = 0;

if ($q !== '') {

    // Busca direta por CPF/CNPJ/telefone antes da busca comum.
    // Motivo: alguns cadastros armazenam CPF/CNPJ com máscara e o MySQL pode falhar na comparação dependendo do formato enviado pelo campo global.
    if ($digits !== '' && strlen($digits) >= 5
        && sgl_bg_table_exists($conn, 'clientes')
        && sgl_bg_table_isolavel($conn, 'clientes')) {
        $sqlCpfDireto = "SELECT c.id, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.whatsapp, c.email, c.cidade, c.estado, c.status
                         FROM clientes c
                         WHERE 1=1" . sgl_bg_tenant_sql($conn, 'c', 'clientes') . sgl_bg_and_deletado($conn, 'c', 'clientes') . "
                         ORDER BY c.nome ASC
                         LIMIT 5000";
        $stmtCpfDireto = $conn->prepare($sqlCpfDireto);
        sgl_bg_bind_tenant_direto($conn, $stmtCpfDireto, 'clientes', $tenantId, $escritorioId);
        $stmtCpfDireto->execute();
        $resCpfDireto = $stmtCpfDireto->get_result();
        $idsClientesJaAdicionados = [];
        if ($resCpfDireto) {
            while ($rCpf = $resCpfDireto->fetch_assoc()) {
                $cpfBanco = sgl_bg_norm_php($rCpf['cpf_cnpj'] ?? '');
                $telBanco = sgl_bg_norm_php($rCpf['telefone'] ?? '');
                $celBanco = sgl_bg_norm_php($rCpf['celular'] ?? '');
                $zapBanco = sgl_bg_norm_php($rCpf['whatsapp'] ?? '');

                $achouCpf = (
                    ($cpfBanco !== '' && (str_contains($cpfBanco, $digits) || str_contains($digits, $cpfBanco))) ||
                    ($telBanco !== '' && str_contains($telBanco, $digits)) ||
                    ($celBanco !== '' && str_contains($celBanco, $digits)) ||
                    ($zapBanco !== '' && str_contains($zapBanco, $digits))
                );

                if ($achouCpf) {
                    $idClienteBusca = (string)($rCpf['id'] ?? '');
                    if ($idClienteBusca !== '' && !isset($idsClientesJaAdicionados[$idClienteBusca])) {
                        $resultados['Clientes'][] = sgl_bg_cliente_item($rCpf, $q);
                        $idsClientesJaAdicionados[$idClienteBusca] = true;
                        $totalGeral++;
                    }
                }

                if (!empty($resultados['Clientes']) && count($resultados['Clientes']) >= sgl_bg_limit()) {
                    break;
                }
            }
        }
    }
    // Clientes
    if (sgl_bg_table_exists($conn, 'clientes') && sgl_bg_table_isolavel($conn, 'clientes')) {
        $where = "(c.id LIKE ? OR c.nome LIKE ?";
        $params = [$like, $like]; $types = 'ss';
        foreach (['cpf_cnpj','telefone','celular','whatsapp','email','cidade'] as $col) {
            if (sgl_bg_col_exists($conn, 'clientes', $col)) { $where .= " OR c.`{$col}` LIKE ?"; $params[] = $like; $types .= 's'; }
        }
        sgl_bg_add_digits_conditions($conn, 'clientes', 'c', ['cpf_cnpj','telefone','celular','whatsapp'], $where, $params, $types, $digits);
        $where .= ')';
        $sql = "SELECT c.id, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.whatsapp, c.email, c.cidade, c.estado, c.status
                FROM clientes c WHERE {$where}" . sgl_bg_tenant_sql($conn, 'c', 'clientes') . sgl_bg_and_deletado($conn, 'c', 'clientes') . " ORDER BY c.nome ASC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'clientes', $params, $types, $tenantId, $escritorioId);
        $stmt = $conn->prepare($sql); sgl_bg_bind_like($stmt, $types, $params);
        $rows = sgl_bg_fetch($stmt);
        foreach ($rows as $r) {
            $resultados['Clientes'][] = sgl_bg_cliente_item($r, $q);
            $totalGeral++;
        }

        // Reforço para CPF/CNPJ/telefone: filtra também pelo PHP, evitando falhas por máscara, pontos, barras ou formato salvo no banco.
        if ($digits !== '' && strlen($digits) >= 5 && empty($resultados['Clientes'])) {
            $sqlFallback = "SELECT c.id, c.nome, c.cpf_cnpj, c.telefone, c.celular, c.whatsapp, c.email, c.cidade, c.estado, c.status
                            FROM clientes c
                            WHERE 1=1" . sgl_bg_tenant_sql($conn, 'c', 'clientes') . sgl_bg_and_deletado($conn, 'c', 'clientes') . "
                            ORDER BY c.nome ASC LIMIT 1000";
            $stmtFallback = $conn->prepare($sqlFallback);
            sgl_bg_bind_tenant_direto($conn, $stmtFallback, 'clientes', $tenantId, $escritorioId);
            $stmtFallback->execute();
            $resFallback = $stmtFallback->get_result();
            if ($resFallback) {
                while ($r = $resFallback->fetch_assoc()) {
                    $camposNumericos = [
                        sgl_bg_norm_php($r['cpf_cnpj'] ?? ''),
                        sgl_bg_norm_php($r['telefone'] ?? ''),
                        sgl_bg_norm_php($r['celular'] ?? ''),
                        sgl_bg_norm_php($r['whatsapp'] ?? ''),
                    ];
                    foreach ($camposNumericos as $campoNum) {
                        if ($campoNum !== '' && str_contains($campoNum, $digits)) {
                            $resultados['Clientes'][] = sgl_bg_cliente_item($r, $q);
                            $totalGeral++;
                            break;
                        }
                    }
                    if (count($resultados['Clientes']) >= sgl_bg_limit()) {
                        break;
                    }
                }
            }
            if (isset($stmtFallback) && $stmtFallback instanceof mysqli_stmt) {
                $stmtFallback->close();
            }
        }
    }

    // Advogados
    if (sgl_bg_table_exists($conn, 'advogados') && sgl_bg_table_isolavel($conn, 'advogados')) {
        $where = "(a.id LIKE ? OR a.nome LIKE ?"; $params = [$like,$like]; $types='ss';
        foreach (['cpf','oab','oab_uf','telefone','celular','email','especialidade'] as $col) {
            if (sgl_bg_col_exists($conn, 'advogados', $col)) { $where .= " OR a.`{$col}` LIKE ?"; $params[]=$like; $types.='s'; }
        }
        sgl_bg_add_digits_conditions($conn, 'advogados', 'a', ['cpf','oab','telefone','celular'], $where, $params, $types, $digits);
        $where .= ')';
        $sql = "SELECT a.* FROM advogados a WHERE {$where}" . sgl_bg_tenant_sql($conn, 'a', 'advogados') . sgl_bg_and_deletado($conn, 'a', 'advogados') . " ORDER BY a.nome ASC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'advogados', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach ($rows as $r) { $resultados['Advogados'][] = [
            'icone'=>'bi-person-badge','cor'=>'info','titulo'=>$r['nome'] ?? $r['id'],
            'subtitulo'=>'Advogado • OAB ' . (($r['oab'] ?? '') ?: '-') . (($r['oab_uf'] ?? '') ? '/' . $r['oab_uf'] : ''),
            'detalhe'=>(($r['especialidade'] ?? '') ?: 'Especialidade não informada') . ' • ' . (($r['telefone'] ?? '') ?: ($r['celular'] ?? '') ?: ($r['email'] ?? 'Sem contato')),
            'link'=>'?mod=advogados&busca=' . urlencode($r['nome'] ?? $r['id']),
            'badge'=>$r['status'] ?? 'Advogado'
        ]; $totalGeral++; }
    }

    // Processos
    if (sgl_bg_table_exists($conn, 'processos') && sgl_bg_table_isolavel($conn, 'processos')) {
        $selectCliente = sgl_bg_table_exists($conn,'clientes')
            ? "LEFT JOIN clientes c ON c.id = p.cliente_id"
                . (sgl_bg_has_tenant_id($conn, 'clientes') && sgl_bg_has_tenant_id($conn, 'processos')
                    && sgl_bg_has_escritorio_id($conn, 'clientes') && sgl_bg_has_escritorio_id($conn, 'processos')
                    ? " AND c.tenant_id = p.tenant_id AND c.escritorio_id = p.escritorio_id"
                    : "")
            : "";
        $clienteNome = sgl_bg_table_exists($conn,'clientes') ? "COALESCE(c.nome,'-')" : "'-'";
        $where = "(p.id LIKE ?"; $params=[$like]; $types='s';
        foreach (['numero_processo','num_processo','tipo_processo','tipo','fase','status','comarca','vara','observacoes'] as $col) {
            if (sgl_bg_col_exists($conn,'processos',$col)) { $where .= " OR p.`{$col}` LIKE ?"; $params[]=$like; $types.='s'; }
        }
        sgl_bg_add_digits_conditions($conn, 'processos', 'p', ['numero_processo','num_processo'], $where, $params, $types, $digits);
        if (sgl_bg_table_exists($conn,'clientes')) { $where .= " OR c.nome LIKE ?"; $params[]=$like; $types.='s'; }
        $where .= ')';
        $numExpr = sgl_bg_col_exists($conn,'processos','numero_processo') ? 'p.numero_processo' : (sgl_bg_col_exists($conn,'processos','num_processo') ? 'p.num_processo' : 'p.id');
        $tipoExpr = sgl_bg_col_exists($conn,'processos','tipo_processo') ? 'p.tipo_processo' : (sgl_bg_col_exists($conn,'processos','tipo') ? 'p.tipo' : "''");
        $faseExpr = sgl_bg_col_exists($conn,'processos','fase') ? 'p.fase' : "''";
        $statusExpr = sgl_bg_col_exists($conn,'processos','status') ? 'p.status' : "''";
        $comarcaExpr = sgl_bg_col_exists($conn,'processos','comarca') ? 'p.comarca' : "''";
        $sql="SELECT p.id, {$numExpr} AS numero, {$tipoExpr} AS tipo, {$faseExpr} AS fase, {$statusExpr} AS status, {$comarcaExpr} AS comarca, {$clienteNome} AS cliente_nome FROM processos p {$selectCliente} WHERE {$where}" . sgl_bg_tenant_sql($conn,'p','processos') . sgl_bg_and_deletado($conn,'p','processos') . " ORDER BY p.id DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'processos', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Processos'][]=[
            'icone'=>'bi-folder2-open','cor'=>'warning','titulo'=>'Processo ' . ($r['numero'] ?: $r['id']),
            'subtitulo'=>'Cliente: ' . ($r['cliente_nome'] ?: '-'),
            'detalhe'=>trim(($r['tipo'] ?: 'Tipo não informado') . ' • ' . ($r['fase'] ?: 'Fase não informada') . ' • ' . ($r['comarca'] ?: 'Comarca não informada'), ' •'),
            'link'=>'?mod=processos&busca=' . urlencode($r['numero'] ?: $q),
            'badge'=>$r['status'] ?: 'Processo'
        ]; $totalGeral++; }
    }

    // Agenda
    if (sgl_bg_table_exists($conn, 'agenda') && sgl_bg_table_isolavel($conn, 'agenda')) {
        $where="(ag.id LIKE ?"; $params=[$like]; $types='s';
        foreach(['titulo','compromisso','descricao','tipo','local','status','numero_processo'] as $col){ if(sgl_bg_col_exists($conn,'agenda',$col)){ $where.=" OR ag.`{$col}` LIKE ?"; $params[]=$like; $types.='s'; }}
        $where.=')';
        $tituloExpr = sgl_bg_col_exists($conn,'agenda','titulo') ? 'ag.titulo' : (sgl_bg_col_exists($conn,'agenda','compromisso') ? 'ag.compromisso' : "'Compromisso'");
        $dataExpr = sgl_bg_col_exists($conn,'agenda','data') ? 'ag.data' : (sgl_bg_col_exists($conn,'agenda','data_evento') ? 'ag.data_evento' : 'NULL');
        $horaExpr = sgl_bg_col_exists($conn,'agenda','hora') ? 'ag.hora' : (sgl_bg_col_exists($conn,'agenda','hora_inicio') ? 'ag.hora_inicio' : 'NULL');
        $tipoExpr = sgl_bg_col_exists($conn,'agenda','tipo') ? 'ag.tipo' : "''";
        $statusExpr = sgl_bg_col_exists($conn,'agenda','status') ? 'ag.status' : "''";
        $sql="SELECT ag.id, {$tituloExpr} AS titulo, {$dataExpr} AS data_evt, {$horaExpr} AS hora_evt, {$tipoExpr} AS tipo, {$statusExpr} AS status FROM agenda ag WHERE {$where}" . sgl_bg_tenant_sql($conn,'ag','agenda') . sgl_bg_and_deletado($conn,'ag','agenda') . " ORDER BY data_evt DESC, hora_evt DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'agenda', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Agenda'][]=[
            'icone'=>'bi-calendar-event','cor'=>'success','titulo'=>$r['titulo'] ?: 'Compromisso',
            'subtitulo'=>'Agenda • ' . dataBusca($r['data_evt']) . (($r['hora_evt'] ?? '') ? ' às ' . substr((string)$r['hora_evt'],0,5) : ''),
            'detalhe'=>$r['tipo'] ?: 'Tipo não informado',
            'link'=>'?mod=agenda&busca=' . urlencode($r['titulo'] ?: $q),
            'badge'=>$r['status'] ?: 'Agenda'
        ]; $totalGeral++; }
    }

    // Honorários e parcelas
    if (sgl_bg_table_exists($conn, 'honorarios') && sgl_bg_table_isolavel($conn, 'honorarios')) {
        $joinCli = sgl_bg_table_exists($conn,'clientes')
            ? "LEFT JOIN clientes c ON c.id = h.cliente_id"
                . (sgl_bg_has_tenant_id($conn, 'clientes') && sgl_bg_has_tenant_id($conn, 'honorarios')
                    && sgl_bg_has_escritorio_id($conn, 'clientes') && sgl_bg_has_escritorio_id($conn, 'honorarios')
                    ? " AND c.tenant_id = h.tenant_id AND c.escritorio_id = h.escritorio_id"
                    : "")
            : "";
        $clienteNome = sgl_bg_table_exists($conn,'clientes') ? "COALESCE(c.nome,'-')" : "'-'";
        $where="(h.id LIKE ?"; $params=[$like]; $types='s';
        foreach(['descricao','status','tipo','forma_pagamento'] as $col){ if(sgl_bg_col_exists($conn,'honorarios',$col)){ $where.=" OR h.`{$col}` LIKE ?"; $params[]=$like; $types.='s'; }}
        if(sgl_bg_table_exists($conn,'clientes')){ $where.=" OR c.nome LIKE ?"; $params[]=$like; $types.='s'; }
        $where.=')';
        $valorExpr = sgl_bg_col_exists($conn,'honorarios','valor_total') ? 'h.valor_total' : (sgl_bg_col_exists($conn,'honorarios','valor') ? 'h.valor' : '0');
        $statusExpr = sgl_bg_col_exists($conn,'honorarios','status') ? 'h.status' : "''";
        $descExpr = sgl_bg_col_exists($conn,'honorarios','descricao') ? 'h.descricao' : "'Honorário'";
        $sql="SELECT h.id, {$descExpr} AS descricao, {$valorExpr} AS valor, {$statusExpr} AS status, {$clienteNome} AS cliente_nome FROM honorarios h {$joinCli} WHERE {$where}" . sgl_bg_tenant_sql($conn,'h','honorarios') . sgl_bg_and_deletado($conn,'h','honorarios') . " ORDER BY h.id DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'honorarios', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Honorários'][]=[
            'icone'=>'bi-cash-stack','cor'=>'success','titulo'=>$r['descricao'] ?: 'Honorário',
            'subtitulo'=>'Cliente: ' . ($r['cliente_nome'] ?: '-') . ' • ' . brlBusca($r['valor']),
            'detalhe'=>'Registro de honorários',
            'link'=>'?mod=honorarios&busca=' . urlencode($r['cliente_nome'] ?: $r['id']),
            'badge'=>$r['status'] ?: 'Honorário'
        ]; $totalGeral++; }
    }

    // Financeiro: contas receber/pagar
    foreach ([
        ['contas_receber','Contas a Receber','bi-arrow-down-circle','primary','cliente'],
        ['contas_pagar','Contas a Pagar','bi-arrow-up-circle','danger','fornecedor']
    ] as $cfg) {
        [$table,$label,$icon,$cor,$entidade] = $cfg;
        if (!sgl_bg_table_exists($conn,$table) || !sgl_bg_table_isolavel($conn,$table)) continue;
        $alias='f';
        $where="({$alias}.id LIKE ?"; $params=[$like]; $types='s';
        foreach(['codigo','descricao','categoria','fornecedor','cliente','status','forma_pagamento'] as $col){ if(sgl_bg_col_exists($conn,$table,$col)){ $where.=" OR {$alias}.`{$col}` LIKE ?"; $params[]=$like; $types.='s'; }}
        $where.=')';
        $codigoExpr=sgl_bg_col_exists($conn,$table,'codigo') ? "{$alias}.codigo" : "{$alias}.id";
        $descExpr=sgl_bg_col_exists($conn,$table,'descricao') ? "{$alias}.descricao" : "'Lançamento financeiro'";
        $valorExpr=sgl_bg_col_exists($conn,$table,'valor') ? "{$alias}.valor" : (sgl_bg_col_exists($conn,$table,'valor_total') ? "{$alias}.valor_total" : '0');
        $vencExpr=sgl_bg_col_exists($conn,$table,'data_vencimento') ? "{$alias}.data_vencimento" : (sgl_bg_col_exists($conn,$table,'vencimento') ? "{$alias}.vencimento" : 'NULL');
        $statusExpr=sgl_bg_col_exists($conn,$table,'status') ? "{$alias}.status" : "''";
        $sql="SELECT {$alias}.id, {$codigoExpr} AS codigo, {$descExpr} AS descricao, {$valorExpr} AS valor, {$vencExpr} AS vencimento, {$statusExpr} AS status FROM {$table} {$alias} WHERE {$where}" . sgl_bg_tenant_sql($conn,$alias,$table) . sgl_bg_and_deletado($conn,$alias,$table) . " ORDER BY {$alias}.id DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, $table, $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados[$label][]=[
            'icone'=>$icon,'cor'=>$cor,'titulo'=>($r['codigo'] ?: $r['id']) . ' • ' . ($r['descricao'] ?: 'Lançamento'),
            'subtitulo'=>$label . ' • ' . brlBusca($r['valor']),
            'detalhe'=>'Vencimento: ' . dataBusca($r['vencimento']),
            'link'=>'?mod=financeiro&q=' . urlencode($r['codigo'] ?: $q),
            'badge'=>$r['status'] ?: 'Financeiro'
        ]; $totalGeral++; }
    }

    // Recibos
    if (sgl_bg_table_exists($conn, 'recibos') && sgl_bg_table_isolavel($conn, 'recibos')) {
        $where="(r.id LIKE ? OR r.numero LIKE ? OR r.nome_cliente LIKE ? OR r.referente LIKE ? OR r.forma_pagamento LIKE ?";
        $params=[$like,$like,$like,$like,$like]; $types='sssss';
        if(sgl_bg_col_exists($conn,'recibos','chave_validacao')){ $where.=" OR r.chave_validacao LIKE ?"; $params[]=$like; $types.='s'; }
        sgl_bg_add_digits_conditions($conn, 'recibos', 'r', ['numero'], $where, $params, $types, $digits);
        $where.=')';
        $sql="SELECT r.id, r.numero, r.nome_cliente, r.referente, r.valor, r.data_emissao, r.status FROM recibos r WHERE {$where}" . sgl_bg_tenant_sql($conn,'r','recibos') . sgl_bg_and_deletado($conn,'r','recibos') . " ORDER BY r.data_emissao DESC, r.id DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'recibos', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Recibos'][]=[
            'icone'=>'bi-receipt','cor'=>'secondary','titulo'=>$r['numero'] ?: $r['id'],
            'subtitulo'=>'Cliente: ' . ($r['nome_cliente'] ?: '-') . ' • ' . brlBusca($r['valor']),
            'detalhe'=>($r['referente'] ?: 'Recibo') . ' • Emitido em ' . dataBusca($r['data_emissao']),
            'link'=>'?mod=recibos&acao=imprimir&id=' . urlencode($r['id']),
            'badge'=>$r['status'] ?: 'Recibo'
        ]; $totalGeral++; }
    }

    // Documentos
    if (sgl_bg_table_exists($conn, 'documentos_arquivos') && sgl_bg_table_isolavel($conn, 'documentos_arquivos')) {
        $joinCli = sgl_bg_table_exists($conn,'clientes')
            ? "LEFT JOIN clientes c ON c.id = d.cliente_id"
                . (sgl_bg_has_tenant_id($conn, 'clientes') && sgl_bg_has_tenant_id($conn, 'documentos_arquivos')
                    && sgl_bg_has_escritorio_id($conn, 'clientes') && sgl_bg_has_escritorio_id($conn, 'documentos_arquivos')
                    ? " AND c.tenant_id = d.tenant_id AND c.escritorio_id = d.escritorio_id"
                    : "")
            : "";
        $where="(d.codigo LIKE ? OR d.titulo LIKE ? OR d.categoria LIKE ? OR d.nome_original LIKE ? OR d.descricao LIKE ?";
        $params=[$like,$like,$like,$like,$like]; $types='sssss';
        if(sgl_bg_table_exists($conn,'clientes')){ $where.=" OR c.nome LIKE ?"; $params[]=$like; $types.='s'; }
        $where.=')';
        $clienteNome = sgl_bg_table_exists($conn,'clientes') ? "COALESCE(c.nome,'-')" : "'-'";
        $sql="SELECT d.id, d.codigo, d.titulo, d.categoria, d.nome_original, d.criado_em, {$clienteNome} AS cliente_nome FROM documentos_arquivos d {$joinCli} WHERE {$where}" . sgl_bg_tenant_sql($conn,'d','documentos_arquivos') . sgl_bg_and_deletado($conn,'d','documentos_arquivos') . " ORDER BY d.criado_em DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'documentos_arquivos', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Documentos'][]=[
            'icone'=>'bi-file-earmark-arrow-up','cor'=>'dark','titulo'=>($r['codigo'] ?: 'DOC') . ' • ' . ($r['titulo'] ?: $r['nome_original']),
            'subtitulo'=>'Cliente: ' . ($r['cliente_nome'] ?: '-') . ' • ' . ($r['categoria'] ?: 'Documento'),
            'detalhe'=>'Arquivo: ' . ($r['nome_original'] ?: '-') . ' • Enviado em ' . dataBusca($r['criado_em']),
            'link'=>'?mod=documentos&q=' . urlencode($r['titulo'] ?: $r['nome_original'] ?: $q),
            'badge'=>'Documento'
        ]; $totalGeral++; }
    }

    // Modelos
    if (sgl_bg_table_exists($conn, 'modelos_documentos') && sgl_bg_table_isolavel($conn, 'modelos_documentos')) {
        $where="(m.codigo LIKE ? OR m.titulo LIKE ? OR m.categoria LIKE ? OR m.area_direito LIKE ? OR m.conteudo LIKE ? OR m.observacoes LIKE ?)";
        $params=[$like,$like,$like,$like,$like,$like]; $types='ssssss';
        $sql="SELECT m.id, m.codigo, m.titulo, m.categoria, m.area_direito, m.status, m.atualizado_em FROM modelos_documentos m WHERE {$where}" . sgl_bg_tenant_sql($conn,'m','modelos_documentos') . sgl_bg_and_deletado($conn,'m','modelos_documentos') . " ORDER BY m.atualizado_em DESC LIMIT " . sgl_bg_limit();
        sgl_bg_add_tenant_params($conn, 'modelos_documentos', $params, $types, $tenantId, $escritorioId);
        $stmt=$conn->prepare($sql); sgl_bg_bind_like($stmt,$types,$params); $rows=sgl_bg_fetch($stmt);
        foreach($rows as $r){ $resultados['Modelos Jurídicos'][]=[
            'icone'=>'bi-journal-text','cor'=>'primary','titulo'=>($r['codigo'] ?: 'MOD') . ' • ' . ($r['titulo'] ?: 'Modelo'),
            'subtitulo'=>($r['categoria'] ?: 'Modelo') . ' • ' . ($r['area_direito'] ?: 'Área não informada'),
            'detalhe'=>'Atualizado em ' . dataBusca($r['atualizado_em']),
            'link'=>'?mod=modelos&q=' . urlencode($r['titulo'] ?: $q),
            'badge'=>$r['status'] ?: 'Modelo'
        ]; $totalGeral++; }
    }
}
?>

<style>
.busca-hero { background: linear-gradient(135deg,#0d3b66,#1e75bb); color:#fff!important; border-radius:18px; padding:24px; box-shadow:0 12px 30px rgba(13,59,102,.18); }
.busca-hero h1,.busca-hero h2,.busca-hero h3,.busca-hero p,.busca-hero div{color:#fff!important}
.busca-hero .opacity-75{opacity:.9!important}
.busca-hero .form-control { border-radius:14px; min-height:50px; }
.busca-hero .btn { border-radius:14px; }
.busca-card { border:0; border-radius:16px; box-shadow:0 6px 22px rgba(15,23,42,.06); overflow:hidden; }
.busca-card .card-header { background:#1f2428; color:#fff; font-weight:800; display:flex; justify-content:space-between; align-items:center; }
.busca-result { padding:14px 16px; border-bottom:1px solid #edf0f3; display:flex; gap:12px; align-items:flex-start; }
.busca-result:last-child { border-bottom:0; }
.busca-icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:#eef5ff; font-size:1.25rem; }
.busca-result h6 { margin:0; font-weight:800; color:#123a5a; }
.busca-result p { margin:2px 0; }
.busca-result:hover { background:#f8fbff; }
.busca-empty { background:#fff; border-radius:16px; padding:28px; box-shadow:0 6px 22px rgba(15,23,42,.06); }
</style>

<div class="busca-hero mb-4">
    <div class="row align-items-center g-3">
        <div class="col-lg-7">
            <h2 class="mb-1"><i class="bi bi-search"></i> Busca Global Inteligente</h2>
            <p class="mb-0 opacity-75">Localize rapidamente clientes, processos, documentos, recibos, honorários, agenda, financeiro, advogados e modelos jurídicos.</p>
        </div>
        <div class="col-lg-5 text-lg-end">
            <div class="small opacity-75">Use o campo de busca no topo para pesquisar em todo o sistema.</div>
            <?php if ($q !== ''): ?><div class="fw-bold mt-1">Busca atual: <?= h($q) ?></div><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($q === ''): ?>
    <div class="busca-empty">
        <h5 class="fw-bold mb-3"><i class="bi bi-lightbulb text-warning"></i> Como pesquisar</h5>
        <div class="row g-3">
            <div class="col-md-4"><div class="alert alert-primary mb-0"><strong>Cliente:</strong><br>nome, CPF/CNPJ, telefone ou cidade.</div></div>
            <div class="col-md-4"><div class="alert alert-warning mb-0"><strong>Processo:</strong><br>número, comarca, fase ou tipo.</div></div>
            <div class="col-md-4"><div class="alert alert-success mb-0"><strong>Financeiro:</strong><br>CR, CP, recibo, valor, PIX ou descrição.</div></div>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-0">Resultado para: <strong><?= h($q) ?></strong></h5>
            <div class="text-muted small"><?= (int)$totalGeral ?> resultado(s) encontrado(s) em todos os módulos.</div>
        </div>
        <a href="?mod=busca" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i> Limpar busca</a>
    </div>

    <?php if ($totalGeral === 0): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Nenhum resultado encontrado. Tente pesquisar por parte do nome, CPF, número do processo, código financeiro ou recibo.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($resultados as $grupo => $itens): if (!$itens) continue; ?>
                <div class="col-xl-6">
                    <div class="card busca-card h-100">
                        <div class="card-header"><span><i class="bi bi-collection"></i> <?= h($grupo) ?></span><span><?= count($itens) ?></span></div>
                        <div class="card-body p-0">
                            <?php foreach ($itens as $item): ?>
                                <a class="busca-result text-decoration-none text-reset" href="<?= h($item['link']) ?>">
                                    <div class="busca-icon text-<?= h($item['cor']) ?>"><i class="bi <?= h($item['icone']) ?>"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between gap-2">
                                            <h6><?= h($item['titulo']) ?></h6>
                                            <span class="badge text-bg-light border"><?= h($item['badge']) ?></span>
                                        </div>
                                        <p class="small text-muted fw-semibold"><?= h($item['subtitulo']) ?></p>
                                        <p class="small text-muted"><?= h($item['detalhe']) ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
