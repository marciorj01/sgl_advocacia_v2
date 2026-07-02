<?php
if (!isset($conn) || !($conn instanceof mysqli)) { $conn = conectar(); }
require_once __DIR__ . '/../config/ia.php';
if (function_exists('sgl_garantir_logs')) { sgl_garantir_logs($conn); }

function sgl_ia_h(mysqli $conn, string $sql): bool { try { $conn->query($sql); return true; } catch (Throwable $e) { return false; } }
function sgl_ia_table_exists(mysqli $conn, string $table): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $r = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $r && $r->num_rows > 0;
}
function sgl_ia_col_exists(mysqli $conn, string $table, string $col): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $colEsc = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'");
    return $r && $r->num_rows > 0;
}
function sgl_ia_garantir(mysqli $conn): void {
    sgl_ia_h($conn, "CREATE TABLE IF NOT EXISTS ia_consultas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(80) NOT NULL,
        titulo VARCHAR(180) NULL,
        entrada MEDIUMTEXT NULL,
        prompt_gerado MEDIUMTEXT NULL,
        resposta MEDIUMTEXT NULL,
        modo VARCHAR(30) DEFAULT 'rascunho',
        usuario_id INT NULL,
        usuario_nome VARCHAR(150) NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tipo (tipo),
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function sgl_ia_rows(mysqli $conn, string $sql): array { try { $r=$conn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; } catch (Throwable $e) { return []; } }
function sgl_ia_one(mysqli $conn, string $sql): ?array { $rows=sgl_ia_rows($conn,$sql); return $rows[0] ?? null; }
function sgl_ia_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function sgl_ia_data(): string { return date('d/m/Y'); }
function sgl_ia_moeda($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }

sgl_ia_garantir($conn);

$clientes = sgl_ia_rows($conn, "SELECT id, nome, cpf_cnpj FROM clientes WHERE COALESCE(deletado,0)=0 ORDER BY nome LIMIT 200");
$processos = sgl_ia_rows($conn, "SELECT p.id, p.numero_processo, p.tipo_processo, p.comarca, p.fase_atual, c.nome AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id=p.cliente_id WHERE COALESCE(p.deletado,0)=0 ORDER BY p.id DESC LIMIT 200");
$modelos = sgl_ia_table_exists($conn,'modelos_documentos') ? sgl_ia_rows($conn, "SELECT id, titulo, categoria, area_direito FROM modelos_documentos WHERE COALESCE(deletado,0)=0 ORDER BY titulo LIMIT 200") : [];

$tipo = $_POST['tipo'] ?? 'peticao';
$resposta = '';
$promptGerado = '';
$erroIa = '';
$modoResposta = 'rascunho';

$perfis = [
    'peticao' => 'Gerador de Petições',
    'contrato' => 'Gerador/Revisor de Contratos',
    'resumo' => 'Resumo Jurídico',
    'revisao' => 'Revisão de Texto Jurídico',
    'estrategia' => 'Estratégia Processual',
    'checklist' => 'Checklist de Documentos',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteId = (int)($_POST['cliente_id'] ?? 0);
    $processoId = (int)($_POST['processo_id'] ?? 0);
    $modeloId = (int)($_POST['modelo_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $textoBase = trim($_POST['texto_base'] ?? '');
    $objetivo = trim($_POST['objetivo'] ?? '');
    $area = trim($_POST['area'] ?? '');
    $tom = trim($_POST['tom'] ?? 'Técnico, objetivo e profissional');

    $cliente = $clienteId > 0 ? sgl_ia_one($conn, "SELECT * FROM clientes WHERE id={$clienteId} LIMIT 1") : null;
    $processo = $processoId > 0 ? sgl_ia_one($conn, "SELECT p.*, c.nome AS cliente_nome FROM processos p LEFT JOIN clientes c ON c.id=p.cliente_id WHERE p.id={$processoId} LIMIT 1") : null;
    $modelo = ($modeloId > 0 && sgl_ia_table_exists($conn,'modelos_documentos')) ? sgl_ia_one($conn, "SELECT * FROM modelos_documentos WHERE id={$modeloId} LIMIT 1") : null;

    $contexto = [];
    $contexto[] = "Data atual: " . sgl_ia_data();
    $contexto[] = "Área do Direito: " . ($area ?: 'não informada');
    $contexto[] = "Tom desejado: " . $tom;
    if ($cliente) {
        $contexto[] = "Cliente: " . ($cliente['nome'] ?? '');
        $contexto[] = "CPF/CNPJ: " . ($cliente['cpf_cnpj'] ?? '');
        $contexto[] = "Contato: " . (($cliente['telefone'] ?? '') ?: ($cliente['whatsapp'] ?? ''));
        $end = trim(($cliente['logradouro'] ?? '') . ' ' . ($cliente['numero'] ?? '') . ' ' . ($cliente['bairro'] ?? '') . ' ' . ($cliente['cidade'] ?? '') . '/' . ($cliente['estado'] ?? ''));
        if ($end !== '/') $contexto[] = "Endereço: " . $end;
    }
    if ($processo) {
        $contexto[] = "Processo: " . ($processo['numero_processo'] ?? '');
        $contexto[] = "Tipo: " . ($processo['tipo_processo'] ?? '');
        $contexto[] = "Comarca: " . ($processo['comarca'] ?? '');
        $contexto[] = "Fase atual: " . ($processo['fase_atual'] ?? '');
        if (isset($processo['valor_causa'])) $contexto[] = "Valor da causa: " . sgl_ia_moeda($processo['valor_causa']);
    }
    if ($modelo) {
        $contexto[] = "Modelo selecionado: " . ($modelo['titulo'] ?? '');
        $contexto[] = "Categoria do modelo: " . ($modelo['categoria'] ?? '');
        if (!empty($modelo['conteudo'])) $contexto[] = "Conteúdo do modelo:\n" . $modelo['conteudo'];
    }
    if ($objetivo !== '') $contexto[] = "Objetivo do usuário:\n" . $objetivo;
    if ($textoBase !== '') $contexto[] = "Texto/base fornecido pelo usuário:\n" . $textoBase;

    $promptSistema = "Você é um assistente jurídico para um escritório de advocacia brasileiro. Responda em português do Brasil, com linguagem técnica, clara e prudente. Não invente fatos, artigos, jurisprudência ou dados não fornecidos. Quando faltar informação, marque campos como [informar]. A resposta é rascunho para revisão de advogado habilitado.";

    $instrucoes = [
        'peticao' => "Crie um rascunho estruturado de peça/petição com: título, qualificação resumida se possível, fatos, fundamentos jurídicos em linguagem prudente, pedidos, provas, valor da causa se aplicável e fechamento. Não invente número de processo nem dados ausentes.",
        'contrato' => "Crie ou revise um contrato/termo com cláusulas numeradas, linguagem clara, obrigações das partes, valores, prazos, foro e campos pendentes. Preserve dados fornecidos.",
        'resumo' => "Faça um resumo executivo para advogado/equipe com: contexto, partes, situação atual, pendências, riscos, próximos passos e checklist.",
        'revisao' => "Revise o texto jurídico, apontando problemas, inconsistências, melhorias de clareza e entregue uma versão reescrita quando cabível.",
        'estrategia' => "Monte uma análise estratégica com hipóteses, riscos, provas necessárias, próximos passos e perguntas a confirmar com o cliente.",
        'checklist' => "Gere checklist de documentos e informações necessários para o caso, separando obrigatório, recomendado e complementar.",
    ];

    $promptGerado = "Tarefa: " . ($perfis[$tipo] ?? $tipo) . "\n\n" . ($instrucoes[$tipo] ?? '') . "\n\nCONTEXTO DO SGL:\n" . implode("\n", $contexto);

    $resultado = sgl_ia_chamar_openai($promptSistema, $promptGerado);
    if ($resultado['ok']) {
        $resposta = $resultado['texto'];
        $modoResposta = $resultado['modo'];
    } else {
        $erroIa = $resultado['erro'];
        $modoResposta = 'rascunho';
        $resposta = "MODO RASCUNHO — IA externa ainda não configurada.\n\nCopie o prompt abaixo para usar no ChatGPT ou configure a API na Hostinger/XAMPP.\n\n" . $promptGerado;
    }

    $stmt = $conn->prepare("INSERT INTO ia_consultas (tipo, titulo, entrada, prompt_gerado, resposta, modo, usuario_id, usuario_nome) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    $unome = (string)($_SESSION['nome'] ?? $_SESSION['username'] ?? 'Usuário');
    $entrada = json_encode(['cliente_id'=>$clienteId,'processo_id'=>$processoId,'modelo_id'=>$modeloId,'objetivo'=>$objetivo,'area'=>$area], JSON_UNESCAPED_UNICODE);
    $stmt->bind_param('ssssssis', $tipo, $titulo, $entrada, $promptGerado, $resposta, $modoResposta, $uid, $unome);
    $stmt->execute();
    if (function_exists('sgl_registrar_log')) sgl_registrar_log($conn, 'USOU_IA_JURIDICA', 'ia_consultas', (string)$conn->insert_id, ($perfis[$tipo] ?? $tipo) . ' - ' . $modoResposta);
}

$historico = sgl_ia_rows($conn, "SELECT id, tipo, titulo, modo, usuario_nome, criado_em FROM ia_consultas ORDER BY id DESC LIMIT 8");
?>

<style>
.ia-hero{background:linear-gradient(135deg,#123a5a,#1f73b7);border-radius:18px;color:#fff!important;padding:24px;box-shadow:0 10px 28px rgba(15,23,42,.12)}
.ia-hero h1,.ia-hero h2,.ia-hero h3,.ia-hero p,.ia-hero div{color:#fff!important}
.ia-hero .opacity-75{opacity:.9!important}
.ia-card{border:0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.08)}
.ia-badge{border-radius:999px;padding:.35rem .7rem;font-weight:700;font-size:.75rem}
.ia-output{white-space:pre-wrap;background:#fff;border:1px solid rgba(15,23,42,.12);border-radius:14px;padding:18px;min-height:260px;font-family:Arial, sans-serif;line-height:1.55}
.ia-template-btn{border:1px solid rgba(13,110,253,.25);background:#f8fbff;border-radius:12px;padding:10px;text-align:left;width:100%;height:100%}
.ia-template-btn:hover{background:#eef6ff;border-color:#0d6efd}
</style>

<div class="ia-hero mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-robot"></i> IA para Advogados</h2>
            <div class="opacity-75">Assistente jurídico para rascunhos, revisões, resumos, checklists, contratos e estratégia processual.</div>
        </div>
        <div class="text-lg-end">
            <?php if (sgl_ia_disponivel()): ?>
                <span class="ia-badge bg-success text-white"><i class="bi bi-check-circle"></i> IA conectada</span>
            <?php else: ?>
                <span class="ia-badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> Modo rascunho</span>
                <div class="small opacity-75 mt-1">Configure SGL_OPENAI_API_KEY e SGL_OPENAI_MODEL para resposta automática.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card ia-card">
            <div class="card-header bg-dark text-white"><strong><i class="bi bi-magic"></i> Criar solicitação para IA</strong></div>
            <div class="card-body">
                <?php if ($erroIa): ?><div class="alert alert-warning"><strong>Atenção:</strong> <?= sgl_ia_e($erroIa) ?></div><?php endif; ?>
                <form method="post" id="formIA">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tipo de assistência</label>
                            <select name="tipo" class="form-select">
                                <?php foreach ($perfis as $k=>$v): ?><option value="<?= $k ?>" <?= $tipo===$k?'selected':'' ?>><?= sgl_ia_e($v) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Área do Direito</label>
                            <select name="area" class="form-select">
                                <?php $areas=['Previdenciário','Trabalhista','Cível','Família','Consumidor','Empresarial','Tributário','Criminal','Administrativo','Imobiliário','Bancário','Outro']; foreach($areas as $a): ?>
                                    <option value="<?= sgl_ia_e($a) ?>"><?= sgl_ia_e($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cliente vinculado</label>
                            <select name="cliente_id" class="form-select">
                                <option value="0">Não vincular</option>
                                <?php foreach($clientes as $c): ?><option value="<?= (int)$c['id'] ?>"><?= sgl_ia_e($c['nome'] . (!empty($c['cpf_cnpj']) ? ' - ' . $c['cpf_cnpj'] : '')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Processo vinculado</label>
                            <select name="processo_id" class="form-select">
                                <option value="0">Não vincular</option>
                                <?php foreach($processos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= sgl_ia_e(($p['numero_processo'] ?: 'Sem número') . ' - ' . ($p['cliente_nome'] ?: 'Sem cliente')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Modelo jurídico base</label>
                            <select name="modelo_id" class="form-select">
                                <option value="0">Sem modelo</option>
                                <?php foreach($modelos as $m): ?><option value="<?= (int)$m['id'] ?>"><?= sgl_ia_e($m['titulo'] . ' (' . ($m['categoria'] ?? '-') . ')') ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Título interno</label>
                            <input name="titulo" class="form-control" placeholder="Ex.: Inicial BPC/LOAS, revisão contrato...">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Objetivo</label>
                            <textarea name="objetivo" class="form-control" rows="3" placeholder="Explique o que deseja: gerar inicial, revisar contrato, resumir processo, criar checklist..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Texto/base, fatos ou observações</label>
                            <textarea name="texto_base" class="form-control" rows="7" placeholder="Cole aqui fatos do caso, texto para revisar, cláusulas, decisões, histórico do cliente, documentos etc."></textarea>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Tom da resposta</label>
                            <input name="tom" class="form-control" value="Técnico, objetivo e profissional">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-primary w-100"><i class="bi bi-stars"></i> Gerar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ia-card mt-4">
            <div class="card-header bg-dark text-white"><strong><i class="bi bi-lightning-charge"></i> Atalhos rápidos</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><button type="button" class="ia-template-btn" onclick="sglIASet('peticao','Gerar petição inicial com fatos, fundamentos e pedidos.','Previdenciário')"><strong>Petição Inicial</strong><br><small class="text-muted">Estrutura completa</small></button></div>
                    <div class="col-md-4"><button type="button" class="ia-template-btn" onclick="sglIASet('revisao','Revisar o texto, melhorar clareza, corrigir inconsistências e sugerir melhorias.','Cível')"><strong>Revisar texto</strong><br><small class="text-muted">Melhoria jurídica</small></button></div>
                    <div class="col-md-4"><button type="button" class="ia-template-btn" onclick="sglIASet('checklist','Criar checklist de documentos necessários para o caso.','Previdenciário')"><strong>Checklist</strong><br><small class="text-muted">Documentos e provas</small></button></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card ia-card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-file-earmark-text"></i> Resultado</strong>
                <?php if ($resposta): ?><span class="badge bg-light text-dark"><?= sgl_ia_e($modoResposta) ?></span><?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($resposta): ?>
                    <div class="d-flex gap-2 mb-2">
                        <button class="btn btn-outline-primary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('iaOut').innerText)"><i class="bi bi-clipboard"></i> Copiar</button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
                    </div>
                    <div id="iaOut" class="ia-output"><?= sgl_ia_e($resposta) ?></div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-robot" style="font-size:3rem"></i>
                        <h5 class="mt-3">Nenhuma geração ainda</h5>
                        <p>Preencha o formulário e clique em <strong>Gerar</strong>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ia-card mt-4">
            <div class="card-header bg-dark text-white"><strong><i class="bi bi-clock-history"></i> Histórico recente</strong></div>
            <div class="list-group list-group-flush">
                <?php if (!$historico): ?>
                    <div class="p-3 text-muted">Nenhuma consulta registrada.</div>
                <?php else: foreach($historico as $h): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= sgl_ia_e($perfis[$h['tipo']] ?? $h['tipo']) ?></strong><span class="badge bg-secondary"><?= sgl_ia_e($h['modo']) ?></span></div>
                        <div class="small text-muted"><?= sgl_ia_e($h['titulo'] ?: 'Sem título') ?> · <?= sgl_ia_e($h['usuario_nome'] ?: '-') ?> · <?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function sglIASet(tipo, objetivo, area){
    const f = document.getElementById('formIA');
    f.querySelector('[name="tipo"]').value = tipo;
    f.querySelector('[name="objetivo"]').value = objetivo;
    f.querySelector('[name="area"]').value = area;
    f.querySelector('[name="texto_base"]').focus();
}
</script>
