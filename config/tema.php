<?php
/**
 * config/tema.php
 * Identidade visual centralizada do ROJEX.AI.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Empresa.php';

try {
    $empresaTema = Empresa::criar();
    $cor_primaria = $empresaTema->corPrimaria();
    $cor_secundaria = $empresaTema->corSecundaria();
    $cor_accent = $empresaTema->corAccent();
    $logo_src = $empresaTema->logoPrincipal();
} catch (Throwable $e) {
    $cor_primaria = '#081f2d';
    $cor_secundaria = '#0d6efd';
    $cor_accent = '#d4af37';
    $logo_src = 'assets/img/logo_rojex_ai.png';
}
?>
<style>
:root {
    --rojex-primary: <?= htmlspecialchars($cor_primaria, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-secondary: <?= htmlspecialchars($cor_secundaria, ENT_QUOTES, 'UTF-8') ?>;
    --rojex-accent: <?= htmlspecialchars($cor_accent, ENT_QUOTES, 'UTF-8') ?>;
    --sgl-primary: var(--rojex-primary);
    --sgl-secondary: var(--rojex-secondary);
    --sgl-accent: var(--rojex-accent);
}
.sidebar { background: linear-gradient(180deg, var(--rojex-primary), #04121b) !important; }
.sidebar .nav-link.active { background: var(--rojex-secondary) !important; }
.sidebar h5,
.text-warning { color: var(--rojex-accent) !important; }
.card-header.bg-primary { background-color: var(--rojex-primary) !important; }
.btn-primary { background-color: var(--rojex-secondary) !important; border-color: var(--rojex-secondary) !important; }
.btn-primary:hover { filter: brightness(.92); }
.rojex-powered { color: rgba(255,255,255,.72); font-size: .72rem; letter-spacing: .02em; }
.rojex-brand-box { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.10); border-radius: 14px; padding: 12px; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-rojex-logo]').forEach(function (img) {
        img.src = '<?= htmlspecialchars($logo_src, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>';
    });
});
</script>
