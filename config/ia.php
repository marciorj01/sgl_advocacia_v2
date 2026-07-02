<?php
/**
 * Configuração opcional de IA do SGL Advocacia.
 *
 * Local/XAMPP: por padrão o módulo funciona em MODO RASCUNHO, sem chamar API externa.
 * Produção: configure variáveis de ambiente na Hostinger, se desejar usar OpenAI API.
 *
 * Variáveis recomendadas:
 * - SGL_OPENAI_API_KEY
 * - SGL_OPENAI_MODEL
 */

if (!defined('SGL_IA_API_KEY')) {
    define('SGL_IA_API_KEY', getenv('SGL_OPENAI_API_KEY') ?: '');
}
if (!defined('SGL_IA_MODEL')) {
    define('SGL_IA_MODEL', getenv('SGL_OPENAI_MODEL') ?: '');
}
if (!defined('SGL_IA_ENDPOINT')) {
    define('SGL_IA_ENDPOINT', 'https://api.openai.com/v1/responses');
}

function sgl_ia_disponivel(): bool
{
    return SGL_IA_API_KEY !== '' && SGL_IA_MODEL !== '' && function_exists('curl_init');
}

function sgl_ia_chamar_openai(string $promptSistema, string $promptUsuario): array
{
    if (!sgl_ia_disponivel()) {
        return [
            'ok' => false,
            'modo' => 'rascunho',
            'erro' => 'IA externa não configurada. Configure SGL_OPENAI_API_KEY e SGL_OPENAI_MODEL para ativar respostas automáticas.',
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
        'temperature' => 0.2,
    ];

    $ch = curl_init(SGL_IA_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SGL_IA_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http < 200 || $http >= 300) {
        return [
            'ok' => false,
            'modo' => 'api',
            'erro' => $err ?: ('Falha na API. HTTP ' . $http . '. ' . mb_substr((string)$raw, 0, 500)),
            'texto' => '',
        ];
    }

    $json = json_decode((string)$raw, true);
    $texto = '';
    if (isset($json['output_text']) && is_string($json['output_text'])) {
        $texto = $json['output_text'];
    } elseif (isset($json['output']) && is_array($json['output'])) {
        foreach ($json['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                        $texto .= $content['text'] . "\n";
                    }
                }
            }
        }
        $texto = trim($texto);
    }

    return [
        'ok' => $texto !== '',
        'modo' => 'api',
        'erro' => $texto === '' ? 'Resposta recebida, mas sem texto interpretável.' : '',
        'texto' => $texto,
    ];
}
