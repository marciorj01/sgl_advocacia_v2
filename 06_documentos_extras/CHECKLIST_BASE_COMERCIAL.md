# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Checklist da Base Comercial

### Escritórios e licenças

- [x] Escritório de homologação identificado.
- [x] Tenant de homologação identificado.
- [ ] Autorizar remoção do Escritório C na cópia de produção.
- [ ] Revisar assinatura e licença vinculadas.
- [ ] Preservar catálogos globais de planos e módulos.

### Pessoas e operação jurídica

- [x] Usuários de teste identificados.
- [x] Cliente fictício identificado.
- [x] Nenhum advogado inserido na tabela `advogados`; existe usuário com perfil Advogado.
- [x] Nenhum processo inserido.
- [x] Nenhum compromisso de agenda inserido.
- [x] Nenhum honorário inserido.
- [ ] Confirmar remoção das preferências dos usuários de teste.

### Financeiro operacional

- [x] Conta a pagar fictícia identificada.
- [x] Parcela de conta a pagar identificada.
- [x] Conta a receber fictícia identificada.
- [x] Recibo de homologação identificado.
- [x] Conta bancária/caixa de homologação identificada.
- [ ] Autorizar limpeza encadeada do financeiro do tenant 673.

### Portal do Cliente

- [x] Conta de portal identificada.
- [x] Permissão de portal identificada.
- [x] Sessão de portal identificada.
- [x] Token de convite identificado.
- [x] Tentativa de login identificada.
- [ ] Remover todos os tokens e sessões na cópia de produção.

### Auditoria, backup e arquivos

- [x] Logs de homologação identificados.
- [x] Backups e hashes locais identificados.
- [x] Tabelas de backup técnico identificadas.
- [x] Uploads físicos de homologação identificados.
- [x] Autorização de encerramento local identificada.
- [ ] Definir quais tabelas de backup técnico serão mantidas apenas como estrutura ou excluídas do SQL Mestre.
- [ ] Gerar inventário final de arquivos antes da limpeza física.
