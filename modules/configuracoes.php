<?php
// modules/configuracoes.php — gerado pelo instalador SGL v1.1
$conn = conectar();
$upload_dir = __DIR__ . '/../assets/img/';

$conn->query("CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(60) NOT NULL, valor TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function cfg_get(mysqli $conn, string $chave, string $default = ''): string {
    $s = @$conn->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
    if (!$s) { return $default; }
    $s->bind_param('s', $chave);
    $s->execute();
    $res = $s->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $s->close();
    return $row ? (string)$row['valor'] : $default;
}
function cfg_set(mysqli $conn, string $chave, string $valor): void {
    $s = @$conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    if (!$s) { return; }
    $s->bind_param('ss', $chave, $valor);
    $s->execute();
    $s->close();
}

$msg = ''; $msg_tipo = 'success';
$acao_cfg = $_POST['acao_cfg'] ?? '';

// Captura mensagens vindas de recarregamento por URL (Query String)
if (isset($_GET['msg_sucesso'])) { $msg = $_GET['msg_sucesso']; $msg_tipo = 'success'; }
if (isset($_GET['msg_aviso'])) { $msg = $_GET['msg_aviso']; $msg_tipo = 'warning'; }
if (isset($_GET['msg_erro'])) { $msg = $_GET['msg_erro']; $msg_tipo = 'danger'; }

// UPLOAD DE LOGO
if ($acao_cfg === 'upload_logo' && isset($_FILES['logo'])) {
    $file = $_FILES['logo'];
    $allowed = ['image/jpeg','image/jpg','image/png'];
    $ext_map = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/png'=>'png'];
    $mime = mime_content_type($file['tmp_name']);
    if ($file['error'] !== UPLOAD_ERR_OK) { $msg = '❌ Erro no upload.'; $msg_tipo='danger'; }
    elseif (!in_array($mime,$allowed,true)) { $msg = '❌ Use apenas JPG ou PNG.'; $msg_tipo='danger'; }
    elseif ($file['size'] > 2*1024*1024) { $msg = '❌ Máximo 2 MB.'; $msg_tipo='danger'; }
    else {
        foreach (glob($upload_dir.'logo_custom.*') as $f) { @unlink($f); }
        $ext = $ext_map[$mime];
        if (move_uploaded_file($file['tmp_name'], $upload_dir.'logo_custom.'.$ext)) {
            cfg_set($conn,'logo_arquivo','logo_custom.'.$ext);
            echo "<script>window.location.href = '?mod=configuracoes&msg_sucesso=' + encodeURIComponent('✅ Logomarca atualizada com sucesso!');</script>";
            exit;
        } else { $msg='❌ Falha ao salvar. Verifique permissões de assets/img/'; $msg_tipo='danger'; }
    }
}

// REMOVER LOGO
if ($acao_cfg === 'remover_logo') {
    foreach (glob($upload_dir.'logo_custom.*') as $f) { @unlink($f); }
    $conn->query("DELETE FROM configuracoes WHERE chave='logo_arquivo'");
    echo "<script>window.location.href = '?mod=configuracoes&msg_sucesso=' + encodeURIComponent('✅ Logo personalizada removida.');</script>";
    exit;
}

// SALVAR DADOS DA EMPRESA
if ($acao_cfg === 'salvar_empresa') {
    $campos_empresa = [
        'nome_escritorio',
        'razao_social',
        'cnpj',
        'telefone',
        'whatsapp',
        'email',
        'site'
    ];

    foreach ($campos_empresa as $campo) {
        $valor = trim((string)($_POST[$campo] ?? ''));
        cfg_set($conn, $campo, $valor);
    }

    echo "<script>window.location.href = '?mod=configuracoes&msg_sucesso=' + encodeURIComponent('✅ Dados da empresa salvos com sucesso!');</script>";
    exit;
}

// SALVAR TEMA
if ($acao_cfg === 'salvar_tema') {
    $cp = preg_replace('/[^#a-fA-F0-9]/','', $_POST['cor_primaria']   ?? '#1a3c5e');
    $cs = preg_replace('/[^#a-fA-F0-9]/','', $_POST['cor_secundaria'] ?? '#2c6fad');
    $ca = preg_replace('/[^#a-fA-F0-9]/','', $_POST['cor_accent']     ?? '#f0a500');
    cfg_set($conn,'cor_primaria',$cp); cfg_set($conn,'cor_secundaria',$cs); cfg_set($conn,'cor_accent',$ca);
    echo "<script>window.location.href = '?mod=configuracoes&msg_sucesso=' + encodeURIComponent('✅ Cores do tema salvas com sucesso!');</script>";
    exit;
}

// RESTAURAR ITEM DA LIXEIRA
if ($acao_cfg === 'restaurar_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $tb = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['tabela']);
    $id = $conn->real_escape_string($_POST['item_id']);
    $allowed_tables = ['advogados', 'clientes', 'processos'];
    
    if (in_array($tb, $allowed_tables, true)) {
        if ($tb === 'clientes') {
            $conn->query("UPDATE `clientes` SET deletado = 0 WHERE id = '$id'");
        } else {
            $conn->query("UPDATE `$tb` SET status = 'Ativo' WHERE id = '$id'");
        }
        echo "<script>window.location.href = '?mod=configuracoes&tab=lixeira&msg_sucesso=' + encodeURIComponent('🔄 Registro restaurado com sucesso!');</script>";
        exit;
    }
}

// EXCLUIR ITEM DEFINITIVAMENTE DA LIXEIRA
if ($acao_cfg === 'excluir_item_lixeira' && !empty($_POST['tabela']) && !empty($_POST['item_id'])) {
    $tb = preg_replace('/[^a-zA-Z0-9_]/','',$_POST['tabela']);
    $id = $conn->real_escape_string($_POST['item_id']);
    $allowed_tables = ['advogados','clientes','processos'];
    
    if (in_array($tb, $allowed_tables, true)) {
        $w = ($tb === 'clientes') ? "deletado = 1" : "status='Excluído'";
        $conn->query("DELETE FROM `$tb` WHERE id='$id' AND $w");
        echo "<script>window.location.href = '?mod=configuracoes&tab=lixeira&msg_aviso=' + encodeURIComponent('💥 Item excluído permanentemente.');</script>";
        exit;
    }
}

// ESVAZIAR TODA A LIXEIRA
if ($acao_cfg === 'esvaziar_lixeira') {
    $total = 0;
    $condicoes = ['advogados' => "status='Excluído'", 'clientes' => "deletado = 1", 'processos' => "status='Excluído'"];
    foreach ($condicoes as $t => $w) {
        $res = $conn->query("SELECT COUNT(*) AS c FROM `$t` WHERE $w");
        if ($res) { $r = $res->fetch_assoc(); $total += (int)$r['c']; }
        $conn->query("DELETE FROM `$t` WHERE $w");
    }
    echo "<script>window.location.href = '?mod=configuracoes&tab=lixeira&msg_erro=' + encodeURIComponent('🗑️ Lixeira limpa por completo! Registros removidos: $total');</script>";
    exit;
}

// BUSCADOR DINÂMICO DE ITENS DA LIXEIRA
function buscar_lixeira(mysqli $conn): array {
    $itens = [];
    $mapeamento = ['advogados' => 'nome', 'clientes' => 'nome', 'processos' => 'numero_processo'];
    foreach ($mapeamento as $t => $c) {
        $w = ($t === 'clientes') ? "deletado = 1" : "status='Excluído'";
        $r = $conn->query("SELECT id, `$c` AS nome FROM `$t` WHERE $w ORDER BY id DESC LIMIT 100");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $itens[] = ['tabela' => $t, 'id' => $row['id'], 'nome' => $row['nome'], 'tipo' => ucfirst($t)];
            }
        }
    }
    return $itens;
}

$lixeira_itens = buscar_lixeira($conn);
$nome_escritorio = cfg_get($conn,'nome_escritorio','SGL Advocacia');
$razao_social = cfg_get($conn,'razao_social','');
$cnpj = cfg_get($conn,'cnpj','');
$telefone = cfg_get($conn,'telefone','');
$whatsapp = cfg_get($conn,'whatsapp','');
$email = cfg_get($conn,'email','');
$site = cfg_get($conn,'site','');
$logo_salva = cfg_get($conn,'logo_arquivo','');
$logo_exibir = $logo_salva ? 'assets/img/'.htmlspecialchars($logo_salva) : 'assets/img/logo_custom.png';
$cor_primaria = cfg_get($conn,'cor_primaria','#1a3c5e');
$cor_secundaria = cfg_get($conn,'cor_secundaria','#2c6fad');
$cor_accent = cfg_get($conn,'cor_accent','#f0a500');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Configurações do Sistema</h4>
</div>

<?php if($msg): ?>
    <div class="alert alert-<?=$msg_tipo?> alert-dismissible fade show">
        <?=htmlspecialchars($msg)?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4" id="cfgTabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" id="tab-empresa-lnk" data-bs-toggle="tab" href="#tab-empresa" role="tab"><i class="bi bi-building me-1"></i>Empresa</a></li>
    <li class="nav-item"><a class="nav-link" id="tab-marca-lnk" data-bs-toggle="tab" href="#tab-marca" role="tab"><i class="bi bi-image me-1"></i>Marca</a></li>
    <li class="nav-item"><a class="nav-link" id="tab-tema-lnk" data-bs-toggle="tab" href="#tab-tema" role="tab"><i class="bi bi-palette me-1"></i>Cores</a></li>
    <li class="nav-item"><a class="nav-link" id="tab-lixeira-lnk" data-bs-toggle="tab" href="#tab-lixeira" role="tab"><i class="bi bi-trash3 me-1"></i>Lixeira <?php if(count($lixeira_itens)>0): ?><span class="badge bg-danger ms-1"><?=count($lixeira_itens)?></span><?php endif; ?></a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-empresa">
    <div class="card">
        <div class="card-header bg-primary text-white"><i class="bi bi-building me-1"></i> Dados da Empresa / Escritório</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="acao_cfg" value="salvar_empresa">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Nome do Escritório</label>
                <input type="text" name="nome_escritorio" class="form-control" value="<?=htmlspecialchars($nome_escritorio)?>" placeholder="Ex: SGL Advocacia">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Razão Social</label>
                <input type="text" name="razao_social" class="form-control" value="<?=htmlspecialchars($razao_social)?>" placeholder="Ex: SGL Advocacia LTDA">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">CNPJ</label>
                <input type="text" name="cnpj" class="form-control" value="<?=htmlspecialchars($cnpj)?>" placeholder="00.000.000/0000-00">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Telefone</label>
                <input type="text" name="telefone" class="form-control" value="<?=htmlspecialchars($telefone)?>" placeholder="(00) 0000-0000">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">WhatsApp</label>
                <input type="text" name="whatsapp" class="form-control" value="<?=htmlspecialchars($whatsapp)?>" placeholder="(00) 00000-0000">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">E-mail</label>
                <input type="email" name="email" class="form-control" value="<?=htmlspecialchars($email)?>" placeholder="contato@escritorio.com.br">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Site</label>
                <input type="text" name="site" class="form-control" value="<?=htmlspecialchars($site)?>" placeholder="https://www.escritorio.com.br">
              </div>
            </div>
            <div class="mt-4">
              <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar Dados da Empresa</button>
            </div>
          </form>
        </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab-marca">
    <div class="card">
        <div class="card-header bg-primary text-white"><i class="bi bi-image me-1"></i> Logomarca (White Label)</div>
        <div class="card-body">
          <div class="row g-4 align-items-start">
            <div class="col-md-4 text-center">
              <p class="text-muted small mb-2">Logo atual:</p>
              <img src="<?=$logo_exibir?>?v=<?=time()?>" class="img-thumbnail" style="max-width:200px;max-height:200px;object-fit:contain;" alt="Logo">
              <?php if($logo_salva): ?>
                <div class="mt-3">
                    <form method="POST">
                        <input type="hidden" name="acao_cfg" value="remover_logo">
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remover logo personalizada?')"><i class="bi bi-x-circle me-1"></i>Remover</button>
                    </form>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-md-8">
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao_cfg" value="upload_logo">
                <div class="mb-3">
                  <label class="form-label fw-semibold">Enviar nova logomarca</label>
                  <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png" required onchange="prevLogo(this)">
                  <div class="form-text">JPG ou PNG, máximo 2 MB.</div>
                </div>
                <div id="prev_wrap" style="display:none;" class="mb-3"><img id="prev_img" src="#" class="img-thumbnail" style="max-width:200px;max-height:120px;object-fit:contain;" alt="Preview"></div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Enviar</button>
              </form>
            </div>
          </div>
        </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab-tema">
    <div class="card">
        <div class="card-header bg-primary text-white"><i class="bi bi-palette me-1"></i> Identidade Visual</div>
        <div class="card-body">
          <form method="POST"><input type="hidden" name="acao_cfg" value="salvar_tema">
            <div class="row g-4">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Cor Primária (Sidebar)</label>
                <div class="input-group"><input type="color" name="cor_primaria" id="cp" class="form-control form-control-color" value="<?=htmlspecialchars($cor_primaria)?>" oninput="syncCor('cp')"><input type="text" id="cp_txt" class="form-control" value="<?=htmlspecialchars($cor_primaria)?>" maxlength="7" style="font-family:monospace;" oninput="syncTxt('cp')"></div>
                <div class="mt-2 rounded p-2 text-white text-center small" id="prev_cp" style="background:<?=htmlspecialchars($cor_primaria)?>;">Sidebar</div>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Cor Secundária (Item Ativo)</label>
                <div class="input-group"><input type="color" name="cor_secundaria" id="cs" class="form-control form-control-color" value="<?=htmlspecialchars($cor_secundaria)?>" oninput="syncCor('cs')"><input type="text" id="cs_txt" class="form-control" value="<?=htmlspecialchars($cor_secundaria)?>" maxlength="7" style="font-family:monospace;" oninput="syncTxt('cs')"></div>
                <div class="mt-2 rounded p-2 text-white text-center small" id="prev_cs" style="background:<?=htmlspecialchars($cor_secundaria)?>;">Item Ativo</div>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Cor de Destaque (Accent)</label>
                <div class="input-group"><input type="color" name="cor_accent" id="ca" class="form-control form-control-color" value="<?=htmlspecialchars($cor_accent)?>" oninput="syncCor('ca')"><input type="text" id="ca_txt" class="form-control" value="<?=htmlspecialchars($cor_accent)?>" maxlength="7" style="font-family:monospace;" oninput="syncTxt('ca')"></div>
                <div class="mt-2 rounded p-2 text-dark text-center small fw-bold" id="prev_ca" style="background:<?=htmlspecialchars($cor_accent)?>;">Destaque</div>
              </div>
            </div>
            <div class="mt-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar Tema</button>
              <button type="button" class="btn btn-outline-secondary" onclick="resetTema()"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar Padrão</button>
            </div>
          </form>
        </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab-lixeira">
    <div class="card">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
          <span><i class="bi bi-trash3 me-1"></i> Lixeira Central</span>
          <?php if(count($lixeira_itens) > 0): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="acao_cfg" value="esvaziar_lixeira">
                <button type="submit" class="btn btn-sm btn-light text-danger fw-bold" onclick="return confirm('⚠️ ATENÇÃO: Apagar TODOS os itens permanentemente da base de dados?')"><i class="bi bi-trash3-fill me-1"></i>Esvaziar Completa (<?=count($lixeira_itens)?>)</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if(empty($lixeira_itens)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-trash3 fs-1 d-block mb-3 opacity-25"></i>
                <p class="mb-0">A lixeira está vazia.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 100px;">ID</th>
                            <th style="width: 120px;">Módulo</th>
                            <th>Nome / Descrição</th>
                            <th class="text-end" style="width: 250px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($lixeira_itens as $item): ?>
                        <tr>
                            <td><code><?=htmlspecialchars($item['id'])?></code></td>
                            <td><span class="badge bg-secondary"><?=htmlspecialchars($item['tipo'])?></span></td>
                            <td><strong><?=htmlspecialchars($item['nome'])?></strong></td>
                            <td class="text-end">
                                <form method="POST" style="display:inline; margin-right: 5px;">
                                    <input type="hidden" name="acao_cfg" value="restaurar_item_lixeira">
                                    <input type="hidden" name="tabela" value="<?=htmlspecialchars($item['tabela'])?>">
                                    <input type="hidden" name="item_id" value="<?=htmlspecialchars($item['id'])?>">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button>
                                </form>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="acao_cfg" value="excluir_item_lixeira">
                                    <input type="hidden" name="tabela" value="<?=htmlspecialchars($item['tabela'])?>">
                                    <input type="hidden" name="item_id" value="<?=htmlspecialchars($item['id'])?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza de que deseja eliminar este item permanentemente?')"><i class="bi bi-trash3"></i> Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
          <?php endif; ?>
        </div>
    </div>
  </div>
</div>

<script>
// Mantém o foco visual correto no separador da lixeira após as operações
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'lixeira') {
        const triggerEl = document.getElementById('tab-lixeira-lnk');
        if (triggerEl) {
            // Remove estados ativos anteriores
            document.querySelectorAll('#cfgTabs .nav-link').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-content .tab-pane').forEach(el => { el.classList.remove('show', 'active'); });
            
            // Ativa o separador correto
            triggerEl.classList.add('active');
            const targetPane = document.getElementById('tab-lixeira');
            if (targetPane) targetPane.classList.add('show', 'active');
        }
    }
});

function prevLogo(input){const f=input.files[0];if(!f)return;const r=new FileReader();r.onload=e=>{document.getElementById('prev_img').src=e.target.result;document.getElementById('prev_wrap').style.display='block';};r.readAsDataURL(f);}
function syncCor(id){document.getElementById(id+'_txt').value=document.getElementById(id).value;document.getElementById('prev_'+id).style.background=document.getElementById(id).value;}
function syncTxt(id){const v=document.getElementById(id+'_txt').value;if(/^#[0-9A-Fa-f]{6}$/.test(v)){document.getElementById(id).value=v;document.getElementById('prev_'+id).style.background=v;}}
function resetTema(){if(!confirm('Restaurar cores padrão?'))return;document.getElementById('cp').value='#1a3c5e';document.getElementById('cs').value='#2c6fad';document.getElementById('ca').value='#f0a500';['cp','cs','ca'].forEach(id=>{document.getElementById(id+'_txt').value=document.getElementById(id).value;document.getElementById('prev_'+id).style.background=document.getElementById(id).value;});}
</script>