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
    function rojex_env(array $nomes, ?string $padrao = null): ?string
    {
        foreach ($nomes as $nome) {
            $valor = getenv($nome);

            if ($valor !== false && trim((string)$valor) !== '') {
                return trim((string)$valor);
            }
        }

        return $padrao;
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
if (!defined('ROJEX_APP_ENV')) {
    $ambienteConfigurado = strtolower(
        (string)rojex_env(['ROJEX_APP_ENV', 'SGL_APP_ENV'], 'local')
    );

    $ambientesPermitidos = ['local', 'homologacao', 'producao'];

    if (!in_array($ambienteConfigurado, $ambientesPermitidos, true)) {
        $ambienteConfigurado = 'producao';
    }

    define('ROJEX_APP_ENV', $ambienteConfigurado);
}

if (!defined('ROJEX_APP_DEBUG')) {
    $debugConfigurado = strtolower(
        (string)rojex_env(['ROJEX_APP_DEBUG', 'SGL_APP_DEBUG'], '')
    );

    $debugAtivo = in_array($debugConfigurado, ['1', 'true', 'yes', 'on'], true);

    if ($debugConfigurado === '') {
        $debugAtivo = ROJEX_APP_ENV === 'local';
    }

    define('ROJEX_APP_DEBUG', $debugAtivo);
}

if (!defined('ROJEX_APP_TIMEZONE')) {
    define(
        'ROJEX_APP_TIMEZONE',
        (string)rojex_env(
            ['ROJEX_APP_TIMEZONE', 'SGL_APP_TIMEZONE'],
            'America/Sao_Paulo'
        )
    );
}

date_default_timezone_set(ROJEX_APP_TIMEZONE);

$ambienteLocal = ROJEX_APP_ENV === 'local';

if (!defined('DB_HOST')) {
    define(
        'DB_HOST',
        (string)rojex_env(
            ['ROJEX_DB_HOST', 'SGL_DB_HOST'],
            'localhost'
        )
    );
}

if (!defined('DB_PORT')) {
    $portaBanco = (int)rojex_env(
        ['ROJEX_DB_PORT', 'SGL_DB_PORT'],
        '3306'
    );

    if ($portaBanco < 1 || $portaBanco > 65535) {
        $portaBanco = 3306;
    }

    define('DB_PORT', $portaBanco);
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
            $ambienteLocal ? '' : 'ALTERE_SENHA_HOSTINGER'
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
 * Impede que uma instalação de produção continue usando marcadores de exemplo.
 */
if (ROJEX_APP_ENV === 'producao') {
    $configuracoesPendentes = [
        DB_USER === 'ALTERE_USUARIO_HOSTINGER',
        DB_PASS === 'ALTERE_SENHA_HOSTINGER',
        DB_NAME === 'ALTERE_BANCO_HOSTINGER',
    ];

    if (in_array(true, $configuracoesPendentes, true)) {
        error_log(
            '[ROJEX DATABASE] Configuração de produção incompleta. ' .
            'Defina ROJEX_DB_HOST, ROJEX_DB_PORT, ROJEX_DB_USER, ' .
            'ROJEX_DB_PASS e ROJEX_DB_NAME.'
        );

        http_response_code(500);

        die(
            '<div style="font-family:Arial,sans-serif;padding:30px;color:#842029;' .
            'background:#f8d7da;border:1px solid #f5c2c7;border-radius:8px;' .
            'max-width:720px;margin:40px auto;">' .
            '<h3>Configuração indisponível</h3>' .
            '<p>O ambiente de produção ainda não está configurado corretamente.</p>' .
            '</div>'
        );
    }
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
            DB_PORT
        );

        $conn->set_charset('utf8mb4');

        return $conn;
    } catch (Throwable $e) {
        error_log(
            '[ROJEX DATABASE][' . ROJEX_APP_ENV . '] ' .
            $e->getMessage()
        );

        http_response_code(500);

        $mensagemPublica = '<div style="font-family:Arial,sans-serif;padding:30px;' .
            'color:#842029;background:#f8d7da;border:1px solid #f5c2c7;' .
            'border-radius:8px;max-width:720px;margin:40px auto;">' .
            '<h3>Serviço de dados indisponível</h3>' .
            '<p>Não foi possível concluir a conexão com o banco de dados. ' .
            'Tente novamente em instantes.</p>';

        if (ROJEX_APP_DEBUG) {
            $mensagemPublica .=
                '<p><strong>Ambiente:</strong> ' .
                htmlspecialchars(ROJEX_APP_ENV, ENT_QUOTES, 'UTF-8') .
                '</p>' .
                '<p><strong>Banco:</strong> ' .
                htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8') .
                '</p>' .
                '<p><strong>Detalhe técnico:</strong> ' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
                '</p>';
        }

        die($mensagemPublica . '</div>');
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
