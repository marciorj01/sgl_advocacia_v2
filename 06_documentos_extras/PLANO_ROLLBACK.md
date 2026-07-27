# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Plano de Rollback

### Gatilhos

- Falha de login generalizada.
- Erro de conexão ou corrupção do banco.
- Vazamento entre tenants.
- Falha crítica no Portal.
- Inconsistência financeira relevante.
- Uploads inacessíveis ou expostos.

### Procedimento

1. Colocar o sistema em manutenção.
2. Registrar horário, versão e motivo.
3. Preservar logs e banco afetado sem sobrescrever.
4. Restaurar pacote de código anterior validado.
5. Restaurar banco do backup imediatamente anterior, em banco separado quando possível.
6. Validar integridade, login MASTER e tenant piloto.
7. Reabrir somente após aceite técnico.

### Requisitos

- Pacote anterior versionado e com hash.
- Dump pré-deploy íntegro.
- Cópia dos uploads correspondente ao dump.
- Registro de mudanças e responsáveis.
- Teste de rollback antes do piloto.
