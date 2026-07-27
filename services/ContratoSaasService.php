<?php
declare(strict_types=1);

require_once __DIR__ . '/EmailService.php';

final class ContratoSaasService
{
    public function __construct(private mysqli $conn) {}

    public function criarContrato(array $dados, int $usuarioId, string $usuarioNome): array
    {
        $representanteNome=trim((string)($dados['representante_nome']??''));
        $representanteEmail=strtolower(trim((string)($dados['representante_email']??'')));
        if($representanteNome==='' || !filter_var($representanteEmail,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Representante ou e-mail inválido.');
        $snapshot=json_encode($dados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $versao='RC2.11.2-'.date('YmdHis');
        $html=$this->renderizarContrato($dados,$versao);
        $texto=trim(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html)));
        $hash=hash('sha256',$html);
        $codigo=$this->uuidV4();
        $expiraEm=date('Y-m-d H:i:s',strtotime('+7 days'));
        $titulo='Contrato de Licenciamento SaaS ROJEX.AI';
        $planoId=max(0,(int)($dados['plano_id']??0));
        $documento=preg_replace('/\D+/','',(string)($dados['representante_documento']??$dados['documento']??''));
        $qualidade=trim((string)($dados['representante_qualidade']??'Representante legal'));
        $this->conn->begin_transaction();
        try {
            $stmt=$this->conn->prepare("INSERT INTO contratos_saas (codigo_publico,status,versao,titulo,conteudo_html,conteudo_texto,hash_sha256,snapshot_contratacao,plano_id,representante_nome,representante_email,representante_documento,representante_qualidade,expira_em,criado_por_usuario_id,criado_por_nome) VALUES (?,'aguardando_aceite',?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar o contrato: ' . $this->conn->error);
            }
            $stmt->bind_param('sssssssisssssis',$codigo,$versao,$titulo,$html,$texto,$hash,$snapshot,$planoId,$representanteNome,$representanteEmail,$documento,$qualidade,$expiraEm,$usuarioId,$usuarioNome);
            if(!$stmt->execute()) throw new RuntimeException($stmt->error ?: 'Falha ao criar contrato.');
            $contratoId=(int)$stmt->insert_id; $stmt->close();
            $token=bin2hex(random_bytes(32)); $tokenHash=hash('sha256',$token); $ip=$_SERVER['REMOTE_ADDR']??null;
            $stmt=$this->conn->prepare("INSERT INTO contratos_saas_tokens (contrato_id,tipo,token_hash,expira_em,solicitado_ip) VALUES (?,'aceite',?,?,?)");
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar o token: ' . $this->conn->error);
            }
            $stmt->bind_param('isss',$contratoId,$tokenHash,$expiraEm,$ip); if(!$stmt->execute()) throw new RuntimeException($stmt->error ?: 'Falha ao criar token.'); $stmt->close();
            $this->conn->commit();
            return ['contrato_id'=>$contratoId,'codigo_publico'=>$codigo,'token'=>$token,'hash_sha256'=>$hash,'versao'=>$versao,'expira_em'=>$expiraEm,'html'=>$html];
        } catch(Throwable $e){$this->conn->rollback();throw $e;}
    }

    public function enfileirarConvite(array $contrato, string $nome, string $email): int
    {
        $mail=EmailService::fromProject($this->conn); $cfg=require __DIR__.'/../config/mail.php';
        $base=rtrim((string)($cfg['base_url']??''),'/');
        $link=$base.'/public/contrato_aceite.php?token='.rawurlencode((string)$contrato['token']);
        $subject='Contrato digital ROJEX.AI — aceite eletrônico';
        $html=$this->renderTemplate('contrato_convite.php',['nome'=>$nome,'link'=>$link,'expira_em'=>$contrato['expira_em']]);
        return $mail->enfileirar('contrato_convite',$email,$nome,$subject,$html,strip_tags($html),'contratos_saas',(string)$contrato['contrato_id']);
    }

    public function buscarPorToken(string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$token)) return null; $hash=hash('sha256',$token);
        $stmt=$this->conn->prepare("SELECT c.*,t.id token_id,t.expira_em token_expira_em,t.utilizado_em,t.revogado_em FROM contratos_saas_tokens t INNER JOIN contratos_saas c ON c.id=t.contrato_id WHERE t.token_hash=? LIMIT 1");
        $stmt->bind_param('s',$hash);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row || $row['utilizado_em'] || $row['revogado_em'] || strtotime((string)$row['token_expira_em'])<time()) return null;
        $ip=$_SERVER['REMOTE_ADDR']??null;$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255);
        $stmt=$this->conn->prepare("UPDATE contratos_saas_tokens SET primeiro_acesso_em=COALESCE(primeiro_acesso_em,NOW()),ultimo_acesso_em=NOW(),tentativas=tentativas+1,ultimo_ip=?,user_agent=? WHERE id=?");
        $stmt->bind_param('ssi',$ip,$ua,$row['token_id']);$stmt->execute();$stmt->close();
        if(empty($row['visualizado_em'])){$id=(int)$row['id'];$this->conn->query("UPDATE contratos_saas SET visualizado_em=NOW(),status='visualizado' WHERE id={$id}");}
        return $row;
    }

    public function aceitarDireto(string $token,array $post): int
    {
        $contrato=$this->buscarPorToken($token); if(!$contrato) throw new RuntimeException('Link inválido, expirado ou já utilizado.');
        foreach(['confirmou_leitura','confirmou_concordancia','autorizou_implantacao','aceitou_privacidade_lgpd'] as $campo) if(empty($post[$campo])) throw new RuntimeException('Todas as confirmações são obrigatórias.');
        $nome=trim((string)($post['representante_nome']??$contrato['representante_nome']));$doc=preg_replace('/\D+/','',(string)($post['representante_documento']??$contrato['representante_documento']));$qualidade=trim((string)($post['representante_qualidade']??$contrato['representante_qualidade']));
        if($nome===''||$doc===''||$qualidade==='') throw new RuntimeException('Complete os dados do representante.');
        $ip=$_SERVER['REMOTE_ADDR']??null;$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255);$agora=date('Y-m-d H:i:s');
        $evidencias=json_encode(['token_hash'=>hash('sha256',$token),'ip'=>$ip,'user_agent'=>$ua,'confirmacoes'=>['leitura'=>1,'concordancia'=>1,'implantacao'=>1,'privacidade_lgpd'=>1]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $cid=(int)$contrato['id'];$tid=(int)$contrato['token_id'];
        $this->conn->begin_transaction();
        try{
            $stmt=$this->conn->prepare("INSERT INTO contratos_saas_aceites (contrato_id,token_id,modalidade,status,confirmou_leitura,confirmou_concordancia,autorizou_implantacao,aceitou_privacidade_lgpd,representante_nome,representante_documento,representante_qualidade,ip,user_agent,versao_contrato,hash_sha256,evidencias_json,aceito_em) VALUES (?,?,'direto','aceito',1,1,1,1,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iisssssssss',$cid,$tid,$nome,$doc,$qualidade,$ip,$ua,$contrato['versao'],$contrato['hash_sha256'],$evidencias,$agora);if(!$stmt->execute())throw new RuntimeException($stmt->error);$aceiteId=(int)$stmt->insert_id;$stmt->close();
            $this->conn->query("UPDATE contratos_saas_tokens SET utilizado_em=NOW() WHERE id={$tid}");$this->conn->query("UPDATE contratos_saas SET status='aceito',aceito_em=NOW() WHERE id={$cid}");
            $this->conn->commit();return $aceiteId;
        }catch(Throwable $e){$this->conn->rollback();throw $e;}
    }

    public function contratoAceito(int $contratoId): bool
    { $stmt=$this->conn->prepare("SELECT 1 FROM contratos_saas c INNER JOIN contratos_saas_aceites a ON a.contrato_id=c.id WHERE c.id=? AND c.status='aceito' AND a.status='aceito' LIMIT 1");$stmt->bind_param('i',$contratoId);$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_row();$stmt->close();return $ok; }

    private function renderizarContrato(array $d,string $versao): string
    {
        $e=fn(mixed $v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
        $valor=number_format((float)($d['valor_contratado']??0),2,',','.');$oficial=number_format((float)($d['valor_oficial']??0),2,',','.');
        return '<article><h1>Contrato de Licenciamento SaaS ROJEX.AI</h1><p><strong>Versão:</strong> '.$e($versao).'</p><h2>Contratante</h2><p>'.$e($d['razao_social']??$d['nome_fantasia']??'').' — Documento: '.$e($d['documento']??'').'</p><h2>Plano e condições</h2><p>Plano: '.$e($d['plano_nome']??'').' | Periodicidade: '.$e($d['periodicidade']??'mensal').'</p><p>Valor oficial: R$ '.$oficial.' | Valor contratado: R$ '.$valor.'</p><p>Benefício comercial: '.$e($d['beneficio_tipo']??'nenhum').' — '.$e($d['beneficio_motivo']??'').'</p><p>Hospedagem: '.$e($d['hospedagem_descricao']??'conforme contratação').' | APIs de IA: '.$e($d['api_ia_descricao']??'conforme consumo/contratação').'</p><p>Trial: '.$e(!empty($d['aplicar_trial'])?((int)($d['trial_dias']??0).' dias'):'não aplicado').'</p><h2>Termos essenciais</h2><p>O serviço é fornecido como software SaaS, sujeito aos limites do plano, política de uso aceitável, disponibilidade técnica, segurança, confidencialidade, proteção de dados e responsabilidades previstas nesta contratação.</p><p>As partes comprometem-se a cumprir a LGPD. O cliente declara possuir base legal para os dados inseridos e a ROJEX.AI adotará medidas técnicas e administrativas adequadas à proteção dos dados tratados.</p><p>O aceite eletrônico confirma leitura, concordância, autorização de implantação, política de privacidade e LGPD.</p><h2>Condições especiais</h2><p>'.$e($d['condicoes_especiais']??'Nenhuma').'</p></article>';
    }
    private function renderTemplate(string $file,array $vars): string {extract($vars,EXTR_SKIP);ob_start();include __DIR__.'/../templates/email/'.$file;return (string)ob_get_clean();}
    private function uuidV4(): string {$d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
}
