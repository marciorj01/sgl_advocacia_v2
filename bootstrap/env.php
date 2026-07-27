<?php
declare(strict_types=1);

/**
 * Carregador mínimo de variáveis de ambiente do ROJEX.AI.
 *
 * Ordem de prioridade:
 * 1. Variáveis já fornecidas pelo servidor/Hostinger.
 * 2. Arquivo .env.local na raiz do projeto, somente para desenvolvimento local.
 */
final class RojexEnv
{
    private static bool $carregado = false;

    public static function carregar(?string $raiz = null): void
    {
        if (self::$carregado) {
            return;
        }
        self::$carregado = true;

        $raiz = $raiz ?: dirname(__DIR__);
        $arquivo = rtrim($raiz, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env.local';
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            return;
        }

        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($linhas)) {
            return;
        }

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#') || !str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = array_map('trim', explode('=', $linha, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $chave)) {
                continue;
            }

            if ((str_starts_with($valor, '"') && str_ends_with($valor, '"'))
                || (str_starts_with($valor, "'") && str_ends_with($valor, "'"))) {
                $valor = substr($valor, 1, -1);
            }

            // Nunca sobrescreve variável definida pelo servidor.
            if (getenv($chave) !== false) {
                continue;
            }

            putenv($chave . '=' . $valor);
            $_ENV[$chave] = $valor;
            $_SERVER[$chave] = $valor;
        }
    }
}

RojexEnv::carregar(dirname(__DIR__));
