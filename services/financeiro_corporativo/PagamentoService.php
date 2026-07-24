<?php
declare(strict_types=1);
require_once __DIR__ . '/FinanceiroCorporativoBaseService.php';

final class PagamentoService extends FinanceiroCorporativoBaseService
{
    public static function criarComConexaoOficial(): self { return new self(conectar()); }

    public function registrar(array $dados): array
    {
        return $this->executarSeguro('Falha ao registrar pagamento SaaS','pagamentos',null,function() use($dados){
            $eid=FinanceiroCorporativoValidator::inteiroPositivo($dados['escritorio_id']??null,'escritorio_id'); $e=$this->escritorio($eid);
            $forma=FinanceiroCorporativoValidator::enum($dados['forma_pagamento']??'','forma_pagamento',['pix','boleto','cartao','transferencia','dinheiro','credito','outro']);
            $bruto=FinanceiroCorporativoValidator::dinheiro($dados['valor_bruto']??'0','valor_bruto',false);
            $taxa=FinanceiroCorporativoValidator::dinheiro($dados['valor_taxa']??'0','valor_taxa');
            if(FinanceiroCorporativoValidator::compararDinheiro($taxa,$bruto)>0) throw new FinanceiroCorporativoException('Taxa superior ao valor bruto.','VALIDACAO_FALHOU');
            $liquido=FinanceiroCorporativoValidator::subtrairDinheiro($bruto,$taxa);
            $codigo=FinanceiroCorporativoCodigo::gerar('PAG'); $uid=$this->usuarioIdAtual();
            $gateway=FinanceiroCorporativoValidator::textoOpcional($dados['gateway']??null,60); $gref=FinanceiroCorporativoValidator::textoOpcional($dados['gateway_referencia']??null,150); $ext=FinanceiroCorporativoValidator::textoOpcional($dados['referencia_externa']??null,150); $obs=FinanceiroCorporativoValidator::textoOpcional($dados['observacoes']??null,65000);
            if($gref!==null && $this->buscarUm('SELECT id FROM saas_financeiro_pagamentos WHERE gateway_referencia=? LIMIT 1','s',[$gref])) throw new FinanceiroCorporativoException('Referência de gateway já registrada.','PAGAMENTO_DUPLICADO');
            $s=$this->conn->prepare("INSERT INTO saas_financeiro_pagamentos (codigo,escritorio_id,tenant_id,forma_pagamento,status,valor_bruto,valor_taxa,valor_liquido,valor_alocado,valor_disponivel,gateway,gateway_referencia,referencia_externa,observacoes,criado_por) VALUES (?,?,?,?,'pendente',?,?,?,'0.00',?,?,?,?,?,?,?)");
            $s->bind_param('sisssssssssssi',$codigo,$e['id'],$e['tenant_id'],$forma,$bruto,$taxa,$liquido,$liquido,$gateway,$gref,$ext,$obs,$uid);$s->execute();$id=(int)$this->conn->insert_id;$s->close();
            $this->log->registrarStatus('pagamento',$id,(int)$e['id'],$e['tenant_id'],null,'pendente','Pagamento registrado.');
            return $this->sucesso('Pagamento registrado.','PAGAMENTO_REGISTRADO',$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo));
        });
    }

    public function confirmar(string $codigo, ?string $recebidoEm=null): array { return $this->status($codigo,['pendente'],'confirmado','PAGAMENTO_CONFIRMADO',$recebidoEm??date('Y-m-d H:i:s')); }
    public function compensar(string $codigo, ?string $compensadoEm=null): array { return $this->status($codigo,['confirmado'],'compensado','PAGAMENTO_COMPENSADO',$compensadoEm??date('Y-m-d H:i:s'),true); }

    private function status(string $codigo,array $permitidos,string $novo,string $retorno,string $data,bool $compensado=false): array
    {
        return $this->executarSeguro('Falha ao atualizar pagamento','pagamentos',$codigo,function() use($codigo,$permitidos,$novo,$retorno,$data,$compensado){$p=$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo);if(!in_array($p['status'],$permitidos,true))throw new FinanceiroCorporativoException('Status inválido.','STATUS_INVALIDO');$uid=$this->usuarioIdAtual();$campo=$compensado?'compensado_em':'recebido_em';$s=$this->conn->prepare("UPDATE saas_financeiro_pagamentos SET status=?, $campo=?, confirmado_por=? WHERE id=?");$s->bind_param('ssii',$novo,$data,$uid,$p['id']);$s->execute();$s->close();$this->log->registrarStatus('pagamento',(int)$p['id'],(int)$p['escritorio_id'],$p['tenant_id'],$p['status'],$novo,'Atualização de pagamento.');return $this->sucesso('Pagamento atualizado.',$retorno,$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo));});
    }

    public function alocar(string $pagamentoCodigo,array $alocacoes): array
    {
        return $this->executarSeguro('Falha ao alocar pagamento','pagamentos',$pagamentoCodigo,function() use($pagamentoCodigo,$alocacoes){
            $p=$this->entidadePorCodigo('saas_financeiro_pagamentos',$pagamentoCodigo); if(!in_array($p['status'],['confirmado','compensado'],true)) throw new FinanceiroCorporativoException('Pagamento ainda não confirmado.','STATUS_INVALIDO');
            if($alocacoes===[]) throw new FinanceiroCorporativoException('Informe ao menos uma alocação.','VALIDACAO_FALHOU');
            $uid=$this->usuarioIdAtual();
            $result=FinanceiroCorporativoTransaction::executar($this->conn,function() use($p,$alocacoes,$uid){$disponivel=(string)$p['valor_disponivel'];$total='0.00';foreach($alocacoes as $a){$cid=FinanceiroCorporativoValidator::inteiroPositivo($a['cobranca_id']??null,'cobranca_id');$valor=FinanceiroCorporativoValidator::dinheiro($a['valor']??'0','valor',false);$c=$this->buscarUm('SELECT * FROM saas_financeiro_cobrancas WHERE id=? FOR UPDATE','i',[$cid]);if(!$c|| (int)$c['escritorio_id']!==(int)$p['escritorio_id'])throw new FinanceiroCorporativoException('Cobrança inválida.','COBRANCA_INVALIDA');if(FinanceiroCorporativoValidator::compararDinheiro($valor,$c['saldo_aberto'])>0)throw new FinanceiroCorporativoException('Alocação superior ao saldo da cobrança.','SALDO_INSUFICIENTE');$total=FinanceiroCorporativoValidator::somarDinheiro($total,$valor);if(FinanceiroCorporativoValidator::compararDinheiro($total,$disponivel)>0)throw new FinanceiroCorporativoException('Alocação superior ao saldo disponível.','SALDO_INSUFICIENTE');$s=$this->conn->prepare("INSERT INTO saas_financeiro_pagamento_alocacoes (pagamento_id,cobranca_id,valor_alocado,status,criado_por) VALUES (?,?,?,'ativa',?)");$s->bind_param('iisi',$p['id'],$cid,$valor,$uid);$s->execute();$s->close();$vp=FinanceiroCorporativoValidator::somarDinheiro($c['valor_pago'],$valor);$sa=FinanceiroCorporativoValidator::subtrairDinheiro($c['saldo_aberto'],$valor);$st=FinanceiroCorporativoValidator::compararDinheiro($sa,'0.00')===0?'paga':'parcial';$paga=$st==='paga'?date('Y-m-d H:i:s'):null;$u=$this->conn->prepare('UPDATE saas_financeiro_cobrancas SET valor_pago=?,saldo_aberto=?,status=?,paga_em=?,atualizado_por=? WHERE id=?');$u->bind_param('ssssii',$vp,$sa,$st,$paga,$uid,$cid);$u->execute();$u->close();$this->log->registrarStatus('cobranca',$cid,(int)$c['escritorio_id'],$c['tenant_id'],$c['status'],$st,'Pagamento alocado.');}
            $novoAlocado=FinanceiroCorporativoValidator::somarDinheiro($p['valor_alocado'],$total);$novoDisponivel=FinanceiroCorporativoValidator::subtrairDinheiro($p['valor_disponivel'],$total);$u=$this->conn->prepare('UPDATE saas_financeiro_pagamentos SET valor_alocado=?,valor_disponivel=? WHERE id=?');$u->bind_param('ssi',$novoAlocado,$novoDisponivel,$p['id']);$u->execute();$u->close();return ['valor_alocado'=>$total,'valor_disponivel'=>$novoDisponivel];});
            return $this->sucesso('Pagamento alocado com sucesso.','PAGAMENTO_ALOCADO',$result);
        });
    }

    public function estornar(string $codigo,string $valor,string $motivo): array
    {
        return $this->executarSeguro('Falha ao estornar pagamento','pagamentos',$codigo,function() use($codigo,$valor,$motivo){$p=$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo);$v=FinanceiroCorporativoValidator::dinheiro($valor,'valor',false);if(FinanceiroCorporativoValidator::compararDinheiro($v,$p['valor_liquido'])>0)throw new FinanceiroCorporativoException('Estorno superior ao pagamento.','VALIDACAO_FALHOU');$s=$this->conn->prepare("UPDATE saas_financeiro_pagamentos SET status='estornado',estornado_em=NOW(),motivo_estorno=? WHERE id=?");$s->bind_param('si',$motivo,$p['id']);$s->execute();$s->close();$this->log->registrarStatus('pagamento',(int)$p['id'],(int)$p['escritorio_id'],$p['tenant_id'],$p['status'],'estornado',$motivo,['valor'=>$v]);return $this->sucesso('Pagamento estornado.','PAGAMENTO_ESTORNADO',$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo));});
    }

    public function saldoNaoAlocado(string $codigo): array { return $this->executarSeguro('Falha ao consultar saldo','pagamentos',$codigo,function() use($codigo){$p=$this->entidadePorCodigo('saas_financeiro_pagamentos',$codigo);return $this->sucesso('Saldo consultado.','SALDO_PAGAMENTO',['codigo'=>$codigo,'valor_disponivel'=>$p['valor_disponivel']]);}); }
}
