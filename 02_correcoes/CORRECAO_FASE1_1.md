# Correção Fase 1.1

Arquivos ajustados:

- `modules/dashboard.php`
  - Corrigido erro `Unknown column num_processo` no Dashboard.
  - O sistema agora usa `numero_processo`, conforme tabela `processos` do SQL de instalação.

- `auth/login.php`
  - Removida a exibição pública do usuário e senha padrão na tela de login.

## Como aplicar

Substitua os arquivos no projeto local ou substitua a pasta inteira pelo ZIP corrigido.

Não é necessário importar novamente o banco para esta correção.
