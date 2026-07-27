# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Checklist Pós-Implantação

### Primeiras 2 horas

- [ ] HTTPS e redirecionamentos.
- [ ] Login MASTER e logout.
- [ ] Criação do escritório piloto.
- [ ] Login e isolamento do tenant.
- [ ] Logs sem erros fatais.

### Primeiro dia

- [ ] Clientes, advogados, processos e agenda.
- [ ] Financeiro, honorários e recibos.
- [ ] Upload, visualização e download.
- [ ] Portal do Cliente.
- [ ] Dashboard do escritório e MASTER.
- [ ] Exportações PDF/Excel/CSV/ZIP.

### Primeira semana

- [ ] Backup completo e hash.
- [ ] Restauração em ambiente separado.
- [ ] Revisão de erros PHP e logs do servidor.
- [ ] Revisão de permissões de diretórios.
- [ ] Revisão de consumo de disco e banco.
- [ ] Feedback formal do piloto.

### Critérios de aceite

- [ ] Zero incidente crítico de segurança.
- [ ] Zero vazamento multi-tenant.
- [ ] Totais financeiros conciliados.
- [ ] Portal acessível apenas aos clientes autorizados.
- [ ] Backup restaurável comprovado.
- [ ] Responsividade validada em desktop e celular.
