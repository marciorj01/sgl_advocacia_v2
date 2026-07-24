<?php
/**
 * Validações compartilhadas do Financeiro Corporativo MASTER SaaS.
 */

declare(strict_types=1);

require_once __DIR__ . '/FinanceiroCorporativoException.php';

final class FinanceiroCorporativoValidator
{
    public static function textoObrigatorio(mixed $valor, string $campo, int $maximo = 255): string
    {
        $texto = trim((string)$valor);
        if ($texto === '') {
            throw new FinanceiroCorporativoException(
                'Existem dados obrigatórios não preenchidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'Campo obrigatório.']
            );
        }
        if (mb_strlen($texto, 'UTF-8') > $maximo) {
            throw new FinanceiroCorporativoException(
                'Existem dados inválidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'Limite máximo de ' . $maximo . ' caracteres.']
            );
        }
        return $texto;
    }

    public static function textoOpcional(mixed $valor, int $maximo = 500): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string)$valor);
        if ($texto === '') {
            return null;
        }
        if (mb_strlen($texto, 'UTF-8') > $maximo) {
            throw new FinanceiroCorporativoException(
                'Existem dados inválidos.',
                'VALIDACAO_FALHOU',
                ['texto' => 'Limite máximo de ' . $maximo . ' caracteres.']
            );
        }
        return $texto;
    }

    public static function inteiroPositivo(mixed $valor, string $campo): int
    {
        $inteiro = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($inteiro === false) {
            throw new FinanceiroCorporativoException(
                'Existem dados inválidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'Informe um identificador válido.']
            );
        }
        return (int)$inteiro;
    }

    public static function inteiroOpcional(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return self::inteiroPositivo($valor, $campo);
    }

    public static function dataObrigatoria(mixed $valor, string $campo): string
    {
        return self::data($valor, $campo, false) ?? '';
    }

    public static function dataOpcional(mixed $valor, string $campo): ?string
    {
        return self::data($valor, $campo, true);
    }

    private static function data(mixed $valor, string $campo, bool $opcional): ?string
    {
        $texto = trim((string)$valor);
        if ($texto === '' && $opcional) {
            return null;
        }
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        $erros = DateTimeImmutable::getLastErrors();
        $invalida = !$data || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0));
        if ($invalida || $data->format('Y-m-d') !== $texto) {
            throw new FinanceiroCorporativoException(
                'Existem datas inválidas.',
                'VALIDACAO_FALHOU',
                [$campo => 'Use o formato AAAA-MM-DD.']
            );
        }
        return $texto;
    }

    public static function enum(mixed $valor, string $campo, array $permitidos): string
    {
        $texto = strtolower(trim((string)$valor));
        if (!in_array($texto, $permitidos, true)) {
            throw new FinanceiroCorporativoException(
                'Existem dados inválidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'Valor não permitido.']
            );
        }
        return $texto;
    }

    /**
     * Normaliza valor monetário para string decimal com duas casas, sem float.
     */
    public static function dinheiro(mixed $valor, string $campo, bool $permiteZero = true): string
    {
        $texto = trim((string)$valor);
        $texto = str_replace(['R$', ' '], '', $texto);

        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } elseif (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', $texto);
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $texto)) {
            throw new FinanceiroCorporativoException(
                'Existem valores financeiros inválidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'Informe um valor monetário válido.']
            );
        }

        [$inteiro, $decimal] = array_pad(explode('.', $texto, 2), 2, '');
        $normalizado = ltrim($inteiro, '0');
        $normalizado = $normalizado === '' ? '0' : $normalizado;
        $decimal = str_pad($decimal, 2, '0');
        $normalizado .= '.' . substr($decimal, 0, 2);

        if (!$permiteZero && self::compararDinheiro($normalizado, '0.00') <= 0) {
            throw new FinanceiroCorporativoException(
                'Existem valores financeiros inválidos.',
                'VALIDACAO_FALHOU',
                [$campo => 'O valor deve ser maior que zero.']
            );
        }
        return $normalizado;
    }

    public static function compararDinheiro(string $a, string $b): int
    {
        return self::paraCentavos($a) <=> self::paraCentavos($b);
    }

    public static function somarDinheiro(string ...$valores): string
    {
        $centavos = 0;
        foreach ($valores as $valor) {
            $centavos += self::paraCentavos($valor);
        }
        return self::deCentavos($centavos);
    }

    public static function subtrairDinheiro(string $a, string $b): string
    {
        return self::deCentavos(self::paraCentavos($a) - self::paraCentavos($b));
    }

    public static function multiplicarDinheiro(string $valor, string $quantidade): string
    {
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $quantidade)) {
            throw new FinanceiroCorporativoException('Quantidade inválida.', 'VALIDACAO_FALHOU');
        }
        $partes = explode('.', $quantidade, 2);
        $inteiro = (int)$partes[0];
        $fracao = str_pad($partes[1] ?? '', 4, '0');
        $unidades = ($inteiro * 10000) + (int)substr($fracao, 0, 4);
        $resultado = intdiv(self::paraCentavos($valor) * $unidades, 10000);
        return self::deCentavos($resultado);
    }

    private static function paraCentavos(string $valor): int
    {
        [$inteiro, $decimal] = array_pad(explode('.', $valor, 2), 2, '00');
        return ((int)$inteiro * 100) + (int)str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private static function deCentavos(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);
        return $sinal . intdiv($centavos, 100) . '.' . str_pad((string)($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
