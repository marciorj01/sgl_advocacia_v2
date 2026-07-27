# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Plano de Backup

### Escopos

- Banco de dados completo.
- Uploads e documentos.
- Configurações necessárias, sem expor segredos em locais públicos.
- Logs e manifestos de integridade.

### Política inicial

- Backup manual obrigatório antes de cada deploy.
- Backup diário da hospedagem habilitado.
- Cópia externa periódica em local independente da Hostinger.
- Retenção sugerida: 7 diários, 4 semanais e 6 mensais, sujeita à política comercial e LGPD.

### Verificação

- Calcular SHA-256.
- Registrar tamanho, data, escopo e responsável.
- Testar abertura do ZIP.
- Restaurar amostra mensalmente em ambiente separado.
- Nunca considerar backup válido apenas porque o arquivo foi criado.

### Segurança

- Criptografar pacotes com dados pessoais.
- Guardar senha/chave fora do pacote.
- Restringir acesso por função.
- Registrar download e restauração.
