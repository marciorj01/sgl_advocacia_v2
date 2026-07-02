# Fase 3.4.1 — Correção de Visualização/Download de Documentos

## Problema corrigido
Ao clicar em visualizar documento, o arquivo binário era enviado dentro do `index.php`, que já havia carregado HTML. Isso gerava:

- `Cannot modify header information`
- caracteres corrompidos na tela
- PDF/imagens aparecendo como texto estranho

## Solução aplicada
Foi criado um endpoint limpo:

- `documento_arquivo.php`

Agora a tela `modules/documentos.php` apenas lista os arquivos e chama o endpoint para visualizar ou baixar.

## Arquivos alterados

- `modules/documentos.php`
- `documento_arquivo.php`

## Segurança

O endpoint:

- exige login;
- valida o ID;
- verifica se o arquivo pertence à pasta do sistema;
- impede acesso fora da estrutura do projeto;
- registra log de visualização/download;
- envia cabeçalhos corretos para PDF, imagens, Word, Excel e demais formatos permitidos.
