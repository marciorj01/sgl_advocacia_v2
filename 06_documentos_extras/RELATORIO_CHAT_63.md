# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## 1. Resultado executivo

A base ainda **não está pronta para importação em produção**. A estrutura está madura, porém o dump contém dados de homologação, tabelas de backup técnico, logs, sessões e tokens do Portal, registros financeiros fictícios, caminhos locais do XAMPP e duas procedures com `DEFINER=root@localhost`.

### Inventário estrutural

- 75 tabelas InnoDB.
- 71 chaves estrangeiras.
- Aproximadamente 311 índices declarados.
- Charset predominante `utf8mb4`.
- Collations misturadas entre `utf8mb4_unicode_ci` e `utf8mb4_general_ci`.
- Timezone do dump: `+00:00`.
- 2 procedures armazenadas.
- Nenhuma view, trigger ou function identificada.
- 2 ocorrências de `DEFINER=root@localhost`.

## 2. Dados de homologação confirmados

- Escritório `Homologação C`, ID 673, tenant `ESCRIT-ORIO-HOMOLOGAC-AO-C-74FCB98F`.
- Usuário `admin.homologacao.c` e usuário `advogadoc`.
- Cliente `Cliente Portal C`, marcado como excluído.
- Conta a pagar fictícia “agua”, R$ 100,00.
- Conta a receber fictícia “teste”, R$ 10,00.
- Recibo fictício `REC-2026-0001`, R$ 10,00.
- Conta, sessão, token e tentativa de login do Portal do Cliente.
- Logs de homologação e tabelas de backup de logs.
- Três registros de backup com caminhos absolutos do XAMPP.
- Configuração `ambiente_sistema=desenvolvimento`.
- Tabelas auxiliares de backup: `bancos_caixa_backup_fase441`, `bancos_caixa_backup_fix10`, `ia_consultas_backup_sprint463`, `logs_sistema_backup_sprint464` e tabelas equivalentes de movimentações.

## 3. Arquivos físicos encontrados no repositório

- 14 arquivos em `uploads`, incluindo PDFs, imagens, JPEG e TXT de homologação.
- 7 arquivos em `storage`, incluindo autorização de encerramento do Escritório C.
- Arquivos `.htaccess` de proteção presentes em uploads e storage.

## 4. Classificação para produção

### Manter

Estrutura das tabelas, índices, chaves estrangeiras, catálogo de planos, módulos, relacionamentos plano–módulo, categorias e centros de custo corporativos, configurações padrão não sensíveis e histórico de migrações validado.

### Limpar após autorização

Escritório C e suas dependências, usuários de homologação, cliente fictício, financeiro fictício, recibo, dados do Portal, logs, backups, sessões, tokens, tentativas de login, arquivos de upload e autorização de encerramento.

### Revisar antes de decidir

Usuário MASTER inicial, tabela `usuarios_sistema`, tabelas de backup técnico, registros de manutenção, histórico de preços, assinatura e licença SaaS de homologação.

## 5. Bloqueadores de deploy

1. Remover ou recriar as duas procedures sem `DEFINER=root@localhost`.
2. Definir política única de collation.
3. Gerar dump limpo sem dados de homologação.
4. Remover caminhos absolutos `C:\xampp\...` dos registros de backup.
5. Alterar ambiente para produção e configurar variáveis Hostinger.
6. Triar e limpar uploads/storage de homologação.
7. Validar credenciais MASTER iniciais e troca obrigatória de senha.
8. Importar em banco vazio de teste antes da produção.

## 6. Impacto arquitetural

Nenhum arquivo foi alterado. A Baseline RC1 permanece preservada. A futura limpeza deverá operar apenas sobre dados e artefatos de homologação, mantendo esquema, relações, autenticação e regras de negócio.

## 7. Próxima decisão necessária

Autorizar a geração de uma **cópia limpa do SQL**, preservando o arquivo original. A limpeza deverá ser feita em arquivo separado, nunca diretamente no banco de homologação.
