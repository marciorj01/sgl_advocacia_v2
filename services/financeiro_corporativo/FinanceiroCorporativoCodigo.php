<?php
/**
 * Gerador de códigos públicos não sequenciais.
 */

declare(strict_types=1);

final class FinanceiroCorporativoCodigo
{
    public static function gerar(string $prefixo): string
    {
        $prefixo = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefixo) ?? 'FIN');
        $prefixo = substr($prefixo, 0, 8);
        return $prefixo . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
    }
}
