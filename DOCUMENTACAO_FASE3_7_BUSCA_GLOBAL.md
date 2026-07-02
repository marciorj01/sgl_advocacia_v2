# DOCUMENTAÇÃO — FASE 3.7 — BUSCA GLOBAL INTELIGENTE

## Objetivo
Criar uma busca única para localizar informações em todos os principais módulos do SGL Advocacia.

## Arquivos alterados
- `index.php`
- `modules/busca_global.php`

## Funcionalidades incluídas
- Novo menu **Busca Global**.
- Campo de busca rápida no topo do sistema.
- Pesquisa integrada nos módulos:
  - Clientes;
  - Advogados;
  - Processos;
  - Agenda;
  - Honorários;
  - Contas a Receber;
  - Contas a Pagar;
  - Recibos;
  - Documentos;
  - Modelos Jurídicos.

## Exemplos de pesquisa
- Nome de cliente;
- CPF/CNPJ;
- Número de processo;
- OAB;
- Código de recibo;
- Código financeiro;
- Nome de documento;
- Título de modelo jurídico;
- Termos como PIX, Previdenciário, Trabalhista, Contrato etc.

## Observação técnica
O módulo foi construído com verificação dinâmica de tabelas e colunas para evitar erros caso alguma migração ainda não tenha sido importada.

## Próxima fase
FASE 3.8 — Central Inteligente.
