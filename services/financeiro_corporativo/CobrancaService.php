<?php
declare(strict_types=1);
require_once __DIR__ . '/FinanceiroCorporativoBaseService.php';

final class CobrancaService extends FinanceiroCorporativoBaseService
{
    public static function criarComConexaoOficial(): self { return new self(conectar()); }

    public function criarRascunho(array $dados, array $itens = []): array
    {
        return $this->executarSeguro('Falha ao criar cobrança SaaS', 'cobrancas', null, function () use ($dados, $itens) {
            $escritorioId = FinanceiroCorporativoValidator::inteiroPositivo($dados['escritorio_id'] ?? null, 'escritorio_id');
            $escritorio = $this->escritorio($escritorioId);
            $assinaturaId = FinanceiroCorporativoValidator::inteiroOpcional($dados['assinatura_id'] ?? null, 'assinatura_id');
            if ($assinaturaId !== null) {
                $a = $this->buscarUm('SELECT id FROM saas_financeiro_assinaturas WHERE id=? AND escritorio_id=?', 'ii', [$assinaturaId, $escritorioId]);
                if (!$a) throw new FinanceiroCorporativoException('Assinatura inválida para o escritório.', 'ASSINATURA_INVALIDA');
            }
            $tipo = FinanceiroCorporativoValidator::enum($dados['tipo'] ?? '', 'tipo', ['mensalidade','anuidade','implantacao','servico','ajuste','negociacao','renovacao']);
            $vencimento = FinanceiroCorporativoValidator::dataObrigatoria($dados['vencimento_em'] ?? '', 'vencimento_em');
            $competencia = FinanceiroCorporativoValidator::textoOpcional($dados['competencia'] ?? null, 7);
            if ($competencia !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia)) throw new FinanceiroCorporativoException('Competência inválida.', 'VALIDACAO_FALHOU');
            $codigo = FinanceiroCorporativoCodigo::gerar('COB');
            $uid = $this->usuarioIdAtual();
            $resultado = FinanceiroCorporativoTransaction::executar($this->conn, function () use ($dados,$itens,$escritorio,$assinaturaId,$tipo,$vencimento,$competencia,$codigo,$uid) {
                $original='0.00';
                foreach ($itens as $item) {
                    $q=(string)($item['quantidade'] ?? '1.0000');
                    $vu=FinanceiroCorporativoValidator::dinheiro($item['valor_unitario'] ?? '0.00','valor_unitario');
                    $vd=FinanceiroCorporativoValidator::dinheiro($item['valor_desconto'] ?? '0.00','valor_desconto');
                    $total=FinanceiroCorporativoValidator::subtrairDinheiro(FinanceiroCorporativoValidator::multiplicarDinheiro($vu,$q),$vd);
                    if (FinanceiroCorporativoValidator::compararDinheiro($total,'0.00')<0) throw new FinanceiroCorporativoException('Item não pode ficar negativo.','VALIDACAO_FALHOU');
                    $original=FinanceiroCorporativoValidator::somarDinheiro($original,$total);
                }
                if ($itens===[]) $original=FinanceiroCorporativoValidator::dinheiro($dados['valor_original'] ?? '0.00','valor_original');
                $desconto=FinanceiroCorporativoValidator::dinheiro($dados['valor_desconto'] ?? '0.00','valor_desconto');
                $juros=FinanceiroCorporativoValidator::dinheiro($dados['valor_juros'] ?? '0.00','valor_juros');
                $multa=FinanceiroCorporativoValidator::dinheiro($dados['valor_multa'] ?? '0.00','valor_multa');
                $total=FinanceiroCorporativoValidator::somarDinheiro(FinanceiroCorporativoValidator::subtrairDinheiro($original,$desconto),$juros,$multa);
                if (FinanceiroCorporativoValidator::compararDinheiro($total,'0.00')<0) throw new FinanceiroCorporativoException('Cobrança não pode ser negativa.','VALIDACAO_FALHOU');
                $obs=FinanceiroCorporativoValidator::textoOpcional($dados['observacoes'] ?? null,65000);
                $stmt=$this->conn->prepare("INSERT INTO saas_financeiro_cobrancas (codigo,assinatura_id,escritorio_id,tenant_id,tipo,competencia,vencimento_em,status,valor_original,valor_desconto,valor_juros,valor_multa,valor_total,valor_pago,saldo_aberto,observacoes,criado_por,atualizado_por) VALUES (?,?,?,?,?,?,?,'rascunho',?,?,?,?,?,'0.00',?,?,?,?)");
                $stmt->bind_param('siissssssssssssii',$codigo,$assinaturaId,$escritorio['id'],$escritorio['tenant_id'],$tipo,$competencia,$vencimento,$original,$desconto,$juros,$multa,$total,$total,$obs,$uid,$uid);
                $stmt->execute(); $id=(int)$this->conn->insert_id; $stmt->close();
                $ordem=0;
                foreach ($itens as $item) {
                    $ordem++;
                    $tipoItem=FinanceiroCorporativoValidator::textoObrigatorio($item['tipo'] ?? 'item','tipo',40);
                    $desc=FinanceiroCorporativoValidator::textoObrigatorio($item['descricao_snapshot'] ?? '', 'descricao_snapshot',255);
                    $cod=FinanceiroCorporativoValidator::textoOpcional($item['codigo_snapshot'] ?? null,80);
                    $assItem=FinanceiroCorporativoValidator::inteiroOpcional($item['assinatura_item_id'] ?? null,'assinatura_item_id');
                    $q=(string)($item['quantidade'] ?? '1.0000');
                    $vu=FinanceiroCorporativoValidator::dinheiro($item['valor_unitario'] ?? '0.00','valor_unitario');
                    $vd=FinanceiroCorporativoValidator::dinheiro($item['valor_desconto'] ?? '0.00','valor_desconto');
                    $vt=FinanceiroCorporativoValidator::subtrairDinheiro(FinanceiroCorporativoValidator::multiplicarDinheiro($vu,$q),$vd);
                    $s=$this->conn->prepare('INSERT INTO saas_financeiro_cobranca_itens (cobranca_id,assinatura_item_id,tipo,codigo_snapshot,descricao_snapshot,quantidade,valor_unitario,valor_desconto,valor_total,ordem) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $s->bind_param('iisssssssi',$id,$assItem,$tipoItem,$cod,$desc,$q,$vu,$vd,$vt,$ordem); $s->execute(); $s->close();
                }
                $this->log->registrarStatus('cobranca',$id,(int)$escritorio['id'],(string)$escritorio['tenant_id'],null,'rascunho','Cobrança criada.');
                return $this->buscarUm('SELECT * FROM saas_financeiro_cobrancas WHERE id=?','i',[$id]) ?? [];
            });
            $this->log->registrarEvento('Criou cobrança SaaS','cobrancas',$codigo,'Cobrança criada em rascunho.');
            return $this->sucesso('Cobrança criada com sucesso.','COBRANCA_CRIADA',$resultado);
        });
    }

    public function emitir(string $codigo): array { return $this->alterarStatus($codigo,['rascunho'],'emitida','Cobrança emitida.','COBRANCA_EMITIDA',true); }
    public function marcarVencida(string $codigo): array { return $this->alterarStatus($codigo,['emitida','aberta','parcial'],'vencida','Cobrança vencida.','COBRANCA_VENCIDA'); }
    public function cancelar(string $codigo,string $motivo): array { return $this->alterarStatus($codigo,['rascunho','emitida','aberta','vencida'],'cancelada',$motivo,'COBRANCA_CANCELADA'); }
    public function estornar(string $codigo,string $motivo): array { return $this->alterarStatus($codigo,['paga','parcial'],'estornada',$motivo,'COBRANCA_ESTORNADA'); }

    private function alterarStatus(string $codigo,array $permitidos,string $novo,string $motivo,string $retorno,bool $emitir=false): array
    {
        return $this->executarSeguro('Falha ao atualizar cobrança SaaS','cobrancas',$codigo,function() use($codigo,$permitidos,$novo,$motivo,$retorno,$emitir){
            $c=$this->entidadePorCodigo('saas_financeiro_cobrancas',$codigo);
            if(!in_array($c['status'],$permitidos,true)) throw new FinanceiroCorporativoException('Status atual não permite esta operação.','STATUS_INVALIDO');
            $uid=$this->usuarioIdAtual(); $now=date('Y-m-d H:i:s');
            $sql='UPDATE saas_financeiro_cobrancas SET status=?, atualizado_por=?'; $types='si'; $p=[$novo,$uid];
            if($emitir){$sql.=', emitida_em=?';$types.='s';$p[]=$now;}
            if($novo==='cancelada'){$sql.=', cancelada_em=?, motivo_cancelamento=?';$types.='ss';$p[]=$now;$p[]=$motivo;}
            $sql.=' WHERE id=?';$types.='i';$p[]=(int)$c['id'];
            $s=$this->conn->prepare($sql);$s->bind_param($types,...$p);$s->execute();$s->close();
            $this->log->registrarStatus('cobranca',(int)$c['id'],(int)$c['escritorio_id'],$c['tenant_id'],$c['status'],$novo,$motivo);
            return $this->sucesso('Cobrança atualizada com sucesso.',$retorno,$this->entidadePorCodigo('saas_financeiro_cobrancas',$codigo));
        });
    }

    public function consultarPorCodigo(string $codigo): array { return $this->executarSeguro('Falha ao consultar cobrança','cobrancas',$codigo,fn()=> $this->sucesso('Cobrança localizada.','COBRANCA_LOCALIZADA',$this->entidadePorCodigo('saas_financeiro_cobrancas',$codigo))); }
    public function listar(array $filtros=[]): array { return $this->executarSeguro('Falha ao listar cobranças','cobrancas',null,function() use($filtros){$sql='SELECT * FROM saas_financeiro_cobrancas WHERE 1=1';$t='';$p=[];foreach(['escritorio_id'=>'i','assinatura_id'=>'i','status'=>'s','tipo'=>'s'] as $k=>$tp){if(isset($filtros[$k])&&$filtros[$k]!==''){$sql.=" AND $k=?";$t.=$tp;$p[]=$filtros[$k];}}$sql.=' ORDER BY vencimento_em DESC,id DESC LIMIT 1000';return $this->sucesso('Cobranças listadas.','COBRANCAS_LISTADAS',['itens'=>$this->listarSql($sql,$t,$p)]);}); }
}
