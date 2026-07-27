# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Plano do Cliente Piloto — 30 dias

### Dias 1–3 — Implantação assistida

- Criar o escritório pelo fluxo oficial do MASTER.
- Cadastrar administrador e validar troca de senha.
- Configurar identidade, dados cadastrais e permissões.
- Cadastrar um cliente, um advogado e um processo reais controlados.
- Validar agenda, documentos e Portal do Cliente.

**Critério de avanço:** nenhum erro crítico de login, tenant, upload ou permissão.

### Dias 4–7 — Operação jurídica inicial

- Operar clientes, processos, agenda e documentos.
- Validar buscas, filtros, exportações e responsividade.
- Registrar feedback diário em categorias: bloqueador, alto, médio, baixo e sugestão.

**Critério de avanço:** fluxos principais concluídos sem intervenção técnica direta.

### Dias 8–14 — Financeiro e honorários

- Cadastrar honorário real controlado.
- Gerar conta a receber, recebimento e recibo.
- Testar conta a pagar e movimentação bancária.
- Conferir Dashboard do escritório e Dashboard Executivo MASTER.

**Critério de avanço:** totais financeiros conciliados manualmente.

### Dias 15–21 — Portal e estabilidade

- Convidar cliente real autorizado.
- Validar primeiro acesso, permissões, sessão e recuperação de acesso.
- Testar envio e visualização de documentos.
- Monitorar logs, erros PHP e consumo de armazenamento.

**Critério de avanço:** nenhuma quebra de isolamento ou exposição indevida.

### Dias 22–27 — Continuidade e recuperação

- Executar backup completo.
- Verificar hash e download.
- Restaurar em ambiente separado.
- Validar login e amostra de dados restaurados.

**Critério de avanço:** restauração comprovada e documentada.

### Dias 28–30 — Encerramento do piloto

- Consolidar falhas e feedback.
- Corrigir apenas estabilidade, segurança ou compatibilidade.
- Emitir aceite do piloto.
- Definir go/no-go para novos clientes.

### Indicadores do piloto

- Disponibilidade percebida.
- Erros por módulo.
- Tempo para conclusão dos fluxos principais.
- Divergências financeiras.
- Falhas de upload/download.
- Incidentes de permissão ou tenant.
- Chamados por usuário.
- Satisfação do cliente piloto.
