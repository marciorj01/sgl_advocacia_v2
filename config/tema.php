<?php
/**
 * config/tema.php
 * Identidade visual centralizada do ROJEX.AI ERP Jurídico Enterprise.
 *
 * Aplica globalmente:
 * - cores institucionais;
 * - modo claro, escuro ou automático;
 * - densidade;
 * - estilo das bordas;
 * - escala da fonte;
 * - logo oficial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Empresa.php';

if (!function_exists('rojexTemaHexValido')) {
    function rojexTemaHexValido(string $valor, string $padrao): string
    {
        $valor = trim($valor);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $valor)
            ? strtolower($valor)
            : strtolower($padrao);
    }
}

if (!function_exists('rojexTemaBuscarConfiguracoesInstitucionais')) {
    /**
     * Busca somente a identidade visual institucional do escritório.
     *
     * @return array<string,string>
     */
    function rojexTemaBuscarConfiguracoesInstitucionais(mysqli $connTema): array
    {
        $chaves = [
            'cor_primaria',
            'cor_secundaria',
            'cor_accent',
            'cor_fundo',
            'cor_texto',
        ];

        $configuracoes = [];
        $placeholders = implode(',', array_fill(0, count($chaves), '?'));
        $tipos = str_repeat('s', count($chaves));

        $stmt = $connTema->prepare(
            "SELECT chave, valor
               FROM configuracoes
              WHERE chave IN ({$placeholders})"
        );
        $stmt->bind_param($tipos, ...$chaves);
        $stmt->execute();
        $resultado = $stmt->get_result();

        while ($linha = $resultado->fetch_assoc()) {
            $chave = (string)($linha['chave'] ?? '');
            if ($chave !== '') {
                $configuracoes[$chave] = (string)($linha['valor'] ?? '');
            }
        }

        $stmt->close();

        return $configuracoes;
    }
}

if (!function_exists('rojexTemaBuscarPreferenciasUsuario')) {
    /**
     * Busca somente as preferências do usuário autenticado.
     *
     * A ausência de registro é válida e mantém os padrões seguros.
     *
     * @return array<string,string>
     */
    function rojexTemaBuscarPreferenciasUsuario(mysqli $connTema, int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $stmt = $connTema->prepare(
            "SELECT tema_modo,
                    tema_densidade,
                    tema_bordas,
                    tema_fonte_percentual
               FROM usuarios_preferencias
              WHERE usuario_id = ?
              LIMIT 1"
        );
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$linha) {
            return [];
        }

        return [
            'tema_modo' => (string)($linha['tema_modo'] ?? ''),
            'tema_densidade' => (string)($linha['tema_densidade'] ?? ''),
            'tema_bordas' => (string)($linha['tema_bordas'] ?? ''),
            'tema_fonte_percentual' => (string)($linha['tema_fonte_percentual'] ?? ''),
        ];
    }
}

if (!function_exists('rojexTemaCarregar')) {
    /**
     * Carrega identidade institucional e preferências individuais em uma
     * única conexão, mantendo as duas responsabilidades separadas.
     *
     * @return array{institucional:array<string,string>,preferencias:array<string,string>}
     */
    function rojexTemaCarregar(): array
    {
        $dados = [
            'institucional' => [],
            'preferencias' => [],
        ];

        if (!function_exists('conectar')) {
            return $dados;
        }

        $connTema = null;

        try {
            $connTema = conectar();
            $dados['institucional'] = rojexTemaBuscarConfiguracoesInstitucionais($connTema);

            $usuarioId = isset($_SESSION['user_id'])
                ? (int)$_SESSION['user_id']
                : 0;

            $dados['preferencias'] = rojexTemaBuscarPreferenciasUsuario(
                $connTema,
                $usuarioId
            );
        } catch (Throwable $e) {
            error_log('[ROJEX TEMA] ' . $e->getMessage());
        } finally {
            if ($connTema instanceof mysqli) {
                try {
                    $connTema->close();
                } catch (Throwable $e) {
                    // A falha ao fechar a conexão não deve afetar a interface.
                }
            }
        }

        return $dados;
    }
}

$temaPadrao = [
    'cor_primaria' => '#1a3c5e',
    'cor_secundaria' => '#2c6fad',
    'cor_accent' => '#f0a500',
    'cor_fundo' => '#f4f6f9',
    'cor_texto' => '#212529',
    'tema_modo' => 'claro',
    'tema_densidade' => 'confortavel',
    'tema_bordas' => 'suaves',
    'tema_fonte_percentual' => '100',
];

try {
    $empresaTema = Empresa::criar();

    $temaPadrao['cor_primaria'] = rojexTemaHexValido(
        (string)$empresaTema->corPrimaria(),
        $temaPadrao['cor_primaria']
    );
    $temaPadrao['cor_secundaria'] = rojexTemaHexValido(
        (string)$empresaTema->corSecundaria(),
        $temaPadrao['cor_secundaria']
    );
    $temaPadrao['cor_accent'] = rojexTemaHexValido(
        (string)$empresaTema->corAccent(),
        $temaPadrao['cor_accent']
    );

    $logo_src = (string)$empresaTema->logoPrincipal();
} catch (Throwable $e) {
    error_log('[ROJEX TEMA EMPRESA] ' . $e->getMessage());
    $logo_src = 'assets/img/logo_rojex_ai.png';
}

$temaCarregado = rojexTemaCarregar();

$tema = array_merge(
    $temaPadrao,
    $temaCarregado['institucional'],
    $temaCarregado['preferencias']
);

$cor_primaria = rojexTemaHexValido(
    (string)$tema['cor_primaria'],
    $temaPadrao['cor_primaria']
);
$cor_secundaria = rojexTemaHexValido(
    (string)$tema['cor_secundaria'],
    $temaPadrao['cor_secundaria']
);
$cor_accent = rojexTemaHexValido(
    (string)$tema['cor_accent'],
    $temaPadrao['cor_accent']
);
$cor_fundo = rojexTemaHexValido(
    (string)$tema['cor_fundo'],
    $temaPadrao['cor_fundo']
);
$cor_texto = rojexTemaHexValido(
    (string)$tema['cor_texto'],
    $temaPadrao['cor_texto']
);

$tema_modo = in_array(
    (string)$tema['tema_modo'],
    ['claro', 'escuro', 'automatico'],
    true
) ? (string)$tema['tema_modo'] : 'claro';

$tema_densidade = in_array(
    (string)$tema['tema_densidade'],
    ['compacta', 'confortavel'],
    true
) ? (string)$tema['tema_densidade'] : 'confortavel';

$tema_bordas = in_array(
    (string)$tema['tema_bordas'],
    ['retas', 'suaves', 'arredondadas'],
    true
) ? (string)$tema['tema_bordas'] : 'suaves';

$tema_fonte_percentual = max(
    90,
    min(115, (int)$tema['tema_fonte_percentual'])
);

$raioTema = match ($tema_bordas) {
    'retas' => '0px',
    'arredondadas' => '18px',
    default => '8px',
};

$espacamentoTema = $tema_densidade === 'compacta' ? '0.70rem' : '1rem';
$alturaControle = $tema_densidade === 'compacta' ? '2.15rem' : '2.45rem';

$logoSeguro = htmlspecialchars(
    $logo_src,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<script>
(function () {
    const modoConfigurado = <?= json_encode(
        $tema_modo,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const aplicarModo = function () {
        const escuro = modoConfigurado === 'escuro'
            || (
                modoConfigurado === 'automatico'
                && window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches
            );

        document.documentElement.dataset.rojexTheme = escuro ? 'dark' : 'light';
        document.documentElement.dataset.bsTheme = escuro ? 'dark' : 'light';
        document.documentElement.dataset.rojexDensity = <?= json_encode($tema_densidade) ?>;
        document.documentElement.dataset.rojexBorders = <?= json_encode($tema_bordas) ?>;
    };

    aplicarModo();

    if (modoConfigurado === 'automatico' && window.matchMedia) {
        const media = window.matchMedia('(prefers-color-scheme: dark)');

        if (typeof media.addEventListener === 'function') {
            media.addEventListener('change', aplicarModo);
        } else if (typeof media.addListener === 'function') {
            media.addListener(aplicarModo);
        }
    }
})();
</script>

<style>
:root {
    color-scheme: light;

    --rojex-primary: <?= htmlspecialchars($cor_primaria, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-secondary: <?= htmlspecialchars($cor_secundaria, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-accent: <?= htmlspecialchars($cor_accent, ENT_QUOTES, 'UTF-8') ?>;

    --rojex-page-bg: <?= htmlspecialchars($cor_fundo, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-surface: #ffffff;
    --rojex-surface-alt: #f8f9fa;
    --rojex-text: <?= htmlspecialchars($cor_texto, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-muted: #667085;
    --rojex-border: #dee2e6;
    --rojex-input-bg: #ffffff;
    --rojex-shadow: 0 0.35rem 1rem rgba(16, 24, 40, .08);

    --rojex-radius: <?= htmlspecialchars($raioTema, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-spacing: <?= htmlspecialchars($espacamentoTema, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-control-height: <?= htmlspecialchars($alturaControle, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-font-scale: <?= (int)$tema_fonte_percentual ?>%;

    --sgl-primary: var(--rojex-primary);
    --sgl-secondary: var(--rojex-secondary);
    --sgl-accent: var(--rojex-accent);
}

html[data-rojex-theme="dark"] {
    color-scheme: dark;

    --rojex-page-bg: #11161d;
    --rojex-surface: #1a212b;
    --rojex-surface-alt: #222b36;
    --rojex-text: #edf1f5;
    --rojex-muted: #aeb8c4;
    --rojex-border: #364150;
    --rojex-input-bg: #202833;
    --rojex-shadow: 0 0.35rem 1rem rgba(0, 0, 0, .28);

    --bs-body-bg: var(--rojex-page-bg);
    --bs-body-color: var(--rojex-text);
    --bs-border-color: var(--rojex-border);
    --bs-secondary-color: var(--rojex-muted);
    --bs-tertiary-bg: var(--rojex-surface-alt);
}

html {
    font-size: var(--rojex-font-scale);
}

body {
    background-color: var(--rojex-page-bg) !important;
    color: var(--rojex-text) !important;
    transition: background-color .18s ease, color .18s ease;
}

.sgl-main,
main,
main.sgl-main {
    background: var(--rojex-page-bg) !important;
    color: var(--rojex-text) !important;
}

.sgl-sidebar,
.sidebar {
    background: linear-gradient(
        180deg,
        var(--rojex-primary),
        color-mix(in srgb, var(--rojex-primary) 55%, #02070b)
    ) !important;
}

.sgl-sidebar .nav-link.active,
.sidebar .nav-link.active {
    background: var(--rojex-secondary) !important;
}

.sgl-sidebar .brand,
.sidebar h5,
.text-warning {
    color: var(--rojex-accent) !important;
}

.card,
.modal-content,
.dropdown-menu,
.list-group-item,
.accordion-item,
.offcanvas,
.toast {
    background-color: var(--rojex-surface) !important;
    color: var(--rojex-text) !important;
    border-color: var(--rojex-border) !important;
    border-radius: var(--rojex-radius) !important;
}

.card {
    box-shadow: var(--rojex-shadow);
}

.card-header,
.card-footer,
.modal-header,
.modal-footer,
.accordion-button {
    border-color: var(--rojex-border) !important;
}

.card-header.bg-primary {
    background-color: var(--rojex-primary) !important;
    color: #ffffff !important;
}

.bg-white,
.table-light,
.list-group-item-light {
    background-color: var(--rojex-surface) !important;
    color: var(--rojex-text) !important;
}

.bg-light {
    background-color: var(--rojex-surface-alt) !important;
    color: var(--rojex-text) !important;
}

.text-dark,
.text-body {
    color: var(--rojex-text) !important;
}

.text-muted,
.form-text,
small.text-muted {
    color: var(--rojex-muted) !important;
}

.border,
.border-top,
.border-bottom,
.border-start,
.border-end {
    border-color: var(--rojex-border) !important;
}

.table {
    --bs-table-bg: var(--rojex-surface);
    --bs-table-color: var(--rojex-text);
    --bs-table-border-color: var(--rojex-border);
    --bs-table-striped-bg: var(--rojex-surface-alt);
    --bs-table-striped-color: var(--rojex-text);
    --bs-table-hover-bg: color-mix(
        in srgb,
        var(--rojex-secondary) 13%,
        var(--rojex-surface)
    );
    --bs-table-hover-color: var(--rojex-text);

    color: var(--rojex-text) !important;
    border-color: var(--rojex-border) !important;
}

.table > :not(caption) > * > * {
    background-color: var(--bs-table-bg);
    color: var(--bs-table-color);
    border-color: var(--rojex-border);
}

.form-control,
.form-select,
.input-group-text,
.form-check-input,
textarea,
select,
input {
    background-color: var(--rojex-input-bg);
    color: var(--rojex-text);
    border-color: var(--rojex-border);
    border-radius: var(--rojex-radius);
}

.form-control,
.form-select,
.input-group-text {
    min-height: var(--rojex-control-height);
}

.form-control:focus,
.form-select:focus,
.form-check-input:focus {
    background-color: var(--rojex-input-bg);
    color: var(--rojex-text);
    border-color: var(--rojex-secondary);
    box-shadow: 0 0 0 .2rem color-mix(
        in srgb,
        var(--rojex-secondary) 22%,
        transparent
    );
}

.form-control::placeholder {
    color: var(--rojex-muted);
    opacity: .82;
}

.form-control:disabled,
.form-select:disabled,
.form-control[readonly] {
    background-color: var(--rojex-surface-alt);
    color: var(--rojex-muted);
}

.btn,
.form-control,
.form-select,
.input-group-text,
.alert,
.badge,
.nav-tabs .nav-link,
.pagination .page-link {
    border-radius: var(--rojex-radius) !important;
}

.btn-primary {
    background-color: var(--rojex-secondary) !important;
    border-color: var(--rojex-secondary) !important;
    color: #ffffff !important;
}

.btn-primary:hover,
.btn-primary:focus {
    filter: brightness(.92);
}

.btn-outline-primary {
    color: var(--rojex-secondary) !important;
    border-color: var(--rojex-secondary) !important;
}

.btn-outline-primary:hover {
    background-color: var(--rojex-secondary) !important;
    color: #ffffff !important;
}

.btn-outline-secondary {
    color: var(--rojex-muted);
    border-color: var(--rojex-border);
}

html[data-rojex-theme="dark"] .btn-outline-secondary:hover {
    background-color: var(--rojex-surface-alt);
    color: var(--rojex-text);
}

.nav-tabs {
    border-bottom-color: var(--rojex-border);
}

.nav-tabs .nav-link {
    color: var(--rojex-muted);
}

.nav-tabs .nav-link.active {
    background-color: var(--rojex-surface);
    color: var(--rojex-secondary);
    border-color:
        var(--rojex-border)
        var(--rojex-border)
        var(--rojex-surface);
}

.alert {
    border-color: color-mix(
        in srgb,
        currentColor 25%,
        var(--rojex-border)
    );
}

.pagination .page-link {
    background-color: var(--rojex-surface);
    color: var(--rojex-text);
    border-color: var(--rojex-border);
}

.pagination .active > .page-link {
    background-color: var(--rojex-secondary);
    border-color: var(--rojex-secondary);
    color: #ffffff;
}

hr {
    border-color: var(--rojex-border);
    opacity: 1;
}

code {
    color: color-mix(in srgb, var(--rojex-accent) 75%, var(--rojex-text));
}

html[data-rojex-density="compacta"] .card-body,
html[data-rojex-density="compacta"] .card-header,
html[data-rojex-density="compacta"] .card-footer,
html[data-rojex-density="compacta"] .modal-body,
html[data-rojex-density="compacta"] .modal-header,
html[data-rojex-density="compacta"] .modal-footer {
    padding: .70rem !important;
}

html[data-rojex-density="compacta"] .table > :not(caption) > * > * {
    padding: .42rem .55rem;
}

html[data-rojex-density="compacta"] .btn {
    padding-top: .34rem;
    padding-bottom: .34rem;
}

.rojex-powered {
    color: rgba(255,255,255,.72);
    font-size: .72rem;
    letter-spacing: .02em;
}

.rojex-brand-box {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.10);
    border-radius: var(--rojex-radius);
    padding: var(--rojex-spacing);
}

/*
 * Contraste global de leitura no modo escuro.
 *
 * Títulos e links usados como conteúdo passam a branco/cinza claro.
 * Botões, menus ativos e elementos de ação mantêm as cores institucionais.
 */
html[data-rojex-theme="dark"] main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link),
html[data-rojex-theme="dark"] .sgl-main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link),
html[data-rojex-theme="dark"] main .text-primary,
html[data-rojex-theme="dark"] .sgl-main .text-primary,
html[data-rojex-theme="dark"] main .link-primary,
html[data-rojex-theme="dark"] .sgl-main .link-primary,
html[data-rojex-theme="dark"] main h1,
html[data-rojex-theme="dark"] main h2,
html[data-rojex-theme="dark"] main h3,
html[data-rojex-theme="dark"] main h4,
html[data-rojex-theme="dark"] main h5,
html[data-rojex-theme="dark"] main h6,
html[data-rojex-theme="dark"] .sgl-main h1,
html[data-rojex-theme="dark"] .sgl-main h2,
html[data-rojex-theme="dark"] .sgl-main h3,
html[data-rojex-theme="dark"] .sgl-main h4,
html[data-rojex-theme="dark"] .sgl-main h5,
html[data-rojex-theme="dark"] .sgl-main h6 {
    color: var(--rojex-text) !important;
}

html[data-rojex-theme="dark"] main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):hover,
html[data-rojex-theme="dark"] main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):focus,
html[data-rojex-theme="dark"] .sgl-main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):hover,
html[data-rojex-theme="dark"] .sgl-main a:not(.btn):not(.nav-link):not(.dropdown-item):not(.page-link):focus {
    color: var(--rojex-accent) !important;
}

html[data-rojex-theme="dark"] .table a:not(.btn),
html[data-rojex-theme="dark"] .card a:not(.btn),
html[data-rojex-theme="dark"] .list-group-item a:not(.btn) {
    color: #ffffff !important;
}

html[data-rojex-theme="dark"] .table a:not(.btn):hover,
html[data-rojex-theme="dark"] .card a:not(.btn):hover,
html[data-rojex-theme="dark"] .list-group-item a:not(.btn):hover {
    color: var(--rojex-accent) !important;
}

html[data-rojex-theme="dark"] .btn-primary,
html[data-rojex-theme="dark"] .btn-outline-primary:hover,
html[data-rojex-theme="dark"] .nav-link.active,
html[data-rojex-theme="dark"] .page-item.active .page-link {
    color: #ffffff !important;
}


/*
 * Preserva legibilidade de cabeçalhos que já utilizam fundos escuros.
 */
.bg-dark,
.card-header.bg-dark,
.table-dark {
    color: #ffffff !important;
}

/*
 * Impressões e PDFs permanecem claros para economia de tinta e legibilidade.
 */
@media print {
    :root,
    html[data-rojex-theme="dark"] {
        color-scheme: light;
        --rojex-page-bg: #ffffff;
        --rojex-surface: #ffffff;
        --rojex-surface-alt: #f8f9fa;
        --rojex-text: #000000;
        --rojex-muted: #495057;
        --rojex-border: #ced4da;
        --rojex-input-bg: #ffffff;
        --rojex-shadow: none;
    }

    body,
    .sgl-main,
    main {
        background: #ffffff !important;
        color: #000000 !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-rojex-logo]').forEach(function (img) {
        img.src = <?= json_encode(
            $logoSeguro . '?v=' . rawurlencode((string)@filemtime(__DIR__ . '/../' . $logo_src)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?>;
    });
});
</script>
