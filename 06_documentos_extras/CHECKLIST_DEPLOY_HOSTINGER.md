# ROJEX.AI — CHAT 63

**RC2.9 — Preparação da Base de Produção e Implantação do Cliente Piloto**

Data da auditoria: 27/07/2026  
Fontes auditadas: `sgl_advocacia_v2-main(18).zip` e `sistema_sgl_novo(22).sql`  
Modo: somente leitura; nenhuma exclusão ou alteração executada.

## Checklist Deploy Hostinger

### Antes do upload

- [ ] Criar pacote sem `.git`, documentação interna, testes e backups locais.
- [ ] Remover uploads de homologação.
- [ ] Não enviar credenciais em arquivos versionados.
- [ ] Confirmar PHP 8.3 e extensões PDO MySQL, mbstring, fileinfo, openssl, zip e intl quando utilizadas.

### Variáveis obrigatórias

- [ ] `ROJEX_ENV=production`.
- [ ] `ROJEX_DB_HOST`.
- [ ] `ROJEX_DB_NAME`.
- [ ] `ROJEX_DB_USER`.
- [ ] `ROJEX_DB_PASS`.
- [ ] `ROJEX_APP_URL=https://dominio-oficial`.
- [ ] Segredos criptográficos exclusivos de produção.

### Web e segurança

- [ ] HTTPS ativo e redirecionamento 301 para HTTPS.
- [ ] Document root apontado para a pasta correta.
- [ ] Bloquear listagem de diretórios.
- [ ] Impedir execução de PHP dentro de uploads.
- [ ] Proteger `config`, `storage`, backups e logs contra acesso web.
- [ ] Cookies `Secure`, `HttpOnly` e `SameSite` validados.

### Permissões

- [ ] Código: leitura pelo servidor, sem escrita indiscriminada.
- [ ] `uploads`: escrita controlada.
- [ ] `storage/backups`, `storage/log_backups`, `storage/encerramentos`: escrita controlada e acesso público bloqueado.
- [ ] Evitar permissões 777.

### Banco

- [ ] Criar banco e usuário exclusivos.
- [ ] Importar SQL Mestre limpo.
- [ ] Verificar routines/DEFINER.
- [ ] Validar charset, collation e timezone.
- [ ] Testar transações e FKs.

### Homologação do deploy

- [ ] Login MASTER.
- [ ] Cadastro de escritório piloto.
- [ ] Login do escritório.
- [ ] Upload e download seguro.
- [ ] Portal do Cliente.
- [ ] PDF, Excel, CSV e ZIP.
- [ ] Backup completo e restauração de teste.
- [ ] Logs sem exposição de senha, token ou segredo.
