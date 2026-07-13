<?php
/**
 * modules/cij/biblioteca.php
 * Biblioteca Inteligente — ROJEX.AI Enterprise
 * Sprint 4.1.4 — Etapa 7
 *
 * Objetivos:
 * - Consultar modelos jurídicos já existentes no banco.
 * - Organizar por título, código, categoria, área, status e favoritos.
 * - Permitir visualizar, copiar e encaminhar para edição/geração.
 * - Não criar nem alterar tabelas nesta etapa.
 * - Registrar uso no LOG Enterprise.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = conectar();
}

$arquivoBaseConhecimento = __DIR__ . '/../../config/base_conhecimento.php';
if (is_file($arquivoBaseConhecimento)) {
    require_once $arquivoBaseConhecimento;
}

function cij_biblioteca_h($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cij_biblioteca_tabela_disponivel(mysqli $conn): bool
{
    if (function_exists('rojex_kb_tabela_existe')) {
        return rojex_kb_tabela_existe($conn, 'modelos_documentos');
    }

    try {
        $res = $conn->query("SHOW TABLES LIKE 'modelos_documentos'");
        return $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cij_biblioteca_colunas(mysqli $conn): array
{
    if (function_exists('rojex_kb_colunas_tabela')) {
        return rojex_kb_colunas_tabela($conn, 'modelos_documentos');
    }

    $colunas = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM modelos_documentos");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $colunas[] = (string)($row['Field'] ?? '');
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter($colunas));
}

function cij_biblioteca_bind(mysqli_stmt $stmt, string $tipos, array $parametros): bool
{
    if ($tipos === '') {
        return true;
    }

    $refs = [];
    foreach ($parametros as $indice => $valor) {
        $refs[$indice] = &$parametros[$indice];
    }

    return $stmt->bind_param($tipos, ...$refs);
}

function cij_biblioteca_consultar(
    mysqli $conn,
    string $termo,
    string $categoria,
    string $area,
    string $status,
    bool $somenteFavoritos,
    int $limite = 100
): array {
    if (!cij_biblioteca_tabela_disponivel($conn)) {
        return [];
    }

    $colunas = cij_biblioteca_colunas($conn);
    if (!in_array('id', $colunas, true)) {
        return [];
    }

    $camposDesejados = [
        'id', 'codigo', 'titulo', 'categoria', 'area_direito', 'conteudo',
        'observacoes', 'status', 'favorito', 'versao_atual',
        'ultimo_uso_em', 'criado_em', 'atualizado_em'
    ];

    $select = [];
    foreach ($camposDesejados as $campo) {
        if (in_array($campo, $colunas, true)) {
            $select[] = "`{$campo}`";
        }
    }

    if ($select === []) {
        return [];
    }

    $where = [];
    $params = [];
    $types = '';

    if (in_array('deletado', $colunas, true)) {
        $where[] = 'COALESCE(deletado, 0) = 0';
    }

    if ($termo !== '') {
        $camposPesquisa = array_values(array_intersect(
            ['codigo', 'titulo', 'conteudo', 'observacoes', 'categoria', 'area_direito'],
            $colunas
        ));

        $busca = [];
        $like = '%' . $termo . '%';

        foreach ($camposPesquisa as $campo) {
            $busca[] = "`{$campo}` LIKE ?";
            $params[] = $like;
            $types .= 's';
        }

        if ($busca !== []) {
            $where[] = '(' . implode(' OR ', $busca) . ')';
        }
    }

    if ($categoria !== '' && in_array('categoria', $colunas, true)) {
        $where[] = 'categoria = ?';
        $params[] = $categoria;
        $types .= 's';
    }

    if ($area !== '' && in_array('area_direito', $colunas, true)) {
        $where[] = 'area_direito = ?';
        $params[] = $area;
        $types .= 's';
    }

    if ($status !== '' && in_array('status', $colunas, true)) {
        $where[] = 'status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($somenteFavoritos && in_array('favorito', $colunas, true)) {
        $where[] = 'COALESCE(favorito, 0) = 1';
    }

    $ordem = [];
    if (in_array('favorito', $colunas, true)) {
        $ordem[] = 'COALESCE(favorito, 0) DESC';
    }
    if (in_array('atualizado_em', $colunas, true)) {
        $ordem[] = 'atualizado_em DESC';
    }
    $ordem[] = 'id DESC';

    $limite = max(1, min($limite, 200));

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM modelos_documentos'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY ' . implode(', ', $ordem)
        . ' LIMIT ' . $limite;

    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!cij_biblioteca_bind($stmt, $types, $params)) {
            $stmt->close();
            return [];
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $dados = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $dados;
    } catch (Throwable $e) {
        error_log('[CIJ Biblioteca] ' . $e->getMessage());
        return [];
    }
}

function cij_biblioteca_opcoes(mysqli $conn, string $campo): array
{
    $permitidos = ['categoria', 'area_direito', 'status'];
    if (!in_array($campo, $permitidos, true)) {
        return [];
    }

    $colunas = cij_biblioteca_colunas($conn);
    if (!in_array($campo, $colunas, true)) {
        return [];
    }

    $filtro = in_array('deletado', $colunas, true)
        ? ' WHERE COALESCE(deletado, 0) = 0'
        : '';

    try {
        $res = $conn->query(
            "SELECT DISTINCT `{$campo}` AS valor
             FROM modelos_documentos
             {$filtro}
             AND COALESCE(`{$campo}`, '') <> ''
             ORDER BY `{$campo}` ASC"
        );

        if (!$res) {
            return [];
        }

        $dados = [];
        while ($row = $res->fetch_assoc()) {
            $valor = trim((string)($row['valor'] ?? ''));
            if ($valor !== '') {
                $dados[] = $valor;
            }
        }

        return $dados;
    } catch (Throwable $e) {
        return [];
    }
}

function cij_biblioteca_resumo(mysqli $conn): array
{
    $resumo = [
        'total' => 0,
        'ativos' => 0,
        'favoritos' => 0,
        'areas' => 0,
    ];

    if (!cij_biblioteca_tabela_disponivel($conn)) {
        return $resumo;
    }

    $colunas = cij_biblioteca_colunas($conn);
    $where = in_array('deletado', $colunas, true)
        ? ' WHERE COALESCE(deletado, 0) = 0'
        : ' WHERE 1 = 1';

    try {
        $resumo['total'] = (int)(($conn->query(
            "SELECT COUNT(*) AS total FROM modelos_documentos {$where}"
        )->fetch_assoc()['total'] ?? 0));

        if (in_array('status', $colunas, true)) {
            $resumo['ativos'] = (int)(($conn->query(
                "SELECT COUNT(*) AS total FROM modelos_documentos {$where} AND status = 'Ativo'"
            )->fetch_assoc()['total'] ?? 0));
        } else {
            $resumo['ativos'] = $resumo['total'];
        }

        if (in_array('favorito', $colunas, true)) {
            $resumo['favoritos'] = (int)(($conn->query(
                "SELECT COUNT(*) AS total FROM modelos_documentos {$where} AND COALESCE(favorito, 0) = 1"
            )->fetch_assoc()['total'] ?? 0));
        }

        if (in_array('area_direito', $colunas, true)) {
            $resumo['areas'] = (int)(($conn->query(
                "SELECT COUNT(DISTINCT area_direito) AS total
                 FROM modelos_documentos
                 {$where}
                 AND COALESCE(area_direito, '') <> ''"
            )->fetch_assoc()['total'] ?? 0));
        }
    } catch (Throwable $e) {
        return $resumo;
    }

    return $resumo;
}

$termo = trim((string)($_GET['q'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? ''));
$area = trim((string)($_GET['area_direito'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'Ativo'));
$somenteFavoritos = (string)($_GET['favoritos'] ?? '') === '1';

$modelos = cij_biblioteca_consultar(
    $conn,
    $termo,
    $categoria,
    $area,
    $status,
    $somenteFavoritos,
    100
);

$categorias = cij_biblioteca_opcoes($conn, 'categoria');
$areas = cij_biblioteca_opcoes($conn, 'area_direito');
$statusDisponiveis = cij_biblioteca_opcoes($conn, 'status');
$resumo = cij_biblioteca_resumo($conn);

$visualizarId = (int)($_GET['visualizar'] ?? 0);
$modeloVisualizado = null;

if ($visualizarId > 0) {
    foreach ($modelos as $modeloItem) {
        if ((int)($modeloItem['id'] ?? 0) === $visualizarId) {
            $modeloVisualizado = $modeloItem;
            break;
        }
    }

    if (!$modeloVisualizado) {
        $todos = cij_biblioteca_consultar($conn, '', '', '', '', false, 200);
        foreach ($todos as $modeloItem) {
            if ((int)($modeloItem['id'] ?? 0) === $visualizarId) {
                $modeloVisualizado = $modeloItem;
                break;
            }
        }
    }

    if ($modeloVisualizado && function_exists('sgl_registrar_log')) {
        sgl_registrar_log(
            $conn,
            'CONSULTA_BIBLIOTECA_CIJ',
            'modelos_documentos',
            (string)$visualizarId,
            'Modelo consultado pela Biblioteca Inteligente do CIJ: '
                . (string)($modeloVisualizado['titulo'] ?? '')
        );
    }
}
?>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-bold text-primary">
                <i class="bi bi-journal-richtext me-2"></i>Biblioteca Inteligente
            </h2>
            <p class="text-muted mb-0">
                Consulta organizada dos modelos jurídicos já cadastrados no ROJEX.AI.
            </p>
        </div>
        <a href="?mod=cij" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao CIJ
        </a>
    </div>

    <?php if (!cij_biblioteca_tabela_disponivel($conn)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i>
            A tabela <strong>modelos_documentos</strong> ainda não está disponível.
            Abra primeiro o módulo Modelos Jurídicos para validar sua estrutura.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">MODELOS</small>
                    <div class="display-6 fw-bold"><?= (int)$resumo['total'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">ATIVOS</small>
                    <div class="display-6 fw-bold text-success"><?= (int)$resumo['ativos'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">FAVORITOS</small>
                    <div class="display-6 fw-bold text-warning"><?= (int)$resumo['favoritos'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted d-block">ÁREAS</small>
                    <div class="display-6 fw-bold text-primary"><?= (int)$resumo['areas'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <i class="bi bi-search me-2"></i>Pesquisa da biblioteca
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="mod" value="cij">
                <input type="hidden" name="ferramenta" value="biblioteca">

                <div class="col-lg-4">
                    <label class="form-label fw-semibold">Título, código ou conteúdo</label>
                    <input
                        type="text"
                        name="q"
                        class="form-control"
                        value="<?= cij_biblioteca_h($termo) ?>"
                        placeholder="Ex.: contestação, procuração, trabalhista...">
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $opcao): ?>
                            <option value="<?= cij_biblioteca_h($opcao) ?>" <?= $categoria === $opcao ? 'selected' : '' ?>>
                                <?= cij_biblioteca_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">Área do Direito</label>
                    <select name="area_direito" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($areas as $opcao): ?>
                            <option value="<?= cij_biblioteca_h($opcao) ?>" <?= $area === $opcao ? 'selected' : '' ?>>
                                <?= cij_biblioteca_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($statusDisponiveis as $opcao): ?>
                            <option value="<?= cij_biblioteca_h($opcao) ?>" <?= $status === $opcao ? 'selected' : '' ?>>
                                <?= cij_biblioteca_h($opcao) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2">
                    <div class="form-check mb-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="favoritosBiblioteca"
                            name="favoritos"
                            value="1"
                            <?= $somenteFavoritos ? 'checked' : '' ?>>
                        <label class="form-check-label" for="favoritosBiblioteca">
                            Somente favoritos
                        </label>
                    </div>
                    <button class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Pesquisar
                    </button>
                </div>

                <div class="col-12">
                    <a href="?mod=cij&ferramenta=biblioteca" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Limpar filtros
                    </a>
                    <a href="?mod=modelos" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Abrir Modelos Jurídicos
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($modeloVisualizado): ?>
        <div class="card border-0 shadow-sm mb-4" id="visualizacaoBiblioteca">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <?= cij_biblioteca_h($modeloVisualizado['titulo'] ?? 'Modelo jurídico') ?>
                </span>
                <span class="badge bg-primary">
                    <?= cij_biblioteca_h($modeloVisualizado['codigo'] ?? '') ?>
                </span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnCopiarBiblioteca">
                        <i class="bi bi-clipboard me-1"></i>Copiar conteúdo
                    </button>
                    <a
                        class="btn btn-outline-success btn-sm"
                        href="?mod=modelos&visualizar=<?= (int)($modeloVisualizado['id'] ?? 0) ?>">
                        <i class="bi bi-magic me-1"></i>Gerar documento
                    </a>
                    <a
                        class="btn btn-outline-warning btn-sm"
                        href="?mod=modelos&editar=<?= (int)($modeloVisualizado['id'] ?? 0) ?>">
                        <i class="bi bi-pencil me-1"></i>Editar no módulo
                    </a>
                    <a
                        class="btn btn-outline-secondary btn-sm"
                        href="?mod=cij&ferramenta=biblioteca">
                        <i class="bi bi-x-circle me-1"></i>Fechar visualização
                    </a>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <small class="text-muted d-block">Categoria</small>
                            <strong><?= cij_biblioteca_h($modeloVisualizado['categoria'] ?? '-') ?></strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <small class="text-muted d-block">Área</small>
                            <strong><?= cij_biblioteca_h($modeloVisualizado['area_direito'] ?? '-') ?></strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <small class="text-muted d-block">Versão</small>
                            <strong>v<?= (int)($modeloVisualizado['versao_atual'] ?? 1) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <small class="text-muted d-block">Status</small>
                            <strong><?= cij_biblioteca_h($modeloVisualizado['status'] ?? '-') ?></strong>
                        </div>
                    </div>
                </div>

                <textarea
                    id="conteudoBibliotecaCij"
                    class="form-control"
                    rows="22"><?= cij_biblioteca_h($modeloVisualizado['conteudo'] ?? '') ?></textarea>

                <?php if (!empty($modeloVisualizado['observacoes'])): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <strong>Observações internas:</strong>
                        <?= cij_biblioteca_h($modeloVisualizado['observacoes']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-list-ul me-2"></i>Resultados da biblioteca</span>
            <span class="badge bg-primary"><?= count($modelos) ?> encontrado(s)</span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Título</th>
                        <th>Categoria</th>
                        <th>Área</th>
                        <th>Versão</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$modelos): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 opacity-25"></i>
                                Nenhum modelo compatível foi localizado.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($modelos as $modelo): ?>
                        <tr>
                            <td>
                                <strong><?= cij_biblioteca_h($modelo['codigo'] ?? '-') ?></strong>
                                <?php if (!empty($modelo['favorito'])): ?>
                                    <i class="bi bi-star-fill text-warning ms-1" title="Favorito"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-primary">
                                    <?= cij_biblioteca_h($modelo['titulo'] ?? '-') ?>
                                </div>
                                <?php if (!empty($modelo['observacoes'])): ?>
                                    <small class="text-muted">
                                        <?= cij_biblioteca_h(mb_strimwidth(
                                            strip_tags((string)$modelo['observacoes']),
                                            0,
                                            100,
                                            '...',
                                            'UTF-8'
                                        )) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= cij_biblioteca_h($modelo['categoria'] ?? '-') ?></td>
                            <td><?= cij_biblioteca_h($modelo['area_direito'] ?? '-') ?></td>
                            <td>v<?= (int)($modelo['versao_atual'] ?? 1) ?></td>
                            <td>
                                <?php $ativo = ($modelo['status'] ?? '') === 'Ativo'; ?>
                                <span class="badge bg-<?= $ativo ? 'success' : 'secondary' ?>">
                                    <?= cij_biblioteca_h($modelo['status'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a
                                        href="?mod=cij&ferramenta=biblioteca&visualizar=<?= (int)($modelo['id'] ?? 0) ?>"
                                        class="btn btn-sm btn-outline-primary"
                                        title="Visualizar">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a
                                        href="?mod=modelos&visualizar=<?= (int)($modelo['id'] ?? 0) ?>"
                                        class="btn btn-sm btn-outline-success"
                                        title="Gerar documento">
                                        <i class="bi bi-magic"></i>
                                    </a>
                                    <a
                                        href="?mod=modelos&editar=<?= (int)($modelo['id'] ?? 0) ?>"
                                        class="btn btn-sm btn-outline-warning"
                                        title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-info mt-4 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        A Biblioteca Inteligente consulta os modelos já existentes. Inclusão, alteração, exclusão,
        favoritos e versionamento continuam centralizados no módulo Modelos Jurídicos.
    </div>
</div>

<?php if ($modeloVisualizado): ?>
<script>
(function(){
    const campo = document.getElementById('conteudoBibliotecaCij');
    const botao = document.getElementById('btnCopiarBiblioteca');

    if (!campo || !botao) return;

    botao.addEventListener('click', async function(){
        try {
            await navigator.clipboard.writeText(campo.value);
            const original = botao.innerHTML;
            botao.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
            setTimeout(function(){ botao.innerHTML = original; }, 1800);
        } catch (e) {
            campo.select();
            document.execCommand('copy');
        }
    });

    document.getElementById('visualizacaoBiblioteca')?.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
})();
</script>
<?php endif; ?>
