<?php
/**
 * modules/cij.php
 * Centro de Inteligência Jurídica — ROJEX.AI Enterprise
 * Sprint 3.3.3 — Assistente Jurídico funcional inicial.
 *
 * Sem conexão externa de IA e sem alteração de banco.
 * Esta versão consulta dados internos do ROJEX.AI com segurança e exibe respostas orientativas.
 */

$conn = conectar();

$arquivoBaseConhecimento = __DIR__ . '/../config/base_conhecimento.php';
if (is_file($arquivoBaseConhecimento)) {
    require_once $arquivoBaseConhecimento;
}

function cij_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_table_exists(mysqli $conn, string $tabela): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }

    $stmt = @$conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tabela);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['total'] ?? 0)) > 0;
}

function cij_column_exists(mysqli $conn, string $tabela, string $coluna): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela) || !preg_match('/^[a-zA-Z0-9_]+$/', $coluna)) {
        return false;
    }

    $stmt = @$conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['total'] ?? 0)) > 0;
}

function cij_count(mysqli $conn, string $sql): int
{
    try {
        $res = $conn->query($sql);
        if (!$res) {
            return 0;
        }
        $row = $res->fetch_assoc();
        return (int)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function cij_money(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function cij_sum(mysqli $conn, string $sql): float
{
    try {
        $res = $conn->query($sql);
        if (!$res) {
            return 0.0;
        }
        $row = $res->fetch_assoc();
        return (float)($row['total'] ?? 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function cij_rows(mysqli $conn, string $sql): array
{
    try {
        $res = $conn->query($sql);
        if (!$res) {
            return [];
        }

        $dados = [];
        while ($row = $res->fetch_assoc()) {
            $dados[] = $row;
        }
        return $dados;
    } catch (Throwable $e) {
        return [];
    }
}


function cij_only_digits(?string $valor): string
{
    return preg_replace('/\D+/', '', (string)($valor ?? ''));
}

function cij_cliente_por_documento(mysqli $conn, string $documento): ?array
{
    if (!function_exists('rojex_kb_cliente_por_documento')) {
        return null;
    }

    return rojex_kb_cliente_por_documento($conn, $documento);
}


function cij_cliente_por_nome(mysqli $conn, string $nome): array
{
    if (!function_exists('rojex_kb_clientes_por_termo')) {
        return [];
    }

    return rojex_kb_clientes_por_termo($conn, $nome, 5);
}


function cij_limpar_termo_consulta(string $texto, array $palavras): string
{
    $limpo = mb_strtolower(trim($texto), 'UTF-8');
    if ($limpo === '') {
        return '';
    }

    $padrao = '/\b(' . implode('|', array_map(static fn($p) => preg_quote($p, '/'), $palavras)) . ')\b/iu';
    $limpo = preg_replace($padrao, ' ', $limpo);
    return trim(preg_replace('/\s+/', ' ', (string)$limpo));
}

function cij_advogados_buscar(mysqli $conn, string $consulta): array
{
    if (!function_exists('rojex_kb_advogados_por_termo')) {
        return [];
    }

    return rojex_kb_advogados_por_termo($conn, $consulta, 5);
}

function cij_processos_buscar_numero(mysqli $conn, string $consulta): array
{
    if (!function_exists('rojex_kb_processos_por_termo')) {
        return [];
    }

    return rojex_kb_processos_por_termo($conn, $consulta, 5);
}


function cij_busca_livre_base(mysqli $conn, string $consulta): array
{
    $consulta = trim($consulta);
    if ($consulta === '') {
        return [];
    }

    $resultados = [];

    if (function_exists('rojex_kb_clientes_por_termo')) {
        foreach (rojex_kb_clientes_por_termo($conn, $consulta, 5) as $cliente) {
            $resultados[] = ['tipo' => 'cliente', 'dados' => $cliente];
        }
    }

    if (function_exists('rojex_kb_advogados_por_termo')) {
        foreach (rojex_kb_advogados_por_termo($conn, $consulta, 5) as $advogado) {
            $resultados[] = ['tipo' => 'advogado', 'dados' => $advogado];
        }
    }

    if (function_exists('rojex_kb_processos_por_termo')) {
        foreach (rojex_kb_processos_por_termo($conn, $consulta, 5) as $processo) {
            $resultados[] = ['tipo' => 'processo', 'dados' => $processo];
        }
    }

    if (function_exists('rojex_kb_agenda_por_termo')) {
        foreach (rojex_kb_agenda_por_termo($conn, $consulta, 5) as $compromisso) {
            $resultados[] = ['tipo' => 'agenda', 'dados' => $compromisso];
        }
    }

    if (function_exists('rojex_kb_honorarios_por_termo')) {
        foreach (rojex_kb_honorarios_por_termo($conn, $consulta, 5) as $honorario) {
            $resultados[] = ['tipo' => 'honorario', 'dados' => $honorario];
        }
    }

    if (function_exists('rojex_kb_documentos_por_termo')) {
        foreach (rojex_kb_documentos_por_termo($conn, $consulta, 5) as $documento) {
            $resultados[] = ['tipo' => 'documento', 'dados' => $documento];
        }
    }

    return $resultados;
}

function cij_assistente_responder(mysqli $conn, string $pergunta, string $atalho): array
{
    $hoje = date('Y-m-d');
    $seteDias = date('Y-m-d', strtotime('+7 days'));
    $inicioMes = date('Y-m-01');
    $fimMes = date('Y-m-t');

    $perguntaNormalizada = mb_strtolower(trim($pergunta . ' ' . $atalho), 'UTF-8');
    $resposta = [
        'titulo' => 'Resposta do Assistente Jurídico',
        'texto' => 'Ainda não identifiquei uma consulta específica. Use os botões rápidos ou pergunte sobre clientes, processos, agenda, financeiro ou honorários.',
        'itens' => [],
        'alerta' => 'Este assistente usa dados internos do sistema e ainda não está conectado a uma IA externa.',
        'tipo' => 'info',
        'acoes' => []
    ];


    $documentoDetectado = cij_only_digits($perguntaNormalizada);
    if (in_array(strlen($documentoDetectado), [11, 14], true)) {
        $clienteDoc = cij_cliente_por_documento($conn, $documentoDetectado);

        if ($clienteDoc) {
            $contato = $clienteDoc['whatsapp'] ?: ($clienteDoc['celular'] ?: ($clienteDoc['telefone'] ?: '-'));
            $cidadeUf = trim(($clienteDoc['cidade'] ?? '') . '/' . ($clienteDoc['estado'] ?? ''), '/');

            $resposta['titulo'] = 'Cliente localizado por CPF/CNPJ';
            $resposta['texto'] = 'Encontrei um cliente cadastrado com o documento informado.';
            $resposta['itens'][] = 'ID: ' . ($clienteDoc['id'] ?: '-');
            $resposta['itens'][] = 'Nome: ' . ($clienteDoc['nome'] ?: '-');
            $resposta['itens'][] = 'CPF/CNPJ: ' . ($clienteDoc['cpf_cnpj'] ?: '-');
            $resposta['itens'][] = 'Tipo: ' . ($clienteDoc['tipo_pessoa'] ?: '-');
            $resposta['itens'][] = 'Status: ' . ($clienteDoc['status'] ?: '-');
            $resposta['itens'][] = 'Contato: ' . $contato;
            $resposta['itens'][] = 'E-mail: ' . ($clienteDoc['email'] ?: '-');
            $resposta['itens'][] = 'Cidade/UF: ' . ($cidadeUf ?: '-');
            $resposta['alerta'] = 'Consulta realizada no cadastro interno de clientes do ROJEX.AI.';
            $resposta['tipo'] = 'success';
            $resposta['acoes'][] = ['label' => 'Abrir cadastro do cliente', 'url' => '?mod=clientes&acao=editar&id=' . urlencode((string)$clienteDoc['id']), 'class' => 'btn-primary', 'icon' => 'bi-person-lines-fill'];
            $resposta['acoes'][] = ['label' => 'Gerar peça para este cliente', 'url' => '?mod=cij&ferramenta=gerador', 'class' => 'btn-success', 'icon' => 'bi-magic'];
            $resposta['acoes'][] = ['label' => 'Ver lista de clientes', 'url' => '?mod=clientes&busca=' . urlencode((string)$clienteDoc['cpf_cnpj']), 'class' => 'btn-outline-secondary', 'icon' => 'bi-search'];
            return $resposta;
        }

        // O mesmo número pode pertencer ao CPF de um advogado. Antes de informar
        // que não há cliente, consulta também o cadastro de advogados.
        $advogadosDocumento = cij_advogados_buscar($conn, $pergunta);
        if (!empty($advogadosDocumento)) {
            $resposta['titulo'] = count($advogadosDocumento) === 1 ? 'Advogado localizado por CPF' : 'Advogados localizados por CPF';
            $resposta['texto'] = 'Encontrei advogado(s) compatíveis com o documento informado.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta realizada no cadastro interno de advogados do ROJEX.AI.';

            foreach ($advogadosDocumento as $adv) {
                $contato = ($adv['celular'] ?? '') ?: (($adv['telefone'] ?? '') ?: '-');
                $resposta['itens'][] = (($adv['nome'] ?? '') ?: '-')
                    . ' | ID: ' . (($adv['id'] ?? '') ?: '-')
                    . ' | OAB: ' . (($adv['oab'] ?? '') ?: '-')
                    . ' | CPF: ' . (($adv['cpf'] ?? '') ?: '-')
                    . ' | Contato: ' . $contato
                    . ' | E-mail: ' . (($adv['email'] ?? '') ?: '-');
            }

            $primeiro = $advogadosDocumento[0];
            $resposta['acoes'][] = ['label' => 'Abrir cadastro do advogado', 'url' => '?mod=advogados&acao=editar&id=' . urlencode((string)($primeiro['id'] ?? '')), 'class' => 'btn-primary', 'icon' => 'bi-person-badge'];
            $resposta['acoes'][] = ['label' => 'Ver lista de advogados', 'url' => '?mod=advogados', 'class' => 'btn-outline-secondary', 'icon' => 'bi-people'];
            return $resposta;
        }

        $resposta['titulo'] = 'Documento não localizado';
        $resposta['texto'] = 'Não encontrei cliente ou advogado ativo com o CPF/CNPJ informado.';
        $resposta['itens'][] = 'Documento pesquisado: ' . $documentoDetectado;
        $resposta['alerta'] = 'Confira se o cadastro está ativo e fora da lixeira.';
        $resposta['tipo'] = 'warning';
        $resposta['acoes'][] = ['label' => 'Pesquisar em clientes', 'url' => '?mod=clientes&busca=' . urlencode($documentoDetectado), 'class' => 'btn-outline-secondary', 'icon' => 'bi-search'];
        $resposta['acoes'][] = ['label' => 'Ver advogados cadastrados', 'url' => '?mod=advogados', 'class' => 'btn-outline-primary', 'icon' => 'bi-people'];
        return $resposta;
    }


    // Reconhece também OAB digitada diretamente, por exemplo: 85561/PR,
    // sem exigir que o usuário escreva a palavra "advogado" ou "OAB".
    $pareceOabDireta = preg_match('/\b\d{2,8}\s*[\/\-]?\s*[a-z]{2}\b/iu', trim($pergunta)) === 1;
    $consultaAdvogado = $atalho === '' && (
        str_contains($perguntaNormalizada, 'advogado') ||
        str_contains($perguntaNormalizada, 'advogada') ||
        str_contains($perguntaNormalizada, 'oab') ||
        $pareceOabDireta
    );
    if ($consultaAdvogado) {
        $advogados = cij_advogados_buscar($conn, $pergunta);
        if (!empty($advogados)) {
            $resposta['titulo'] = count($advogados) === 1 ? 'Advogado localizado' : 'Advogados localizados';
            $resposta['texto'] = 'Encontrei advogado(s) compatíveis com a pesquisa informada.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta realizada no cadastro interno de advogados do ROJEX.AI.';

            foreach ($advogados as $adv) {
                $contato = ($adv['celular'] ?? '') ?: (($adv['telefone'] ?? '') ?: '-');
                $resposta['itens'][] = (($adv['nome'] ?? '') ?: '-')
                    . ' | ID: ' . (($adv['id'] ?? '') ?: '-')
                    . ' | OAB: ' . (($adv['oab'] ?? '') ?: '-')
                    . ' | CPF: ' . (($adv['cpf'] ?? '') ?: '-')
                    . ' | Contato: ' . $contato
                    . ' | E-mail: ' . (($adv['email'] ?? '') ?: '-');
            }

            $primeiro = $advogados[0];
            $resposta['acoes'][] = ['label' => 'Abrir cadastro do advogado', 'url' => '?mod=advogados&acao=editar&id=' . urlencode((string)($primeiro['id'] ?? '')), 'class' => 'btn-primary', 'icon' => 'bi-person-badge'];
            $resposta['acoes'][] = ['label' => 'Ver lista de advogados', 'url' => '?mod=advogados', 'class' => 'btn-outline-secondary', 'icon' => 'bi-people'];
            return $resposta;
        }

        $resposta['titulo'] = 'Advogado não localizado';
        $resposta['texto'] = 'Não encontrei advogado compatível com o nome, OAB ou CPF informado.';
        $resposta['alerta'] = 'Confira os dados pesquisados e se o cadastro está ativo e fora da lixeira.';
        $resposta['tipo'] = 'warning';
        $resposta['acoes'][] = ['label' => 'Ver advogados cadastrados', 'url' => '?mod=advogados', 'class' => 'btn-outline-primary', 'icon' => 'bi-people'];
        return $resposta;
    }


    // Consulta ampla de processos: aceita número curto, ID interno, número CNJ,
    // nome do cliente, advogado, tipo, comarca, fase ou status.
    $consultaProcesso = $atalho === '' && (
        str_contains($perguntaNormalizada, 'processo') ||
        str_contains($perguntaNormalizada, 'processos') ||
        preg_match('/^\s*PRC\d+\s*$/iu', trim($pergunta)) === 1 ||
        preg_match('/^[\s\d.\-\/_]+$/u', trim($pergunta)) === 1
    );

    if ($consultaProcesso) {
        $processos = cij_processos_buscar_numero($conn, $pergunta);

        if (!empty($processos)) {
            $resposta['titulo'] = count($processos) === 1 ? 'Processo localizado' : 'Processos localizados';
            $resposta['texto'] = 'Encontrei processo(s) compatíveis com a pesquisa informada.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta realizada pela Base de Conhecimento do ROJEX.AI.';

            foreach ($processos as $proc) {
                $numero = ($proc['numero_processo'] ?? '') ?: '-';
                $cliente = ($proc['cliente_nome'] ?? '') ?: '-';
                $advogado = ($proc['advogado_nome'] ?? '') ?: '-';

                $resposta['itens'][] = $numero
                    . ' | ID: ' . (($proc['id'] ?? '') ?: '-')
                    . ' | Cliente: ' . $cliente
                    . ' | Advogado: ' . $advogado
                    . ' | Tipo: ' . (($proc['tipo_processo'] ?? '') ?: '-')
                    . ' | Fase: ' . (($proc['fase_atual'] ?? '') ?: '-')
                    . ' | Status: ' . (($proc['status'] ?? '') ?: '-');
            }

            $primeiro = $processos[0];
            $resposta['acoes'][] = [
                'label' => 'Abrir processo',
                'url' => '?mod=processos&acao=editar&id=' . urlencode((string)($primeiro['id'] ?? '')),
                'class' => 'btn-primary',
                'icon' => 'bi-folder2-open'
            ];
            $resposta['acoes'][] = [
                'label' => 'Ver processos cadastrados',
                'url' => '?mod=processos',
                'class' => 'btn-outline-secondary',
                'icon' => 'bi-briefcase'
            ];
            return $resposta;
        }

        $resposta['titulo'] = 'Processo não localizado';
        $resposta['texto'] = 'Não encontrei processo compatível com a pesquisa informada.';
        $resposta['itens'][] = 'Pesquisa: ' . trim($pergunta);
        $resposta['alerta'] = 'Tente informar número, ID, cliente, advogado, tipo, comarca, fase ou status.';
        $resposta['tipo'] = 'warning';
        $resposta['acoes'][] = [
            'label' => 'Ver processos cadastrados',
            'url' => '?mod=processos',
            'class' => 'btn-outline-primary',
            'icon' => 'bi-briefcase'
        ];
        return $resposta;
    }

    // Busca direta por nome do cliente quando não for CPF/CNPJ nem atalho específico.
    if ($atalho === '' && $perguntaNormalizada !== '') {
        $clientesNome = cij_cliente_por_nome($conn, $pergunta);
        if (!empty($clientesNome)) {
            $resposta['titulo'] = count($clientesNome) === 1 ? 'Cliente localizado por nome' : 'Clientes localizados por nome';
            $resposta['texto'] = 'Encontrei cliente(s) compatíveis com a pesquisa informada.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta realizada no cadastro interno de clientes do ROJEX.AI.';

            foreach ($clientesNome as $cli) {
                $contato = ($cli['whatsapp'] ?? '') ?: (($cli['celular'] ?? '') ?: (($cli['telefone'] ?? '') ?: '-'));
                $cidadeUf = trim((($cli['cidade'] ?? '') . '/' . ($cli['estado'] ?? '')), '/');
                $resposta['itens'][] = ($cli['nome'] ?: '-') . ' | ID: ' . ($cli['id'] ?: '-') . ' | CPF/CNPJ: ' . (($cli['cpf_cnpj'] ?? '') ?: '-') . ' | Contato: ' . $contato . ' | Cidade/UF: ' . ($cidadeUf ?: '-');
            }

            $primeiro = $clientesNome[0];
            $resposta['acoes'][] = ['label' => 'Abrir cadastro do cliente', 'url' => '?mod=clientes&acao=editar&id=' . urlencode((string)$primeiro['id']), 'class' => 'btn-primary', 'icon' => 'bi-person-lines-fill'];
            $resposta['acoes'][] = ['label' => 'Gerar peça para cliente', 'url' => '?mod=cij&ferramenta=gerador', 'class' => 'btn-success', 'icon' => 'bi-magic'];
            $resposta['acoes'][] = ['label' => 'Pesquisar em clientes', 'url' => '?mod=clientes&busca=' . urlencode($pergunta), 'class' => 'btn-outline-secondary', 'icon' => 'bi-search'];
            return $resposta;
        }
    }

    if ($atalho === 'agenda' || str_contains($perguntaNormalizada, 'agenda') || str_contains($perguntaNormalizada, 'audiência') || str_contains($perguntaNormalizada, 'audiencia')) {
        $compromissos = [];

        if ($atalho === 'agenda' && function_exists('rojex_kb_agenda_hoje')) {
            $compromissos = rojex_kb_agenda_hoje($conn, $hoje, 10);
        } elseif (function_exists('rojex_kb_agenda_por_termo')) {
            $compromissos = rojex_kb_agenda_por_termo($conn, $pergunta, 10);
        }

        if ($atalho === 'agenda') {
            $total = count($compromissos);
            $audiencias = 0;
            foreach ($compromissos as $compromisso) {
                $tipoCompromisso = mb_strtolower((string)($compromisso['tipo_compromisso'] ?? ''), 'UTF-8');
                if (str_contains($tipoCompromisso, 'audiência') || str_contains($tipoCompromisso, 'audiencia')) {
                    $audiencias++;
                }
            }
            $resposta['titulo'] = 'Agenda de Hoje';
            $resposta['texto'] = "Hoje existem {$total} compromisso(s) na agenda, sendo {$audiencias} audiência(s).";
        } else {
            $resposta['titulo'] = count($compromissos) === 1 ? 'Compromisso localizado' : 'Compromissos localizados';
            $resposta['texto'] = !empty($compromissos)
                ? 'Encontrei compromisso(s) compatíveis com a pesquisa informada.'
                : 'Não encontrei compromisso compatível com a pesquisa informada.';
        }

        foreach ($compromissos as $compromisso) {
            $data = !empty($compromisso['data_evento'])
                ? date('d/m/Y', strtotime((string)$compromisso['data_evento']))
                : '-';
            $hora = !empty($compromisso['horario'])
                ? date('H:i', strtotime((string)$compromisso['horario']))
                : 'Sem horário';

            $resposta['itens'][] = $data . ' ' . $hora
                . ' | ' . (($compromisso['tipo_compromisso'] ?? '') ?: 'Compromisso')
                . ' | Cliente: ' . (($compromisso['cliente_nome'] ?? '') ?: '-')
                . ' | Processo: ' . (($compromisso['numero_processo'] ?? '') ?: '-')
                . ' | Advogado: ' . (($compromisso['advogado_nome'] ?? '') ?: '-')
                . ' | Local: ' . (($compromisso['local'] ?? '') ?: '-')
                . ' | Status: ' . (($compromisso['status'] ?? '') ?: '-');
        }

        if (!empty($compromissos)) {
            $primeiro = $compromissos[0];
            $resposta['tipo'] = 'primary';
            $resposta['alerta'] = 'Consulta realizada pela Base de Conhecimento da Agenda do ROJEX.AI.';
            $resposta['acoes'][] = [
                'label' => 'Abrir compromisso',
                'url' => '?mod=agenda&acao=editar&id=' . urlencode((string)($primeiro['id'] ?? '')),
                'class' => 'btn-primary',
                'icon' => 'bi-calendar-event'
            ];
            $resposta['acoes'][] = [
                'label' => 'Ver agenda completa',
                'url' => '?mod=agenda',
                'class' => 'btn-outline-secondary',
                'icon' => 'bi-calendar3'
            ];
        } else {
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Nenhum compromisso ativo foi encontrado para esta consulta.';
            $resposta['acoes'][] = [
                'label' => 'Abrir agenda',
                'url' => '?mod=agenda',
                'class' => 'btn-outline-primary',
                'icon' => 'bi-calendar3'
            ];
        }

        return $resposta;
    }

    if ($atalho === 'prazos' || str_contains($perguntaNormalizada, 'prazo') || str_contains($perguntaNormalizada, 'processo')) {
        $total = cij_table_exists($conn, 'processos') ? cij_count($conn, "SELECT COUNT(*) AS total FROM processos WHERE status='Em Andamento' AND proximo_prazo BETWEEN '{$hoje}' AND '{$seteDias}'") : 0;
        $linhas = cij_table_exists($conn, 'processos') ? cij_rows($conn, "SELECT numero_processo, tipo_processo, fase_atual, proximo_prazo FROM processos WHERE status='Em Andamento' AND proximo_prazo BETWEEN '{$hoje}' AND '{$seteDias}' ORDER BY proximo_prazo ASC LIMIT 5") : [];

        $resposta['titulo'] = 'Prazos Processuais Próximos';
        $resposta['texto'] = "Foram localizado(s) {$total} prazo(s) processual(is) nos próximos 7 dias.";
        foreach ($linhas as $l) {
            $data = $l['proximo_prazo'] ? date('d/m/Y', strtotime($l['proximo_prazo'])) : '-';
            $resposta['itens'][] = ($l['numero_processo'] ?: 'Processo sem número') . " — " . ($l['tipo_processo'] ?: 'Tipo não informado') . " — " . ($l['fase_atual'] ?: 'Fase não informada') . " — Prazo: {$data}";
        }
        $resposta['tipo'] = $total > 0 ? 'warning' : 'success';
        return $resposta;
    }

    if ($atalho === 'financeiro' || str_contains($perguntaNormalizada, 'financeiro') || str_contains($perguntaNormalizada, 'despesa') || str_contains($perguntaNormalizada, 'receber') || str_contains($perguntaNormalizada, 'inadimpl')) {
        $despesasVencidas = cij_table_exists($conn, 'contas_pagar') ? cij_count($conn, "SELECT COUNT(*) AS total FROM contas_pagar WHERE COALESCE(deletado,0)=0 AND status IN ('Pendente','Parcial') AND data_vencimento < '{$hoje}'") : 0;
        $despesasAbertas = cij_table_exists($conn, 'contas_pagar') ? cij_sum($conn, "SELECT COALESCE(SUM(CASE WHEN valor_pendente > 0 THEN valor_pendente ELSE valor END),0) AS total FROM contas_pagar WHERE COALESCE(deletado,0)=0 AND status IN ('Pendente','Parcial')") : 0;
        $recebidoMes = cij_table_exists($conn, 'contas_receber') ? cij_sum($conn, "SELECT COALESCE(SUM(CASE WHEN valor_pago > 0 THEN valor_pago ELSE valor END),0) AS total FROM contas_receber WHERE COALESCE(deletado,0)=0 AND status IN ('Recebido','Pago','Quitada') AND COALESCE(data_recebimento, DATE(atualizado_em), data_vencimento) BETWEEN '{$inicioMes}' AND '{$fimMes}'") : 0;

        $resposta['titulo'] = 'Análise Financeira Rápida';
        $resposta['texto'] = "Resumo financeiro do mês: recebido " . cij_money($recebidoMes) . ", despesas abertas " . cij_money($despesasAbertas) . " e {$despesasVencidas} despesa(s) vencida(s).";
        $resposta['itens'][] = "Recebido no mês: " . cij_money($recebidoMes);
        $resposta['itens'][] = "Despesas em aberto: " . cij_money($despesasAbertas);
        $resposta['itens'][] = "Despesas vencidas: {$despesasVencidas}";
        $resposta['tipo'] = $despesasVencidas > 0 ? 'danger' : 'success';
        return $resposta;
    }

    $consultaHonorarios = $atalho === 'honorarios'
        || str_contains($perguntaNormalizada, 'honorário')
        || str_contains($perguntaNormalizada, 'honorario')
        || str_contains($perguntaNormalizada, 'saldo pendente')
        || str_contains($perguntaNormalizada, 'valor pendente')
        || str_contains($perguntaNormalizada, 'em aberto')
        || str_contains($perguntaNormalizada, 'quanto falta receber')
        || str_contains($perguntaNormalizada, 'parcelas pendentes')
        || str_contains($perguntaNormalizada, 'parcelas em aberto');

    if ($consultaHonorarios) {
        $consultaEspecifica = trim($pergunta) !== ''
            && $atalho === ''
            && !in_array(
                trim(mb_strtolower($pergunta, 'UTF-8')),
                ['honorários', 'honorarios', 'honorários vencidos', 'honorarios vencidos'],
                true
            );

        if ($consultaEspecifica && function_exists('rojex_kb_honorarios_por_termo')) {
            $honorarios = rojex_kb_honorarios_por_termo($conn, $pergunta, 10);

            if (!empty($honorarios)) {
                $resposta['titulo'] = count($honorarios) === 1
                    ? 'Honorário localizado'
                    : 'Honorários localizados';
                $resposta['texto'] = 'Encontrei honorário(s) compatíveis com a pesquisa informada.';
                $resposta['tipo'] = 'success';
                $resposta['alerta'] = 'Consulta realizada pela Base de Conhecimento de Honorários do ROJEX.AI.';

                foreach ($honorarios as $honorario) {
                    $vencimento = !empty($honorario['data_vencimento'])
                        ? date('d/m/Y', strtotime((string)$honorario['data_vencimento']))
                        : '-';

                    $resposta['itens'][] =
                        'ID: ' . (($honorario['id'] ?? '') ?: '-')
                        . ' | Cliente: ' . (($honorario['nome_cliente'] ?? '') ?: '-')
                        . ' | Processo: ' . (($honorario['numero_processo'] ?? '') ?: '-')
                        . ' | Tipo: ' . (($honorario['tipo_honorario'] ?? '') ?: '-')
                        . ' | Total: ' . cij_money((float)($honorario['valor_total'] ?? 0))
                        . ' | Pago: ' . cij_money((float)($honorario['valor_pago'] ?? 0))
                        . ' | Saldo: ' . cij_money((float)($honorario['valor_pendente'] ?? 0))
                        . ' | Vencimento: ' . $vencimento
                        . ' | Status: ' . (($honorario['status'] ?? '') ?: '-');
                }

                $primeiro = $honorarios[0];
                $resposta['acoes'][] = [
                    'label' => 'Abrir honorário',
                    'url' => '?mod=honorarios&acao=editar&id=' . urlencode((string)($primeiro['id'] ?? '')),
                    'class' => 'btn-primary',
                    'icon' => 'bi-cash-stack'
                ];
                $resposta['acoes'][] = [
                    'label' => 'Ver honorários',
                    'url' => '?mod=honorarios',
                    'class' => 'btn-outline-secondary',
                    'icon' => 'bi-receipt'
                ];

                return $resposta;
            }
        }

        $vencidos = function_exists('rojex_kb_honorarios_vencidos')
            ? rojex_kb_honorarios_vencidos($conn, $hoje, 50)
            : [];

        $resumo = function_exists('rojex_kb_resumo_honorarios')
            ? rojex_kb_resumo_honorarios($conn)
            : [
                'total' => 0,
                'pendentes' => 0,
                'valor_pago' => 0,
                'saldo_aberto' => 0,
                'vencidos' => 0,
            ];

        $valorVencido = 0.0;
        foreach ($vencidos as $honorario) {
            $valorVencido += (float)($honorario['valor_pendente'] ?? 0);
        }

        $resposta['titulo'] = 'Honorários em Atenção';
        $resposta['texto'] = 'Existem '
            . count($vencidos)
            . ' honorário(s) vencido(s), totalizando '
            . cij_money($valorVencido)
            . ' em saldo pendente vencido.';

        $resposta['itens'][] = 'Honorários cadastrados: ' . (int)($resumo['total'] ?? 0);
        $resposta['itens'][] = 'Pendentes ou parciais: ' . (int)($resumo['pendentes'] ?? 0);
        $resposta['itens'][] = 'Valor pago: ' . cij_money((float)($resumo['valor_pago'] ?? 0));
        $resposta['itens'][] = 'Saldo total em aberto: ' . cij_money((float)($resumo['saldo_aberto'] ?? 0));
        $resposta['itens'][] = 'Honorários vencidos: ' . count($vencidos);

        foreach (array_slice($vencidos, 0, 5) as $honorario) {
            $vencimento = !empty($honorario['data_vencimento'])
                ? date('d/m/Y', strtotime((string)$honorario['data_vencimento']))
                : '-';

            $resposta['itens'][] =
                (($honorario['nome_cliente'] ?? '') ?: 'Cliente não informado')
                . ' | ID: ' . (($honorario['id'] ?? '') ?: '-')
                . ' | Processo: ' . (($honorario['numero_processo'] ?? '') ?: '-')
                . ' | Vencimento: ' . $vencimento
                . ' | Saldo: ' . cij_money((float)($honorario['valor_pendente'] ?? 0));
        }

        $resposta['tipo'] = !empty($vencidos) ? 'warning' : 'success';
        $resposta['alerta'] = 'Consulta realizada pela Base de Conhecimento de Honorários do ROJEX.AI.';
        $resposta['acoes'][] = [
            'label' => 'Abrir honorários',
            'url' => '?mod=honorarios',
            'class' => 'btn-primary',
            'icon' => 'bi-cash-stack'
        ];

        if (!empty($vencidos)) {
            $resposta['acoes'][] = [
                'label' => 'Abrir primeiro vencido',
                'url' => '?mod=honorarios&acao=editar&id=' . urlencode((string)($vencidos[0]['id'] ?? '')),
                'class' => 'btn-outline-warning',
                'icon' => 'bi-exclamation-triangle'
            ];
        }

        return $resposta;
    }


    $consultaDocumentos = $atalho === ''
        && (
            str_contains($perguntaNormalizada, 'documento')
            || str_contains($perguntaNormalizada, 'documentos')
            || str_contains($perguntaNormalizada, 'arquivo')
            || str_contains($perguntaNormalizada, 'arquivos')
            || str_contains($perguntaNormalizada, 'procuração')
            || str_contains($perguntaNormalizada, 'procuracao')
            || str_contains($perguntaNormalizada, 'contrato')
            || str_contains($perguntaNormalizada, 'prova')
            || str_contains($perguntaNormalizada, 'pdf')
            || str_contains($perguntaNormalizada, 'word')
            || str_contains($perguntaNormalizada, 'docx')
        );

    if ($consultaDocumentos && function_exists('rojex_kb_documentos_por_termo')) {
        $documentos = rojex_kb_documentos_por_termo($conn, $pergunta, 10);

        if (!empty($documentos)) {
            $resposta['titulo'] = count($documentos) === 1
                ? 'Documento localizado'
                : 'Documentos localizados';
            $resposta['texto'] = 'Encontrei documento(s) compatíveis com a pesquisa informada.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta realizada pela Base de Conhecimento de Documentos do ROJEX.AI.';

            foreach ($documentos as $documento) {
                $dataEnvio = !empty($documento['criado_em'])
                    ? date('d/m/Y H:i', strtotime((string)$documento['criado_em']))
                    : '-';

                $resposta['itens'][] =
                    (($documento['codigo'] ?? '') ?: 'Sem código')
                    . ' | Título: ' . (($documento['titulo'] ?? '') ?: '-')
                    . ' | Categoria: ' . (($documento['categoria'] ?? '') ?: '-')
                    . ' | Cliente: ' . (($documento['cliente_nome'] ?? '') ?: '-')
                    . ' | Processo: ' . (($documento['numero_processo'] ?? '') ?: '-')
                    . ' | Arquivo: ' . (($documento['nome_original'] ?? '') ?: '-')
                    . ' | Extensão: ' . strtoupper((string)(($documento['extensao'] ?? '') ?: '-'))
                    . ' | Enviado por: ' . (($documento['usuario_nome'] ?? '') ?: '-')
                    . ' | Data: ' . $dataEnvio;
            }

            $primeiro = $documentos[0];
            $idDocumento = (int)($primeiro['id'] ?? 0);

            if ($idDocumento > 0) {
                $resposta['acoes'][] = [
                    'label' => 'Visualizar documento',
                    'url' => 'documento_arquivo.php?id=' . $idDocumento . '&modo=inline',
                    'class' => 'btn-success',
                    'icon' => 'bi-eye'
                ];
                $resposta['acoes'][] = [
                    'label' => 'Baixar documento',
                    'url' => 'documento_arquivo.php?id=' . $idDocumento . '&modo=download',
                    'class' => 'btn-outline-primary',
                    'icon' => 'bi-download'
                ];
            }

            $resposta['acoes'][] = [
                'label' => 'Ver documentos',
                'url' => '?mod=documentos',
                'class' => 'btn-outline-secondary',
                'icon' => 'bi-files'
            ];

            return $resposta;
        }

        $resposta['titulo'] = 'Documento não localizado';
        $resposta['texto'] = 'Não encontrei documento compatível com a pesquisa informada.';
        $resposta['alerta'] = 'Tente informar título, código, cliente, processo, categoria, extensão ou nome do arquivo.';
        $resposta['tipo'] = 'warning';
        $resposta['acoes'][] = [
            'label' => 'Abrir documentos',
            'url' => '?mod=documentos',
            'class' => 'btn-outline-primary',
            'icon' => 'bi-files'
        ];
        return $resposta;
    }

    if ($atalho === 'clientes' || str_contains($perguntaNormalizada, 'cliente')) {
        $ativos = cij_table_exists($conn, 'clientes') ? cij_count($conn, "SELECT COUNT(*) AS total FROM clientes WHERE COALESCE(deletado,0)=0 AND status='Ativo'") : 0;
        $novos = cij_table_exists($conn, 'clientes') ? cij_count($conn, "SELECT COUNT(*) AS total FROM clientes WHERE COALESCE(deletado,0)=0 AND data_cadastro BETWEEN '{$inicioMes}' AND '{$fimMes}'") : 0;

        $resposta['titulo'] = 'Resumo de Clientes';
        $resposta['texto'] = "O escritório possui {$ativos} cliente(s) ativo(s), com {$novos} novo(s) cadastro(s) neste mês.";
        $resposta['itens'][] = "Clientes ativos: {$ativos}";
        $resposta['itens'][] = "Novos clientes no mês: {$novos}";
        $resposta['tipo'] = 'primary';
        return $resposta;
    }


    // Fallback universal para consultas livres.
    if ($atalho === '' && trim($pergunta) !== '') {
        $achados = cij_busca_livre_base($conn, $pergunta);

        if (!empty($achados)) {
            $resposta['titulo'] = 'Resultados encontrados';
            $resposta['texto'] = 'A Base de Conhecimento encontrou registros compatíveis em diferentes áreas do ROJEX.AI.';
            $resposta['tipo'] = 'success';
            $resposta['alerta'] = 'Consulta livre realizada em clientes, advogados, processos, agenda, honorários e documentos.';

            foreach ($achados as $achado) {
                $tipo = $achado['tipo'] ?? '';
                $dados = $achado['dados'] ?? [];

                if ($tipo === 'cliente') {
                    $resposta['itens'][] = 'Cliente: '
                        . (($dados['nome'] ?? '') ?: '-')
                        . ' | CPF/CNPJ: ' . (($dados['cpf_cnpj'] ?? '') ?: '-');
                } elseif ($tipo === 'advogado') {
                    $resposta['itens'][] = 'Advogado: '
                        . (($dados['nome'] ?? '') ?: '-')
                        . ' | OAB: ' . (($dados['oab'] ?? '') ?: '-')
                        . (($dados['oab_uf'] ?? '') ? '/' . $dados['oab_uf'] : '');
                } elseif ($tipo === 'processo') {
                    $resposta['itens'][] = 'Processo: '
                        . (($dados['numero_processo'] ?? '') ?: '-')
                        . ' | Cliente: ' . (($dados['cliente_nome'] ?? '') ?: '-')
                        . ' | Status: ' . (($dados['status'] ?? '') ?: '-');
                } elseif ($tipo === 'agenda') {
                    $dataAgenda = !empty($dados['data_evento'])
                        ? date('d/m/Y', strtotime((string)$dados['data_evento']))
                        : '-';
                    $resposta['itens'][] = 'Agenda: '
                        . $dataAgenda
                        . ' | ' . (($dados['tipo_compromisso'] ?? '') ?: 'Compromisso')
                        . ' | Cliente: ' . (($dados['cliente_nome'] ?? '') ?: '-')
                        . ' | Status: ' . (($dados['status'] ?? '') ?: '-');
                } elseif ($tipo === 'honorario') {
                    $resposta['itens'][] = 'Honorário: '
                        . (($dados['id'] ?? '') ?: '-')
                        . ' | Cliente: ' . (($dados['nome_cliente'] ?? '') ?: '-')
                        . ' | Processo: ' . (($dados['numero_processo'] ?? '') ?: '-')
                        . ' | Saldo: ' . cij_money((float)($dados['valor_pendente'] ?? 0))
                        . ' | Status: ' . (($dados['status'] ?? '') ?: '-');
                } elseif ($tipo === 'documento') {
                    $resposta['itens'][] = 'Documento: '
                        . (($dados['codigo'] ?? '') ?: '-')
                        . ' | Título: ' . (($dados['titulo'] ?? '') ?: '-')
                        . ' | Cliente: ' . (($dados['cliente_nome'] ?? '') ?: '-')
                        . ' | Processo: ' . (($dados['numero_processo'] ?? '') ?: '-')
                        . ' | Arquivo: ' . (($dados['nome_original'] ?? '') ?: '-');
                }
            }

            $primeiro = $achados[0];
            $tipo = $primeiro['tipo'] ?? '';
            $dados = $primeiro['dados'] ?? [];
            $id = (string)($dados['id'] ?? '');

            if ($tipo === 'cliente') {
                $resposta['acoes'][] = [
                    'label' => 'Abrir cliente',
                    'url' => '?mod=clientes&acao=editar&id=' . urlencode($id),
                    'class' => 'btn-primary',
                    'icon' => 'bi-person-lines-fill'
                ];
            } elseif ($tipo === 'advogado') {
                $resposta['acoes'][] = [
                    'label' => 'Abrir advogado',
                    'url' => '?mod=advogados&acao=editar&id=' . urlencode($id),
                    'class' => 'btn-primary',
                    'icon' => 'bi-person-badge'
                ];
            } elseif ($tipo === 'processo') {
                $resposta['acoes'][] = [
                    'label' => 'Abrir processo',
                    'url' => '?mod=processos&acao=editar&id=' . urlencode($id),
                    'class' => 'btn-primary',
                    'icon' => 'bi-folder2-open'
                ];
            } elseif ($tipo === 'agenda') {
                $resposta['acoes'][] = [
                    'label' => 'Abrir compromisso',
                    'url' => '?mod=agenda&acao=editar&id=' . urlencode($id),
                    'class' => 'btn-primary',
                    'icon' => 'bi-calendar-event'
                ];
            } elseif ($tipo === 'honorario') {
                $resposta['acoes'][] = [
                    'label' => 'Abrir honorário',
                    'url' => '?mod=honorarios&acao=editar&id=' . urlencode($id),
                    'class' => 'btn-primary',
                    'icon' => 'bi-cash-stack'
                ];
            } elseif ($tipo === 'documento') {
                $idDocumento = (int)($dados['id'] ?? 0);
                if ($idDocumento > 0) {
                    $resposta['acoes'][] = [
                        'label' => 'Visualizar documento',
                        'url' => 'documento_arquivo.php?id=' . $idDocumento . '&modo=inline',
                        'class' => 'btn-success',
                        'icon' => 'bi-eye'
                    ];
                }
            }

            return $resposta;
        }
    }

    return $resposta;
}

$perguntaCij = trim((string)($_POST['pergunta_cij'] ?? ''));
$atalhoCij = trim((string)($_POST['atalho_cij'] ?? ''));
$respostaCij = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['acao_cij'] ?? '') === 'assistente')) {
    $respostaCij = cij_assistente_responder($conn, $perguntaCij, $atalhoCij);
}

$ferramentaCij = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_GET['ferramenta'] ?? ''));
if ($ferramentaCij === 'gerador') {
    $arquivoFerramenta = __DIR__ . '/cij/gerador.php';

    if (is_file($arquivoFerramenta)) {
        include $arquivoFerramenta;
    } else {
        echo "<div class='alert alert-danger'>Ferramenta do CIJ não encontrada: Gerador de Peças.</div>";
    }

    $conn->close();
    return;
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-cpu me-2"></i>Centro de Inteligência Jurídica
            </h2>
            <p class="text-muted mb-0">
                Ambiente inteligente do ROJEX.AI para criação, análise, pesquisa e apoio estratégico jurídico.
            </p>
        </div>
        <span class="badge bg-dark px-3 py-2">ROJEX.AI Enterprise</span>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-chat-dots me-2"></i>Assistente Jurídico ROJEX.AI</span>
            <span class="badge bg-primary">Consulta interna inicial</span>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="acao_cij" value="assistente">
                <input type="hidden" name="atalho_cij" id="atalho_cij" value="">
                <div class="col-lg-9">
                    <label class="form-label fw-semibold">Pergunte ao ROJEX.AI</label>
                    <input type="text" name="pergunta_cij" class="form-control form-control-lg" value="<?= cij_h($perguntaCij) ?>" placeholder="Ex.: quais processos vencem esta semana? clientes ativos? honorários vencidos?">
                    <div class="form-text">Nesta etapa, o assistente responde com base nos dados internos do sistema. A IA externa será integrada em etapa futura.</div>
                </div>
                <div class="col-lg-3">
                    <label class="form-label fw-semibold d-none d-lg-block">&nbsp;</label>
                    <button class="btn btn-primary btn-lg w-100"><i class="bi bi-send me-1"></i>Consultar</button>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('atalho_cij').value='agenda'"><i class="bi bi-calendar-check me-1"></i>Agenda de hoje</button>
                        <button type="submit" class="btn btn-outline-warning btn-sm" onclick="document.getElementById('atalho_cij').value='prazos'"><i class="bi bi-clock-history me-1"></i>Prazos próximos</button>
                        <button type="submit" class="btn btn-outline-danger btn-sm" onclick="document.getElementById('atalho_cij').value='financeiro'"><i class="bi bi-cash-coin me-1"></i>Financeiro em atenção</button>
                        <button type="submit" class="btn btn-outline-success btn-sm" onclick="document.getElementById('atalho_cij').value='honorarios'"><i class="bi bi-receipt me-1"></i>Honorários vencidos</button>
                        <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('atalho_cij').value='clientes'"><i class="bi bi-people me-1"></i>Resumo de clientes</button>
                    </div>
                </div>
            </form>

            <?php if ($respostaCij): ?>
                <div class="card border-<?= cij_h($respostaCij['tipo']) ?> shadow-sm mt-4 mb-0" id="resultado-cij">
                    <div class="card-header bg-<?= cij_h($respostaCij['tipo']) ?> <?= in_array(($respostaCij['tipo'] ?? ''), ['warning', 'info', 'light'], true) ? 'text-dark' : 'text-white' ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <strong><i class="bi bi-pin-angle me-1"></i><?= cij_h($respostaCij['titulo']) ?></strong>
                        <span class="badge bg-light text-dark border">Resultado fixado na tela</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><?= cij_h($respostaCij['texto']) ?></p>
                        <?php if (!empty($respostaCij['itens'])): ?>
                            <ul class="mb-3">
                                <?php foreach ($respostaCij['itens'] as $item): ?>
                                    <li><?= cij_h($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <small class="d-block text-muted mb-3"><i class="bi bi-info-circle me-1"></i><?= cij_h($respostaCij['alerta']) ?></small>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($respostaCij['acoes'])): ?>
                                <?php foreach ($respostaCij['acoes'] as $acaoResultado): ?>
                                    <a class="btn btn-sm <?= cij_h($acaoResultado['class'] ?? 'btn-outline-primary') ?>" href="<?= cij_h($acaoResultado['url'] ?? '#') ?>">
                                        <i class="bi <?= cij_h($acaoResultado['icon'] ?? 'bi-arrow-right') ?> me-1"></i><?= cij_h($acaoResultado['label'] ?? 'Abrir') ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <a href="?mod=cij" class="btn btn-sm btn-outline-dark"><i class="bi bi-arrow-repeat me-1"></i>Nova consulta</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <?php
        $modulosCij = [
            ['icone' => 'bi-pencil-square', 'titulo' => 'Gerador de Peças', 'descricao' => 'Criar petições, contratos, notificações, procurações e pareceres.'],
            ['icone' => 'bi-file-earmark-check', 'titulo' => 'Revisor Jurídico', 'descricao' => 'Revisar textos jurídicos, identificar inconsistências e sugerir melhorias.'],
            ['icone' => 'bi-file-earmark-text', 'titulo' => 'Análise de Contratos', 'descricao' => 'Analisar cláusulas, riscos, obrigações e pontos de atenção em contratos.'],
            ['icone' => 'bi-folder2-open', 'titulo' => 'Análise de Documentos e Provas', 'descricao' => 'Organizar e analisar PDFs, imagens, peças, provas e documentos do cliente.'],
            ['icone' => 'bi-bank', 'titulo' => 'Pesquisa Jurídica', 'descricao' => 'Estrutura para legislação, jurisprudência, fundamentos, teses e referências jurídicas.'],
            ['icone' => 'bi-journal-bookmark', 'titulo' => 'Biblioteca Inteligente', 'descricao' => 'Guardar modelos aprovados, peças geradas, teses e conhecimento do escritório.'],
            ['icone' => 'bi-cash-coin', 'titulo' => 'IA Financeira', 'descricao' => 'Interpretar honorários, inadimplência, fluxo de caixa e indicadores financeiros.'],
            ['icone' => 'bi-graph-up-arrow', 'titulo' => 'IA Administrativa', 'descricao' => 'Apoiar produtividade, desempenho da equipe, agenda e gestão operacional.'],
            ['icone' => 'bi-mortarboard', 'titulo' => 'Academia ROJEX.AI', 'descricao' => 'Espaço para treinamentos, tutoriais, boas práticas e novidades do sistema.'],
        ];
        foreach ($modulosCij as $item):
        ?>
        <div class="col-xl-4 col-lg-6">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width:48px;height:48px;min-width:48px;">
                            <i class="bi <?= cij_h($item['icone']) ?> fs-4"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-2"><?= cij_h($item['titulo']) ?></h5>
                            <p class="text-muted mb-3"><?= cij_h($item['descricao']) ?></p>
                            <?php if (($item['titulo'] ?? '') === 'Gerador de Peças'): ?>
                                <a href="?mod=cij&ferramenta=gerador" class="btn btn-sm btn-primary">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir ferramenta
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" disabled>
                                    Em preparação
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-shield-check me-2"></i>Próximas etapas do CIJ
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>1. Interface</strong><br><small class="text-muted">Estrutura visual do CIJ.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>2. Consultas internas</strong><br><small class="text-muted">Integração com clientes, processos e financeiro.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>3. Upload/análise</strong><br><small class="text-muted">Contratos, provas e documentos.</small></div></div>
                <div class="col-md-3"><div class="border rounded p-3 h-100"><strong>4. IA externa</strong><br><small class="text-muted">Preparação futura para API de IA.</small></div></div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
?>