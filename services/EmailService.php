<?php
declare(strict_types=1);

final class EmailService
{
    public function __construct(private mysqli $conn, private array $config) {}

    public static function fromProject(mysqli $conn): self
    {
        $config = require __DIR__ . '/../config/mail.php';
        return new self($conn, is_array($config) ? $config : []);
    }

    public function enfileirar(string $tipo, string $email, string $nome, string $assunto, string $html, string $texto = '', ?string $tabela = null, ?string $referenciaId = null): int
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Destinatário de e-mail inválido.');
        }
        $agora = date('Y-m-d H:i:s');
        $max = max(1, (int)($this->config['max_attempts'] ?? 5));
        $stmt = $this->conn->prepare("INSERT INTO emails_fila (tipo,referencia_tabela,referencia_id,destinatario_email,destinatario_nome,assunto,corpo_html,corpo_texto,status,prioridade,tentativas,max_tentativas,disponivel_em) VALUES (?,?,?,?,?,?,?,?,'pendente',5,0,?,?)");
        if (!$stmt) throw new RuntimeException('Não foi possível preparar a fila de e-mail.');
        $stmt->bind_param('ssssssssis', $tipo, $tabela, $referenciaId, $email, $nome, $assunto, $html, $texto, $max, $agora);
        if (!$stmt->execute()) { $erro=$stmt->error; $stmt->close(); throw new RuntimeException($erro ?: 'Falha ao enfileirar e-mail.'); }
        $id=(int)$stmt->insert_id; $stmt->close();
        return $id;
    }

    public function processarFila(int $limite = 10): array
    {
        $limite=max(1,min(50,$limite)); $ok=0; $falhas=0;
        $res=$this->conn->query("SELECT * FROM emails_fila WHERE status IN ('pendente','falha_temporaria') AND disponivel_em<=NOW() AND tentativas<max_tentativas ORDER BY prioridade ASC,id ASC LIMIT {$limite}");
        while ($res && ($row=$res->fetch_assoc())) {
            try { $this->enviarItem($row); $ok++; } catch (Throwable $e) { $falhas++; error_log('[ROJEX EMAIL] '.$e->getMessage()); }
        }
        return ['enviados'=>$ok,'falhas'=>$falhas];
    }

    private function enviarItem(array $row): void
    {
        $id=(int)$row['id']; $tentativa=(int)$row['tentativas']+1;
        $stmt=$this->conn->prepare("UPDATE emails_fila SET status='processando',processando_em=NOW(),tentativas=? WHERE id=? AND status IN ('pendente','falha_temporaria')");
        $stmt->bind_param('ii',$tentativa,$id); $stmt->execute(); $stmt->close();
        try {
            $messageId=$this->smtpEnviar((string)$row['destinatario_email'],(string)$row['destinatario_nome'],(string)$row['assunto'],(string)$row['corpo_html'],(string)$row['corpo_texto']);
            $stmt=$this->conn->prepare("UPDATE emails_fila SET status='enviado',enviado_em=NOW(),message_id=?,ultimo_erro_codigo=NULL,ultimo_erro_resumo=NULL WHERE id=?");
            $stmt->bind_param('si',$messageId,$id); $stmt->execute(); $stmt->close();
            $this->log($row,$tentativa,'enviado','smtp',$messageId,null,null);
        } catch (Throwable $e) {
            $max=(int)$row['max_tentativas']; $status=$tentativa >= $max ? 'falha_definitiva' : 'falha_temporaria';
            $proxima=date('Y-m-d H:i:s', time()+min(3600,300*$tentativa));
            $resumo=mb_substr($e->getMessage(),0,500,'UTF-8');
            $stmt=$this->conn->prepare("UPDATE emails_fila SET status=?,falhou_em=NOW(),disponivel_em=?,ultimo_erro_codigo='SMTP_ERROR',ultimo_erro_resumo=? WHERE id=?");
            $stmt->bind_param('sssi',$status,$proxima,$resumo,$id); $stmt->execute(); $stmt->close();
            $this->log($row,$tentativa,$status,'smtp',null,'SMTP_ERROR',$resumo);
            throw $e;
        }
    }

    private function smtpEnviar(string $to, string $nome, string $assunto, string $html, string $texto): string
    {
        $host=(string)($this->config['host']??''); $port=(int)($this->config['port']??587);
        $user=(string)($this->config['username']??''); $pass=(string)($this->config['password']??'');
        $from=(string)($this->config['from_address']??''); $fromName=(string)($this->config['from_name']??'ROJEX.AI');
        if ($host==='' || $user==='' || $pass==='' || !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP não configurado. O e-mail permanecerá na fila.');
        $transport=(($this->config['encryption']??'tls')==='ssl'?'ssl://':'').$host;
        $fp=@stream_socket_client($transport.':'.$port,$errno,$errstr,(int)($this->config['timeout']??20));
        if(!$fp) throw new RuntimeException('Falha na conexão SMTP.');
        stream_set_timeout($fp,(int)($this->config['timeout']??20));
        $read=function()use($fp){$r='';while(($l=fgets($fp,515))!==false){$r.=$l;if(isset($l[3])&&$l[3]===' ')break;}return $r;};
        $cmd=function(string $c,array $codes)use($fp,$read){fwrite($fp,$c."\r\n");$r=$read();$code=(int)substr($r,0,3);if(!in_array($code,$codes,true))throw new RuntimeException('Resposta SMTP inválida: '.$code);};
        $read(); $cmd('EHLO rojex.ai',[250]);
        if (($this->config['encryption']??'tls')==='tls') { $cmd('STARTTLS',[220]); if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Falha ao ativar TLS.'); $cmd('EHLO rojex.ai',[250]); }
        $cmd('AUTH LOGIN',[334]); $cmd(base64_encode($user),[334]); $cmd(base64_encode($pass),[235]);
        $cmd('MAIL FROM:<'.$from.'>',[250]); $cmd('RCPT TO:<'.$to.'>',[250,251]); $cmd('DATA',[354]);
        $boundary='=_ROJEX_'.bin2hex(random_bytes(8)); $messageId='<'.bin2hex(random_bytes(12)).'@rojex.ai>';
        $headers=['From: =?UTF-8?B?'.base64_encode($fromName).'?= <'.$from.'>','To: =?UTF-8?B?'.base64_encode($nome).'?= <'.$to.'>','Subject: =?UTF-8?B?'.base64_encode($assunto).'?=','Message-ID: '.$messageId,'MIME-Version: 1.0','Content-Type: multipart/alternative; boundary="'.$boundary.'"'];
        $body=implode("\r\n",$headers)."\r\n\r\n--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".($texto?:strip_tags($html))."\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n--{$boundary}--\r\n.";
        fwrite($fp,preg_replace('/^\./m','..',$body)."\r\n"); $r=$read(); if((int)substr($r,0,3)!==250) throw new RuntimeException('SMTP recusou a mensagem.');
        $cmd('QUIT',[221]); fclose($fp); return $messageId;
    }

    private function log(array $row,int $tentativa,string $status,string $transportador,?string $messageId,?string $codigo,?string $resumo): void
    {
        $ip=$_SERVER['REMOTE_ADDR']??null; $fila=(int)$row['id'];
        $stmt=$this->conn->prepare("INSERT INTO emails_log (email_fila_id,tipo,referencia_tabela,referencia_id,destinatario_email,assunto,status,tentativa,transportador,message_id,erro_codigo,erro_resumo,ip_origem) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('issssssisssss',$fila,$row['tipo'],$row['referencia_tabela'],$row['referencia_id'],$row['destinatario_email'],$row['assunto'],$status,$tentativa,$transportador,$messageId,$codigo,$resumo,$ip); $stmt->execute(); $stmt->close();
    }
}
