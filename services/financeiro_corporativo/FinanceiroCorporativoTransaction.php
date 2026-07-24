<?php
/**
 * Controle simples de transação, sem transações aninhadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/FinanceiroCorporativoException.php';

final class FinanceiroCorporativoTransaction
{
    public static function executar(mysqli $conn, callable $operacao): mixed
    {
        if ($conn->errno !== 0) {
            throw new FinanceiroCorporativoException('Conexão de dados indisponível.', 'BANCO_INDISPONIVEL');
        }

        $conn->begin_transaction();
        try {
            $resultado = $operacao();
            $conn->commit();
            return $resultado;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
