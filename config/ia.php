<?php
/**
 * ROJEX.AI Enterprise — Configuração oficial de Inteligência Artificial.
 *
 * FASE 4.1 — Camada base de integração com IA.
 *
 * Local/XAMPP:
 * - Por padrão, o sistema funciona em MODO RASCUNHO, sem chamar API externa.
 *
 * Produção/Hostinger:
 * - Configure as variáveis de ambiente para ativar a IA oficial.
 *
 * Variáveis oficiais ROJEX.AI:
 * - ROJEX_OPENAI_API_KEY
 * - ROJEX_OPENAI_MODEL
 *
 * Compatibilidade preservada com versões anteriores:
 * - SGL_OPENAI_API_KEY
 * - SGL_OPENAI_MODEL
 *
 * Observação Enterprise:
 * Este arquivo deve ser a camada central de comunicação com IA.
 * Os módulos do sistema devem chamar as funções abaixo, sem acessar API externa diretamente.
 */

if (!defined('ROJEX_IA_PROVIDER')) {
    define('ROJEX_IA_PROVIDER', getenv('ROJEX_IA_PROVIDER') ?: 'openai');
}

if (!defined('SGL_IA_API_KEY')) {
    define('SGL_IA_API_KEY', getenv('ROJEX_OPENAI_API_KEY') ?: (getenv('SGL_OPENAI_API_KEY') ?: ''));
}

if (!defined('SGL_IA_MODEL')) {
    define('SGL_IA_MODEL', getenv('ROJEX_OPENAI_MODEL') ?: (getenv('SGL_OPENAI_MODEL') ?: ''));
}

if (!defined('SGL_IA_ENDPOINT')) {
    define('SGL_IA_ENDPOINT', getenv('ROJEX_OPENAI_ENDPOINT') ?: 'https://api.openai.com/v1/responses');
}

if (!defined('ROJEX_IA_TIMEOUT')) {
    define('ROJEX_IA_TIMEOUT', (int)(getenv('ROJEX_IA_TIMEOUT') ?: 60));
}

if (!defined('ROJEX_IA_TEMPERATURE')) {
    define('ROJEX_IA_TEMPERATURE', (float)(getenv('ROJEX_IA_TEMPERATURE') ?: 0.2));
}

/**
 * Verifica se a IA externa está disponível.
 * Mantém o nome antigo da função para compatibilidade com módulos já existentes.
 */
function sgl_ia_disponivel(): bool
{
    return ROJEX_IA_PROVIDER === 'openai'
        && SGL_IA_API_KEY !== ''
        && SGL_IA_MODEL !== ''
        && function_exists('curl_init');
}

/**
 * Normaliza mensagens de erro da API para exibição segura ao usuário/sistema.
 */
function rojex_ia_mensagem_erro(int $http, string $raw, string $curlErro = ''): string
{
    if ($curlErro !== '') {
        return 'Falha de comunicação com a IA: ' . $curlErro;
    }

    if ($http === 401 || $http === 403) {
        return 'Falha de autenticação na IA. Verifique a chave da API configurada.';
    }

    if ($http === 404) {
        return 'Endpoint ou modelo de IA não encontrado. Verifique a configuração do modelo.';
    }

    if ($http === 429) {
        return 'Limite de uso da IA atingido. Tente novamente mais tarde ou revise o plano da API.';
    }

    if ($http >= 500) {
        return 'Serviço de IA indisponível no momento. Tente novamente mais tarde.';
    }

    $detalhe = trim(mb_substr(strip_tags((string)$raw), 0, 500));
    return 'Falha na API de IA. HTTP ' . $http . ($detalhe !== '' ? '. ' . $detalhe : '');
}

/**
 * Extrai texto da resposta do endpoint Responses API.
 */
function rojex_ia_extrair_texto(array $json): string
{
    if (isset($json['output_text']) && is_string($json['output_text'])) {
        return trim($json['output_text']);
    }

    $texto = '';

    if (isset($json['output']) && is_array($json['output'])) {
        foreach ($json['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                        $texto .= $content['text'] . "\n";
                    }
                }
            }
        }
    }

    return trim($texto);
}

/**
 * Chamada oficial de IA do ROJEX.AI.
 *
 * Mantém o nome sgl_ia_chamar_openai() para compatibilidade com:
 * - IA Jurídica
 * - CIJ
 * - Gerador de Peças
 * - futuras fases da IA
 */
function sgl_ia_chamar_openai(string $promptSistema, string $promptUsuario): array
{
    if (!sgl_ia_disponivel()) {
        return [
            'ok' => false,
            'modo' => 'rascunho',
            'erro' => 'IA externa não configurada. Configure ROJEX_OPENAI_API_KEY e ROJEX_OPENAI_MODEL para ativar respostas automáticas.',
            'texto' => '',
        ];
    }

    $payload = [
        'model' => SGL_IA_MODEL,
        'input' => [
            [
                'role' => 'system',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $promptSistema,
                ]],
            ],
            [
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $promptUsuario,
                ]],
            ],
        ],
        'temperature' => ROJEX_IA_TEMPERATURE,
    ];

    $ch = curl_init(SGL_IA_ENDPOINT);

    if (!$ch) {
        return [
            'ok' => false,
            'modo' => 'api',
            'erro' => 'Não foi possível iniciar a comunicação cURL com a IA.',
            'texto' => '',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SGL_IA_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => ROJEX_IA_TIMEOUT,
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http < 200 || $http >= 300) {
        return [
            'ok' => false,
            'modo' => 'api',
            'erro' => rojex_ia_mensagem_erro($http, (string)$raw, $err),
            'texto' => '',
        ];
    }

    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'modo' => 'api',
            'erro' => 'Resposta da IA recebida, mas o JSON não pôde ser interpretado.',
            'texto' => '',
        ];
    }

    $texto = rojex_ia_extrair_texto($json);

    return [
        'ok' => $texto !== '',
        'modo' => 'api',
        'erro' => $texto === '' ? 'Resposta recebida da IA, mas sem texto interpretável.' : '',
        'texto' => $texto,
    ];
}
