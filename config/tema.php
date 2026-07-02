<?php
// config/tema.php — gerado pelo instalador SGL v1.1
$cor_primaria   = '#1a3c5e';
$cor_secundaria = '#2c6fad';
$cor_accent     = '#f0a500';
$logo_arquivo   = '';
$logo_src       = 'assets/img/logo_custom.png';
$nome_escritorio = 'SGL Advocacia';
$tema_modo = 'claro';

if (function_exists('conectar')) {
    try {
        $conn_t = conectar();
        $chk = $conn_t->query("SHOW TABLES LIKE 'configuracoes'");
        if ($chk && $chk->num_rows > 0) {
            $r = $conn_t->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('cor_primaria','cor_secundaria','cor_accent','logo_arquivo','nome_escritorio','tema_modo')");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    if ($row['chave'] === 'cor_primaria')   $cor_primaria   = $row['valor'];
                    if ($row['chave'] === 'cor_secundaria') $cor_secundaria = $row['valor'];
                    if ($row['chave'] === 'cor_accent')     $cor_accent     = $row['valor'];
                    if ($row['chave'] === 'logo_arquivo')   $logo_arquivo   = $row['valor'];
                    if ($row['chave'] === 'nome_escritorio') $nome_escritorio = $row['valor'];
                    if ($row['chave'] === 'tema_modo') $tema_modo = $row['valor'];
                }
            }
        }
        $conn_t->close();
    } catch (Exception $e) {}
}

$hex = '/^#[0-9A-Fa-f]{6}$/';
if (!preg_match($hex, $cor_primaria))   $cor_primaria   = '#1a3c5e';
if (!preg_match($hex, $cor_secundaria)) $cor_secundaria = '#2c6fad';
if (!preg_match($hex, $cor_accent))     $cor_accent     = '#f0a500';
if (!in_array($tema_modo, ['claro','escuro'], true)) $tema_modo = 'claro';
$nome_escritorio = trim(strip_tags((string)$nome_escritorio));
if ($nome_escritorio === '') $nome_escritorio = 'SGL Advocacia';

if ($logo_arquivo) {
    $logo_src = 'assets/img/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $logo_arquivo);
}
?>
<style>
:root {
    --sgl-primary:   <?= $cor_primaria ?> !important;
    --sgl-secondary: <?= $cor_secundaria ?> !important;
    --sgl-accent:    <?= $cor_accent ?> !important;
}
.sidebar { background: <?= $cor_primaria ?> !important; }
.sidebar .nav-link.active { background: <?= $cor_secundaria ?> !important; }
.sidebar h5 { color: <?= $cor_accent ?> !important; }
.card-header.bg-primary { background-color: <?= $cor_primaria ?> !important; }
.btn-primary { background-color: <?= $cor_secundaria ?> !important; border-color: <?= $cor_secundaria ?> !important; }
<?php if ($tema_modo === 'escuro'): ?>
body { background:#111827 !important; }
main.bg-light { background:#111827 !important; color:#e5e7eb; }
.card, .table { background:#1f2937 !important; color:#e5e7eb; }
.table-light, .bg-light { background:#374151 !important; color:#f9fafb !important; }
.text-muted { color:#cbd5e1 !important; }
.form-control, .form-select { background:#111827 !important; color:#f9fafb !important; border-color:#4b5563 !important; }
<?php endif; ?>
</style>
<?php if ($logo_arquivo): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.sidebar img').forEach(function (img) {
        img.src = '<?= $logo_src ?>?v=<?= time() ?>';
    });
});
</script>
<?php endif; ?>