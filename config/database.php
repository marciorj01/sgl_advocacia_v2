<?php
/**
 * config/database.php
 * Conexão principal do ROJEX.AI ERP Jurídico Enterprise.
 *
 * Compatibilidade:
 * - XAMPP + MySQL/MariaDB em ambiente local.
 * - Variáveis ROJEX_* como padrão oficial.
 * - Variáveis SGL_* mantidas temporariamente como fallback legado.
 * - Preparado para futura hospedagem na Hostinger.
 */

declare(strict_types=1);

/**
 * Retorna a primeira variável de ambiente válida dentre os nomes informados.
 */
if (!function_exists('rojex_env')) {
    function rojex_env(
        array $nomes,
        ?string $padrao = null,
        bool $removerEspacos = true
    ): ?string
    {
        foreach ($nomes as $nome) {
            $valor = getenv($nome);

            if ($valor !== false && trim((string)$valor) !== '') {
                return $removerEspacos
                    ? trim((string)$valor)
                    : (string)$valor;
            }
        }

        return $padrao;
    }
}

/**
 * Reconhece uma execução local sem confiar no cabeçalho HTTP_HOST.
 *
 * No navegador, somente endereços de loopback são aceitos como locais.
 * No terminal, o Windows é aceito para preservar os comandos do XAMPP.
 */
if (!function_exists('rojex_execucao_local_confiavel')) {
    function rojex_execucao_local_confiavel(): bool
    {
        if (PHP_SAPI === 'cli') {
            return PHP_OS_FAMILY === 'Windows';
        }

        $enderecoServidor = strtolower(
            trim((string)($_SERVER['SERVER_ADDR'] ?? ''))
        );

        return in_array(
            $enderecoServidor,
            ['127.0.0.1', '::1'],
            true
        );
    }
}

/**
 * Identifica o ambiente de execução sem depender de HTTP_HOST.
 *
 * Valores oficiais:
 * - local
 * - homologacao
 * - producao
 */
$ambientesPermitidos = ['local', 'homologacao', 'producao'];

if (!defined('ROJEX_APP_ENV')) {
    $ambienteInformado = rojex_env(
        ['ROJEX_APP_ENV', 'SGL_APP_ENV'],
        null
    );

    $ambienteConfigurado = $ambienteInformado === null
        ? (rojex_execucao_local_confiavel() ? 'local' : 'producao')
        : strtolower($ambienteInformado);

    if (!in_array($ambienteConfigurado, $ambientesPermitidos, true)) {
        error_log(
            '[ROJEX CONFIG] ROJEX_APP_ENV inválida; ' .
            'o modo seguro de produção foi aplicado.'
        );

        $ambienteConfigurado = 'producao';
    }

    define('ROJEX_APP_ENV', $ambienteConfigurado);
}

$ambienteInvalido = !is_string(ROJEX_APP_ENV)
    || !in_array(ROJEX_APP_ENV, $ambientesPermitidos, true);

if ($ambienteInvalido) {
    error_log(
        '[ROJEX CONFIG] A constante ROJEX_APP_ENV contém um valor inválido.'
    );
}

if (!defined('ROJEX_APP_DEBUG')) {
    $debugConfigurado = strtolower(
        (string)rojex_env(['ROJEX_APP_DEBUG', 'SGL_APP_DEBUG'], '')
    );

    $debugSolicitado = $debugConfigurado !== '' && in_array(
        $debugConfigurado,
        ['1', 'true', 'yes', 'on'],
        true
    );

    $debugAtivo = ROJEX_APP_ENV === 'local'
        && ($debugConfigurado === '' || $debugSolicitado);

    if (
        ROJEX_APP_ENV !== 'local'
        && $debugConfigurado !== ''
        && $debugSolicitado
    ) {
        error_log(
            '[ROJEX CONFIG] O modo debug foi ignorado fora do ambiente local.'
        );
    }

    define('ROJEX_APP_DEBUG', $debugAtivo);
}

if (!defined('ROJEX_APP_TIMEZONE')) {
    $timezoneConfigurado = (string)rojex_env(
        ['ROJEX_APP_TIMEZONE', 'SGL_APP_TIMEZONE'],
        'America/Sao_Paulo'
    );

    $timezoneInvalido = !in_array(
        $timezoneConfigurado,
        timezone_identifiers_list(),
        true
    );

    if ($timezoneInvalido) {
        $timezoneConfigurado = 'America/Sao_Paulo';
    }

    define(
        'ROJEX_APP_TIMEZONE',
        $timezoneConfigurado
    );
} else {
    $timezoneInvalido = !in_array(
        (string)ROJEX_APP_TIMEZONE,
        timezone_identifiers_list(),
        true
    );
}

date_default_timezone_set(
    $timezoneInvalido ? 'America/Sao_Paulo' : ROJEX_APP_TIMEZONE
);

$ambienteLocal = ROJEX_APP_ENV === 'local';

if (!defined('DB_HOST')) {
    define(
        'DB_HOST',
        (string)rojex_env(
            ['ROJEX_DB_HOST', 'SGL_DB_HOST'],
            $ambienteLocal ? 'localhost' : 'ALTERE_HOST_HOSTINGER'
        )
    );
}

if (!defined('DB_PORT')) {
    $portaBancoInformada = (string)rojex_env(
        ['ROJEX_DB_PORT', 'SGL_DB_PORT'],
        '3306'
    );

    $portaBancoValida = filter_var(
        $portaBancoInformada,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    ) !== false;

    $portaBanco = $portaBancoValida
        ? (int)$portaBancoInformada
        : 3306;

    if (!$portaBancoValida && $ambienteLocal) {
        $portaBanco = 3306;
    }

    define('DB_PORT', $portaBanco);
} else {
    $portaBancoValida = filter_var(
        (string)DB_PORT,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    ) !== false;
}

if (!defined('DB_USER')) {
    define(
        'DB_USER',
        (string)rojex_env(
            ['ROJEX_DB_USER', 'SGL_DB_USER'],
            $ambienteLocal ? 'root' : 'ALTERE_USUARIO_HOSTINGER'
        )
    );
}

if (!defined('DB_PASS')) {
    define(
        'DB_PASS',
        (string)rojex_env(
            ['ROJEX_DB_PASS', 'SGL_DB_PASS'],
            $ambienteLocal ? '' : 'ALTERE_SENHA_HOSTINGER',
            false
        )
    );
}

if (!defined('DB_NAME')) {
    define(
        'DB_NAME',
        (string)rojex_env(
            ['ROJEX_DB_NAME', 'SGL_DB_NAME'],
            $ambienteLocal ? 'sistema_sgl_novo' : 'ALTERE_BANCO_HOSTINGER'
        )
    );
}

if (!defined('ROJEX_APP_URL')) {
    define(
        'ROJEX_APP_URL',
        rtrim(
            (string)rojex_env(
                ['ROJEX_APP_URL', 'SGL_APP_URL'],
                ''
            ),
            '/'
        )
    );
}

/**
 * Encerra a requisição com uma mensagem segura para navegador, AJAX ou terminal.
 */
if (!function_exists('rojex_responder_indisponibilidade')) {
    function rojex_responder_indisponibilidade(
        string $titulo,
        string $mensagem,
        ?Throwable $erro = null
    ): void {
        http_response_code(500);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $titulo . ': ' . $mensagem . PHP_EOL);
            exit(1);
        }

        $aceitaJson = stripos(
            (string)($_SERVER['HTTP_ACCEPT'] ?? ''),
            'application/json'
        ) !== false;

        $requisicaoAjax = strtolower(
            (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        ) === 'xmlhttprequest';

        if ($aceitaJson || $requisicaoAjax) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            $resposta = [
                'sucesso' => false,
                'mensagem' => $mensagem,
            ];

            if (ROJEX_APP_DEBUG && $erro !== null) {
                $resposta['detalhe'] = $erro->getMessage();
            }

            echo json_encode(
                $resposta,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        $tituloSeguro = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $mensagemSegura = htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:Arial,sans-serif;padding:30px;' .
            'color:#842029;background:#f8d7da;border:1px solid #f5c2c7;' .
            'border-radius:8px;max-width:720px;margin:40px auto;">' .
            '<h3>' . $tituloSeguro . '</h3>' .
            '<p>' . $mensagemSegura . '</p>';

        if (ROJEX_APP_DEBUG && $erro !== null) {
            $html .= '<p><strong>Detalhe técnico:</strong> ' .
                htmlspecialchars($erro->getMessage(), ENT_QUOTES, 'UTF-8') .
                '</p>';
        }

        echo $html . '</div>';
        exit;
    }
}

/**
 * Valida a configuração antes de qualquer tentativa de conexão.
 * Fora do XAMPP local, todos os valores devem ser informados explicitamente.
 */
$errosConfiguracao = [];
$ambienteRemoto = !$ambienteLocal;

if ($ambienteInvalido) {
    $errosConfiguracao[] = 'ROJEX_APP_ENV';
}

if (!is_bool(ROJEX_APP_DEBUG) || ($ambienteRemoto && ROJEX_APP_DEBUG)) {
    $errosConfiguracao[] = 'ROJEX_APP_DEBUG';
}

$hostBancoValido = DB_HOST !== ''
    && strlen(DB_HOST) <= 255
    && preg_match('~[\x00-\x20\x7F/\\\\]~', DB_HOST) !== 1;

$usuarioBancoValido = DB_USER !== ''
    && strlen(DB_USER) <= 128
    && preg_match('/[\x00-\x20\x7F]/', DB_USER) !== 1;

$nomeBancoValido = DB_NAME !== ''
    && strlen(DB_NAME) <= 128
    && preg_match('/[\x00-\x20\x7F]/', DB_NAME) !== 1;

$urlConfigurada = (string)ROJEX_APP_URL;
$partesUrl = $urlConfigurada !== '' ? parse_url($urlConfigurada) : false;
$urlValida = $urlConfigurada !== ''
    && filter_var($urlConfigurada, FILTER_VALIDATE_URL) !== false
    && is_array($partesUrl)
    && !empty($partesUrl['host'])
    && empty($partesUrl['user'])
    && empty($partesUrl['pass'])
    && empty($partesUrl['query'])
    && empty($partesUrl['fragment']);

if ($urlValida && $ambienteRemoto) {
    $urlValida = strtolower((string)($partesUrl['scheme'] ?? '')) === 'https';
}

if (!$hostBancoValido || ($ambienteRemoto && DB_HOST === 'ALTERE_HOST_HOSTINGER')) {
    $errosConfiguracao[] = 'ROJEX_DB_HOST';
}

if (!$portaBancoValida) {
    $errosConfiguracao[] = 'ROJEX_DB_PORT';
}

if (
    !$usuarioBancoValido
    || ($ambienteRemoto && DB_USER === 'ALTERE_USUARIO_HOSTINGER')
) {
    $errosConfiguracao[] = 'ROJEX_DB_USER';
}

if (
    $ambienteRemoto
    && (DB_PASS === '' || DB_PASS === 'ALTERE_SENHA_HOSTINGER')
) {
    $errosConfiguracao[] = 'ROJEX_DB_PASS';
}

if (
    !$nomeBancoValido
    || ($ambienteRemoto && DB_NAME === 'ALTERE_BANCO_HOSTINGER')
) {
    $errosConfiguracao[] = 'ROJEX_DB_NAME';
}

if ($timezoneInvalido) {
    $errosConfiguracao[] = 'ROJEX_APP_TIMEZONE';
}

if (($ambienteRemoto || $urlConfigurada !== '') && !$urlValida) {
    $errosConfiguracao[] = 'ROJEX_APP_URL';
}

if ($errosConfiguracao !== []) {
    error_log(
        '[ROJEX CONFIG] Configuração inválida ou incompleta: ' .
        implode(', ', array_unique($errosConfiguracao)) . '.'
    );

    rojex_responder_indisponibilidade(
        'Configuração indisponível',
        'O ambiente ainda não está configurado corretamente.'
    );
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Abre a conexão oficial do ROJEX.AI.
 */
function conectar(): mysqli
{
    try {
        $conn = mysqli_init();

        if (!$conn) {
            throw new RuntimeException('Não foi possível inicializar o MySQLi.');
        }

        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

        $conn->real_connect(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            (int)DB_PORT
        );

        $conn->set_charset('utf8mb4');

        $timezone = new DateTimeZone(ROJEX_APP_TIMEZONE);
        $offsetBanco = (new DateTimeImmutable('now', $timezone))->format('P');
        $conn->query("SET time_zone = '" . $offsetBanco . "'");

        return $conn;
    } catch (Throwable $e) {
        error_log(
            '[ROJEX DATABASE][' . ROJEX_APP_ENV . '] ' .
            $e->getMessage()
        );

        rojex_responder_indisponibilidade(
            'Serviço de dados indisponível',
            'Não foi possível concluir a conexão com o banco de dados. ' .
            'Tente novamente em instantes.',
            $e
        );
    }
}

/**
 * Retorna a URL-base oficial da aplicação.
 *
 * Em produção, deve usar ROJEX_APP_URL.
 * O fallback dinâmico é mantido somente para compatibilidade local.
 */
function getBaseUrl(): string
{
    if (ROJEX_APP_URL !== '') {
        return ROJEX_APP_URL;
    }

    $https = (
        !empty($_SERVER['HTTPS'])
        && strtolower((string)$_SERVER['HTTPS']) !== 'off'
    ) || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    $scheme = $https ? 'https' : 'http';

    $hostRecebido = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $hostRecebido);

    if ($host === null || $host === '') {
        $host = 'localhost';
    }

    $scriptDir = str_replace(
        '\\',
        '/',
        dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))
    );

    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host .
        ($scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir);
}
