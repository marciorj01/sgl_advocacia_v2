<?php
declare(strict_types=1);

final class EmailService
{
    public function __construct(private mysqli $conn, private array $config)
    {
    }

    public static function fromProject(mysqli $conn): self
    {
        $config = require __DIR__ . '/../config/mail.php';
        return new self($conn, is_array($config) ? $config : []);
    }

    public function enfileirar(
        string $tipo,
        string $email,
        string $nome,
        string $assunto,
        string $html,
        string $texto = '',
        ?string $tabela = null,
        ?string $referenciaId = null
    ): int {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Destinatário de e-mail inválido.');
        }

        $agora = date('Y-m-d H:i:s');
        $max = max(1, (int) ($this->config['max_attempts'] ?? 5));
        $sql = "INSERT INTO emails_fila
            (tipo, referencia_tabela, referencia_id, destinatario_email, destinatario_nome,
             assunto, corpo_html, corpo_texto, status, prioridade, tentativas,
             max_tentativas, disponivel_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente', 5, 0, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a fila de e-mail: ' . $this->conn->error);
        }

        $stmt->bind_param(
            'ssssssssis',
            $tipo,
            $tabela,
            $referenciaId,
            $email,
            $nome,
            $assunto,
            $html,
            $texto,
            $max,
            $agora
        );

        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException($erro ?: 'Falha ao enfileirar e-mail.');
        }

        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function processarFila(int $limite = 10): array
    {
        $limite = max(1, min(50, $limite));
        $recuperados = $this->recuperarProcessamentosAbandonados();
        $enviados = 0;
        $falhas = 0;
        $ignorados = 0;

        $sql = "SELECT *
                FROM emails_fila
                WHERE status IN ('pendente', 'falha_temporaria')
                  AND disponivel_em <= NOW()
                  AND tentativas < max_tentativas
                ORDER BY prioridade ASC, id ASC
                LIMIT {$limite}";

        $res = $this->conn->query($sql);
        if (!$res) {
            throw new RuntimeException('Falha ao consultar a fila de e-mails: ' . $this->conn->error);
        }

        while ($row = $res->fetch_assoc()) {
            try {
                $resultado = $this->enviarItem($row);
                if ($resultado) {
                    $enviados++;
                } else {
                    $ignorados++;
                }
            } catch (Throwable $e) {
                $falhas++;
                error_log('[ROJEX EMAIL] ' . $this->sanitizarErro($e->getMessage()));
            }
        }
        $res->free();

        return [
            'enviados' => $enviados,
            'falhas' => $falhas,
            'ignorados' => $ignorados,
            'recuperados' => $recuperados,
        ];
    }

    /** Retorna false quando outro processo já reservou o item. */
    private function enviarItem(array $row): bool
    {
        $id = (int) ($row['id'] ?? 0);
        $tentativa = (int) ($row['tentativas'] ?? 0) + 1;

        if ($id <= 0) {
            throw new RuntimeException('Item inválido na fila de e-mails.');
        }

        try {
            if (!$this->reservarItem($id, $tentativa)) {
                return false;
            }

            $messageId = $this->smtpEnviar(
                (string) ($row['destinatario_email'] ?? ''),
                (string) ($row['destinatario_nome'] ?? ''),
                (string) ($row['assunto'] ?? ''),
                (string) ($row['corpo_html'] ?? ''),
                (string) ($row['corpo_texto'] ?? '')
            );

            $stmt = $this->conn->prepare(
                "UPDATE emails_fila
                 SET status='enviado', enviado_em=NOW(), processando_em=NULL,
                     message_id=?, ultimo_erro_codigo=NULL, ultimo_erro_resumo=NULL
                 WHERE id=? AND status='processando'"
            );
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar confirmação de envio: ' . $this->conn->error);
            }
            $stmt->bind_param('si', $messageId, $id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $erro = $stmt->error ?: 'O registro não estava mais em processamento.';
                $stmt->close();
                throw new RuntimeException('Falha ao confirmar envio na fila: ' . $erro);
            }
            $stmt->close();

            $this->registrarLogSeguro($row, $tentativa, 'enviado', 'smtp', $messageId, null, null);
            return true;
        } catch (Throwable $e) {
            $resumo = $this->sanitizarErro($e->getMessage());
            $this->marcarFalha($row, $tentativa, 'SMTP_ERROR', $resumo);
            $this->registrarLogSeguro(
                $row,
                $tentativa,
                $tentativa >= (int) ($row['max_tentativas'] ?? 5)
                    ? 'falha_definitiva'
                    : 'falha_temporaria',
                'smtp',
                null,
                'SMTP_ERROR',
                $resumo
            );
            throw $e;
        }
    }

    private function reservarItem(int $id, int $tentativa): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE emails_fila
             SET status='processando', processando_em=NOW(), tentativas=?
             WHERE id=?
               AND status IN ('pendente', 'falha_temporaria')
               AND disponivel_em <= NOW()
               AND tentativas < max_tentativas"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar reserva da fila: ' . $this->conn->error);
        }

        $stmt->bind_param('ii', $tentativa, $id);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao reservar item da fila: ' . $erro);
        }

        $reservado = $stmt->affected_rows === 1;
        $stmt->close();
        return $reservado;
    }

    private function marcarFalha(array $row, int $tentativa, string $codigo, string $resumo): void
    {
        $id = (int) ($row['id'] ?? 0);
        $max = max(1, (int) ($row['max_tentativas'] ?? ($this->config['max_attempts'] ?? 5)));
        $status = $tentativa >= $max ? 'falha_definitiva' : 'falha_temporaria';
        $proxima = date('Y-m-d H:i:s', time() + min(3600, 300 * max(1, $tentativa)));
        $resumo = mb_substr($resumo, 0, 500, 'UTF-8');

        $stmt = $this->conn->prepare(
            "UPDATE emails_fila
             SET status=?, falhou_em=NOW(), processando_em=NULL,
                 disponivel_em=?, ultimo_erro_codigo=?, ultimo_erro_resumo=?
             WHERE id=?"
        );
        if (!$stmt) {
            error_log('[ROJEX EMAIL] Não foi possível preparar atualização de falha: ' . $this->conn->error);
            return;
        }

        $stmt->bind_param('ssssi', $status, $proxima, $codigo, $resumo, $id);
        if (!$stmt->execute()) {
            error_log('[ROJEX EMAIL] Não foi possível atualizar a falha da fila: ' . $stmt->error);
        }
        $stmt->close();
    }

    private function recuperarProcessamentosAbandonados(): int
    {
        $minutos = max(5, (int) ($this->config['processing_timeout_minutes'] ?? 15));
        $sql = "UPDATE emails_fila
                SET status = CASE
                        WHEN tentativas >= max_tentativas THEN 'falha_definitiva'
                        ELSE 'falha_temporaria'
                    END,
                    falhou_em = NOW(),
                    processando_em = NULL,
                    disponivel_em = CASE
                        WHEN tentativas >= max_tentativas THEN disponivel_em
                        ELSE NOW()
                    END,
                    ultimo_erro_codigo = 'PROCESSING_TIMEOUT',
                    ultimo_erro_resumo = 'Processamento anterior foi interrompido e recuperado automaticamente.'
                WHERE status='processando'
                  AND processando_em IS NOT NULL
                  AND processando_em < DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE)";

        if (!$this->conn->query($sql)) {
            error_log('[ROJEX EMAIL] Falha ao recuperar processamentos abandonados: ' . $this->conn->error);
            return 0;
        }

        return max(0, $this->conn->affected_rows);
    }

    private function smtpEnviar(string $to, string $nome, string $assunto, string $html, string $texto): string
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 587);
        $user = trim((string) ($this->config['username'] ?? ''));
        $pass = (string) ($this->config['password'] ?? '');
        $from = trim((string) ($this->config['from_address'] ?? ''));
        $fromName = trim((string) ($this->config['from_name'] ?? 'ROJEX.AI'));
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $timeout = max(5, (int) ($this->config['timeout'] ?? 30));
        $ehlo = preg_replace('/[^a-z0-9.-]/i', '', (string) ($this->config['ehlo_domain'] ?? 'rojex.ai')) ?: 'rojex.ai';

        if ($host === '' || $user === '' || $pass === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP não configurado corretamente.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Destinatário SMTP inválido.');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('Criptografia SMTP inválida.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $endpoint = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($fp)) {
            throw new RuntimeException("Conexão SMTP falhou ({$errno}): " . $this->sanitizarErro($errstr));
        }

        stream_set_timeout($fp, $timeout);

        try {
            $banner = $this->smtpLerResposta($fp);
            $this->smtpExigir($banner, [220], 'banner');

            $this->smtpComando($fp, 'EHLO ' . $ehlo, [250], 'EHLO');

            if ($encryption === 'tls') {
                $this->smtpComando($fp, 'STARTTLS', [220], 'STARTTLS');
                $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
                    ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    : STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (@stream_socket_enable_crypto($fp, true, $cryptoMethod) !== true) {
                    throw new RuntimeException('STARTTLS aceito, mas a negociação criptográfica falhou.');
                }
                $this->smtpComando($fp, 'EHLO ' . $ehlo, [250], 'EHLO após STARTTLS');
            }

            $this->smtpComando($fp, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            $this->smtpComando($fp, base64_encode($user), [334], 'usuário SMTP', true);
            $this->smtpComando($fp, base64_encode($pass), [235], 'senha SMTP', true);
            $this->smtpComando($fp, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM');
            $this->smtpComando($fp, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $this->smtpComando($fp, 'DATA', [354], 'DATA');

            $boundary = '=_ROJEX_' . bin2hex(random_bytes(12));
            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $ehlo . '>';
            $nomeCabecalho = $nome !== '' ? $nome : $to;
            $textoFinal = $texto !== '' ? $texto : html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->codificarCabecalho($fromName) . ' <' . $from . '>',
                'To: ' . $this->codificarCabecalho($nomeCabecalho) . ' <' . $to . '>',
                'Subject: ' . $this->codificarCabecalho($assunto),
                'Message-ID: ' . $messageId,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $message = implode("\r\n", $headers)
                . "\r\n\r\n--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($textoFinal), 76, "\r\n")
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html), 76, "\r\n")
                . "--{$boundary}--\r\n";

            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            if (fwrite($fp, $message . "\r\n.\r\n") === false) {
                throw new RuntimeException('Falha ao transmitir o conteúdo da mensagem ao SMTP.');
            }

            $respostaData = $this->smtpLerResposta($fp);
            $this->smtpExigir($respostaData, [250], 'aceite da mensagem');
            $this->smtpComando($fp, 'QUIT', [221], 'QUIT');

            return $messageId;
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /** @param resource $fp */
    private function smtpComando($fp, string $comando, array $codigos, string $etapa, bool $sensivel = false): string
    {
        if (fwrite($fp, $comando . "\r\n") === false) {
            throw new RuntimeException('Falha ao escrever no SMTP durante ' . $etapa . '.');
        }

        $resposta = $this->smtpLerResposta($fp);
        $this->smtpExigir($resposta, $codigos, $etapa, $sensivel);
        return $resposta;
    }

    /** @param resource $fp */
    private function smtpLerResposta($fp): string
    {
        $resposta = '';
        while (($linha = fgets($fp, 1024)) !== false) {
            $resposta .= $linha;
            if (strlen($linha) >= 4 && $linha[3] === ' ') {
                break;
            }
        }

        $meta = stream_get_meta_data($fp);
        if (($meta['timed_out'] ?? false) === true) {
            throw new RuntimeException('Tempo limite excedido aguardando resposta SMTP.');
        }
        if ($resposta === '') {
            throw new RuntimeException('Servidor SMTP encerrou a conexão sem resposta.');
        }

        return trim($resposta);
    }

    private function smtpExigir(string $resposta, array $codigos, string $etapa, bool $sensivel = false): void
    {
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $codigos, true)) {
            $detalhe = $sensivel ? '[resposta protegida]' : $this->sanitizarErro($resposta);
            throw new RuntimeException("SMTP {$etapa} falhou [{$codigo}]: {$detalhe}");
        }
    }

    private function codificarCabecalho(string $valor): string
    {
        $valor = str_replace(["\r", "\n"], '', $valor);
        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }

    private function sanitizarErro(string $mensagem): string
    {
        $segredos = [
            (string) ($this->config['password'] ?? ''),
            (string) ($this->config['username'] ?? ''),
            base64_encode((string) ($this->config['password'] ?? '')),
            base64_encode((string) ($this->config['username'] ?? '')),
        ];

        foreach ($segredos as $segredo) {
            if ($segredo !== '') {
                $mensagem = str_replace($segredo, '[PROTEGIDO]', $mensagem);
            }
        }

        $mensagem = preg_replace('/[\r\n\t]+/', ' | ', $mensagem) ?? $mensagem;
        return mb_substr(trim($mensagem), 0, 500, 'UTF-8');
    }

    private function registrarLogSeguro(
        array $row,
        int $tentativa,
        string $status,
        string $transportador,
        ?string $messageId,
        ?string $codigo,
        ?string $resumo
    ): void {
        try {
            $this->log($row, $tentativa, $status, $transportador, $messageId, $codigo, $resumo);
        } catch (Throwable $e) {
            error_log('[ROJEX EMAIL LOG] ' . $this->sanitizarErro($e->getMessage()));
        }
    }

    private function log(
        array $row,
        int $tentativa,
        string $status,
        string $transportador,
        ?string $messageId,
        ?string $codigo,
        ?string $resumo
    ): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $fila = (int) ($row['id'] ?? 0);
        $tipo = (string) ($row['tipo'] ?? '');
        $referenciaTabela = $row['referencia_tabela'] ?? null;
        $referenciaId = $row['referencia_id'] ?? null;
        $destinatario = (string) ($row['destinatario_email'] ?? '');
        $assunto = (string) ($row['assunto'] ?? '');

        $stmt = $this->conn->prepare(
            "INSERT INTO emails_log
             (email_fila_id, tipo, referencia_tabela, referencia_id, destinatario_email,
              assunto, status, tentativa, transportador, message_id, erro_codigo,
              erro_resumo, ip_origem)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar emails_log: ' . $this->conn->error);
        }

        $stmt->bind_param(
            'issssssisssss',
            $fila,
            $tipo,
            $referenciaTabela,
            $referenciaId,
            $destinatario,
            $assunto,
            $status,
            $tentativa,
            $transportador,
            $messageId,
            $codigo,
            $resumo,
            $ip
        );

        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao gravar emails_log: ' . $erro);
        }
        $stmt->close();
    }
}