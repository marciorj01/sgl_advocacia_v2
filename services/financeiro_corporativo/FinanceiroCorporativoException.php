<?php
/**
 * Exceção segura da camada de serviços do Financeiro Corporativo MASTER SaaS.
 */

declare(strict_types=1);

final class FinanceiroCorporativoException extends RuntimeException
{
    private string $codigoPublico;
    private array $erros;

    public function __construct(
        string $mensagem,
        string $codigoPublico = 'FINANCEIRO_ERRO',
        array $erros = [],
        int $codigoInterno = 0,
        ?Throwable $anterior = null
    ) {
        parent::__construct($mensagem, $codigoInterno, $anterior);
        $this->codigoPublico = $codigoPublico;
        $this->erros = $erros;
    }

    public function getCodigoPublico(): string
    {
        return $this->codigoPublico;
    }

    public function getErros(): array
    {
        return $this->erros;
    }

    public function paraRetorno(): array
    {
        return [
            'sucesso' => false,
            'mensagem' => $this->getMessage(),
            'erros' => $this->erros,
            'codigo' => $this->codigoPublico,
        ];
    }
}
