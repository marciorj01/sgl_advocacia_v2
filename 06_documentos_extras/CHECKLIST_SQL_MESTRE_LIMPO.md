# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Checklist SQL Mestre Limpo

### Estrutura

- [x] 75 tabelas identificadas.
- [x] Engine InnoDB em todas as tabelas.
- [x] 71 chaves estrangeiras identificadas.
- [x] Índices presentes.
- [x] AUTO_INCREMENT declarado nas tabelas numéricas aplicáveis.
- [ ] Validar importação em banco vazio sem warnings.
- [ ] Executar `CHECK TABLE` após importação.

### Compatibilidade Hostinger

- [ ] Remover `DEFINER=root@localhost` das procedures.
- [ ] Confirmar permissão para criar routines no plano Hostinger.
- [ ] Caso routines não sejam permitidas, manter scripts administrativos fora do dump de produção.
- [ ] Não incluir `CREATE DATABASE` ou `USE` fixo; selecionar o banco pelo painel/importador.

### Charset e collation

- [x] Charset `utf8mb4` predominante.
- [!] Existem `utf8mb4_unicode_ci` e `utf8mb4_general_ci`.
- [ ] Padronizar novas tabelas e comparações textuais para uma única collation compatível com MariaDB 10.5.
- [ ] Validar FKs e índices de colunas textuais após eventual padronização.

### Dados iniciais

- [ ] Manter somente catálogos globais obrigatórios.
- [ ] Definir usuário MASTER inicial.
- [ ] Exigir troca de senha no primeiro acesso.
- [ ] Remover escritórios, licenças, assinaturas e usuários de homologação.
- [ ] Remover dados jurídicos e financeiros fictícios.
- [ ] Remover logs, sessões, tokens, backups e caminhos locais.
- [ ] Reiniciar AUTO_INCREMENT apenas na cópia limpa, após validação das dependências.

### Timezone

- [x] Dump define `+00:00`.
- [ ] Confirmar estratégia oficial: banco em UTC e aplicação em `America/Sao_Paulo`.
- [ ] Validar conversões em agenda, logs, financeiro e Portal.

### Validação final

- [ ] Importar em banco temporário.
- [ ] Executar login MASTER.
- [ ] Criar primeiro escritório pelo fluxo oficial.
- [ ] Validar isolamento multi-tenant.
- [ ] Gerar backup e restaurar em segundo banco temporário.
- [ ] Comparar contagem de tabelas, FKs e registros essenciais.
