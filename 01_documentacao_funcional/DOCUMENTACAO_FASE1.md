# Sistema SGL Advocacia — Documentação Técnica da Fase 1

## Objetivo da Fase 1

Estabilizar a base do sistema antes da criação de novos módulos. Esta etapa corrigiu os pontos estruturais que poderiam impedir o funcionamento do projeto em localhost e futuramente na Hostinger.

## Arquivos alterados/criados

### Arquivos alterados

- `config/database.php`
- `config/conexao.php`
- `config/tema.php`
- `auth/login.php`
- `auth/alterar_senha.php`
- `index.php`
- `modules/configuracoes.php`
- `modules/financeiro.php`
- `assets/js/main.js`

### Arquivos criados

- `config/auth.php`
- `sql/00_instalacao_completa_sgl.sql`
- `sql/01_migracao_fase1.sql`
- `DOCUMENTACAO_FASE1.md`

## Correções realizadas

### 1. Conexão com banco de dados

A configuração principal passa a ser `config/database.php`.

Banco local padrão:

```php
DB_NAME = sistema_sgl
DB_USER = root
DB_PASS = ''
```

As credenciais reais da Hostinger foram removidas do código. Para produção, devem ser configuradas manualmente ou por variáveis de ambiente:

- `SGL_DB_HOST`
- `SGL_DB_USER`
- `SGL_DB_PASS`
- `SGL_DB_NAME`

### 2. Compatibilidade com PDO

O arquivo `config/conexao.php` foi mantido para compatibilidade com códigos futuros ou antigos que usem PDO, mas agora ele reutiliza as mesmas constantes de `database.php`.

### 3. Login corrigido

O login agora usa a tabela oficial `usuarios`, e não mais a tabela `advogados`.

Acesso inicial criado pelo SQL:

```text
Usuário: admin
Senha: admin123
```

Recomendação: alterar a senha imediatamente após o primeiro login.

### 4. Sessão e segurança

Foi criado `config/auth.php`, contendo:

- `iniciarSessaoSegura()`
- `usuarioLogado()`
- `exigirLogin()`
- `gerarTokenCsrf()`
- `validarTokenCsrf()`

O login e a alteração de senha agora usam token CSRF.

### 5. Banco de dados completo

Foi criado o arquivo:

```text
sql/00_instalacao_completa_sgl.sql
```

Ele cria as principais tabelas usadas atualmente pelo sistema:

- `usuarios`
- `advogados`
- `clientes`
- `processos`
- `honorarios`
- `honorarios_parcelas`
- `agenda`
- `contas_pagar`
- `contas_pagar_parcelas`
- `contas_receber`
- `contas_receber_parcelas`
- `configuracoes`
- `logs_sistema`

Também foi criado:

```text
sql/01_migracao_fase1.sql
```

Use este arquivo somente se o banco antigo já existir e você quiser migrar/adaptar a estrutura.

### 6. Logo corrigida

O sistema chamava:

```text
assets/img/logo.jpg
```

Mas o arquivo real existente era:

```text
assets/img/logo_custom.png
```

As referências principais foram atualizadas para `logo_custom.png`.

### 7. JavaScript corrigido

O arquivo estava em:

```text
assets/js/main.js/main.js
```

Mas o sistema chamava:

```text
assets/js/main.js
```

A estrutura foi corrigida.

## Como instalar no XAMPP

1. Copie a pasta do projeto para:

```text
C:\xampp\htdocs\sgl_advocacia
```

2. Abra o XAMPP e inicie:

- Apache
- MySQL

3. Acesse o phpMyAdmin:

```text
http://localhost/phpmyadmin
```

4. Importe o arquivo:

```text
sql/00_instalacao_completa_sgl.sql
```

5. Acesse o sistema:

```text
http://localhost/sgl_advocacia
```

6. Entre com:

```text
Usuário: admin
Senha: admin123
```

7. Altere a senha no menu `Alterar Senha`.

## Observações importantes

- Esta fase não reestruturou o sistema para MVC para evitar quebra desnecessária.
- A arquitetura modular atual foi preservada.
- Ainda existem consultas SQL em alguns módulos que devem ser convertidas gradualmente para prepared statements nas próximas fases.
- A Fase 2 deve revisar módulo por módulo, começando pelo Dashboard.

## Próxima fase recomendada

Fase 2 — Revisão dos módulos existentes, na seguinte ordem:

1. Dashboard
2. Clientes
3. Advogados
4. Processos
5. Honorários
6. Agenda
7. Financeiro
8. Configurações
